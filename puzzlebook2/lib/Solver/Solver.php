<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Reservation;

/**
 * The one assign-and-backtrack loop every rule's demand flows through. No
 * rule is special-cased: a slot is Exact or Shaped, and both paths end the
 * same way -- a Reservation committed on the board with its
 * constraintsFor() validated and registered.
 *
 * The search is SYSTEMATIC chronological backtracking, which is what makes
 * its two negative answers honest:
 *
 *  - A slot's candidate sequence is deterministic: generators are stateless
 *    and seeded, seeds are fixed per slot (never "bumped"), and trail undo
 *    restores the exact board state of the node being retried. So each
 *    Decision keeps a resume index, and backtracking into it continues at
 *    the NEXT untried candidate instead of reshuffling -- no candidate is
 *    ever retried at the same node, and no candidate is ever skipped.
 *  - A conflict always backtracks into the most recent decision (undo top,
 *    try its next candidate; when a decision's candidates are exhausted,
 *    pop it, return its slot to the pending queue, and continue one
 *    deeper). When the stack empties, every alternative has been tried all
 *    the way back to the empty board: Infeasible is a proof, not a guess.
 *    (Soundness of the proof also relies on candidate availability being
 *    MONOTONE: claims and constraints only ever shrink what's possible, so
 *    a slot with no candidates at some node has none at any deeper node.)
 *
 * Conflict-directed backjumping was deliberately dropped: it is only sound
 * with a COMPLETE record of why each candidate failed, and failures caused
 * by consumed words or claimed cells are not attributed here. Jumping on a
 * partial conflict set silently skips live parts of the search space --
 * worse than the chronological cost it saves. Conflict::$responsibleLevels
 * survives as diagnostics for explain/Advisor output only.
 *
 * There is also no classic forward-checking phase, by design rather than
 * omission: a free cell has no Domain object until the moment a
 * reservation claims it (which pool it draws from depends on which slot
 * gets there), so there is nothing to propagate into. The equivalent
 * protection is validateAgainstBoard(): a new constraint is checked
 * against every already-assigned cell it watches before it is accepted,
 * so an invariant can never be registered into a board that already
 * violates it -- v1's worst bug class, closed at the only point it can be.
 */
final class Solver
{
    /**
     * Safety budget, not a retry knob: steps scale with slot count, and a
     * chain that exhausts them gets an honest Inconclusive (see
     * SolveOutcome) instead of an unbounded run.
     */
    private const MAX_STEPS_PER_SLOT = 2000;

    /** @param null|\Closure(int $assigned, int $total, int $steps, int $backtracks): void $progress */
    public function __construct(private ?\Closure $progress = null)
    {
    }

    public function solve(Board $board, ReservationQueue $queue): SolveResult
    {
        $maxSteps = max(10000, $queue->count() * self::MAX_STEPS_PER_SLOT);

        /** @var Decision[] $stack */
        $stack = [];
        $steps = 0;
        $backtracks = 0;
        /** @var array<string,int> rule id => dead ends, for the Advisor's forensics */
        $conflictTally = [];

        while (!$board->isComplete()) {
            if ($queue->isEmpty()) {
                return SolveResult::infeasible(
                    'Every slot resolved but the board is not full -- demand cells do not sum to the board size. '
                    . 'FeasibilityAnalyzer::check() reports this before solving; it was not run or was ignored.',
                    $steps,
                    $backtracks,
                    $conflictTally,
                );
            }

            if (++$steps > $maxSteps) {
                return SolveResult::inconclusive($this->budgetMessage($maxSteps), $steps, $backtracks, $conflictTally);
            }
            if ($this->progress !== null && $steps % 200 === 0) {
                ($this->progress)($board->assignedCount(), $board->spec()->size(), $steps, $backtracks);
            }

            $slot = $queue->pop();
            $mark = $board->trail->mark();
            $outcome = $this->attemptFrom($slot, $board, 0);

            if ($outcome instanceof SlotCommit) {
                $stack[] = new Decision($slot, $mark, $outcome->candidateIndex + 1);
                continue;
            }

            // The slot failed outright at this node; it stays pending and
            // the search backtracks until some earlier decision changes.
            $queue->push($slot);
            $conflict = $outcome;
            $conflictTally[$slot->rule->id()] = ($conflictTally[$slot->rule->id()] ?? 0) + 1;
            $recovered = false;

            while ($stack !== []) {
                if (++$steps > $maxSteps) {
                    return SolveResult::inconclusive($this->budgetMessage($maxSteps), $steps, $backtracks, $conflictTally);
                }

                $top = $stack[array_key_last($stack)];
                $board->trail->undoTo($top->mark);
                $backtracks++;

                $retry = $this->attemptFrom($top->slot, $board, $top->resumeIndex);
                if ($retry instanceof SlotCommit) {
                    $top->resumeIndex = $retry->candidateIndex + 1;
                    $recovered = true;
                    break;
                }

                $conflict = $retry;
                $conflictTally[$top->slot->rule->id()] = ($conflictTally[$top->slot->rule->id()] ?? 0) + 1;
                array_pop($stack);
                $queue->push($top->slot);
            }

            if (!$recovered) {
                return SolveResult::infeasible(
                    'Systematic search exhausted every alternative back to the empty board. '
                    . "Final dead end: {$conflict->description}",
                    $steps,
                    $backtracks,
                    $conflictTally,
                );
            }
        }

        return SolveResult::success(new FilledBoard($board->spec(), $board->finalWords()), $steps, $backtracks, $conflictTally);
    }

    private function attemptFrom(ReservationSlot $slot, Board $board, int $startIndex): SlotCommit|Conflict
    {
        return match ($slot->arity()) {
            Arity::Exact => $this->attemptExact($slot, $board, $startIndex),
            Arity::Shaped => $this->attemptShaped($slot, $board, $startIndex),
        };
    }

    /**
     * Shaped: candidates are the generator's scopes, in its seeded order.
     * Within a candidate, each cell's word is drawn from its domain as soon
     * as that cell is reached, and the cell is claimed (word consumed)
     * before the next cell draws -- so two cells in one scope can never be
     * handed the same word. Any failure undoes just this candidate's
     * claims and moves to the next.
     */
    private function attemptShaped(ReservationSlot $slot, Board $board, int $startIndex): SlotCommit|Conflict
    {
        $demand = $slot->demand;
        $touchedLevels = [];
        $sawAnyScope = false;
        $index = -1;

        foreach ($demand->generator->candidates($board, $slot->seed()) as $scope) {
            $index++;
            $sawAnyScope = true;
            if ($index < $startIndex) {
                continue;
            }

            $candidateMark = $board->trail->mark();
            $level = $board->nextDecisionLevel();
            $words = [];
            $failedCell = null;

            foreach ($scope->cellsByRole as $role => $cell) {
                $domain = ($demand->domainFactory)($role, $cell);
                $word = $domain->firstCandidate();
                if ($word === null) {
                    $failedCell = $cell;
                    break;
                }
                $words[$role] = $word;
                $board->claimCell($cell, $word, $domain, $slot->rule->id(), $level);
            }

            if ($failedCell !== null) {
                foreach ($board->constraints->watching($failedCell) as $c) {
                    $touchedLevels[] = $board->constraints->levelOf($c);
                }
                $board->trail->undoTo($candidateMark);
                continue;
            }

            $reservation = new Reservation($scope, $words, $slot->rule, $level);
            if (!$this->registerConstraintsIfConsistent($reservation, $slot, $board, $level, $touchedLevels)) {
                $board->trail->undoTo($candidateMark);
                continue;
            }

            return new SlotCommit($index, $reservation);
        }

        return $this->exhausted($slot, $sawAnyScope, $touchedLevels);
    }

    /**
     * Exact: the candidate space is (word draw) x (scope), enumerated
     * lexicographically under one flat index so resume works the same way
     * as Shaped. The wordChooser is handed the slot's fixed seed and a draw
     * number; it must enumerate distinct choices deterministically and
     * return null when draws are exhausted -- that null, or a draw whose
     * generator offers no scope at all, ends the enumeration.
     */
    private function attemptExact(ReservationSlot $slot, Board $board, int $startIndex): SlotCommit|Conflict
    {
        $demand = $slot->demand;
        $touchedLevels = [];
        $sawAnyScope = false;
        $sawAnyDraw = false;
        $index = -1;

        for ($draw = 0; ; $draw++) {
            $words = ($demand->wordChooser)($board->consumed, $slot->seed(), $draw);
            if ($words === null) {
                break;
            }
            $sawAnyDraw = true;

            if (count($words) !== count(array_unique($words))) {
                throw new \LogicException(
                    "Exact demand '{$demand->label}': wordChooser returned the same word for two roles ("
                    . implode(', ', $words) . ') -- global word uniqueness makes that unplaceable.'
                );
            }

            $scopesThisDraw = 0;
            foreach ($demand->generator->candidates($board, $slot->seed()) as $scope) {
                $scopesThisDraw++;
                $index++;
                $sawAnyScope = true;
                if ($index < $startIndex) {
                    continue;
                }

                $rejected = false;
                foreach ($scope->cellsByRole as $role => $cell) {
                    if (!isset($words[$role])) {
                        throw new \LogicException(
                            "Exact demand '{$demand->label}': wordChooser result is missing role '{$role}'."
                        );
                    }
                    if ($board->consumed->isConsumed($words[$role])) {
                        $rejected = true;
                        break;
                    }
                    foreach ($board->constraints->watching($cell) as $c) {
                        if ($c->violatedBy($cell, $words[$role], $board, $slot->rule->id())) {
                            $touchedLevels[] = $board->constraints->levelOf($c);
                            $rejected = true;
                        }
                    }
                }
                if ($rejected) {
                    continue;
                }

                $candidateMark = $board->trail->mark();
                $level = $board->nextDecisionLevel();
                foreach ($scope->cellsByRole as $role => $cell) {
                    $domain = Domain::pinnedTo($words[$role], $cell, $board->constraints, $board);
                    $board->claimCell($cell, $words[$role], $domain, $slot->rule->id(), $level);
                }

                $reservation = new Reservation($scope, $words, $slot->rule, $level);
                if (!$this->registerConstraintsIfConsistent($reservation, $slot, $board, $level, $touchedLevels)) {
                    $board->trail->undoTo($candidateMark);
                    continue;
                }

                return new SlotCommit($index, $reservation);
            }

            // No geometry exists at all right now; a different word set
            // cannot conjure cells, so further draws are pointless.
            if ($scopesThisDraw === 0) {
                break;
            }
        }

        if (!$sawAnyDraw) {
            return new Conflict($slot, "no valid word choice exists for Exact demand '{$demand->label}'", []);
        }
        return $this->exhausted($slot, $sawAnyScope, $touchedLevels);
    }

    /**
     * The commit gate every candidate passes last: collect the rule's
     * declared constraints and check each against every ALREADY-ASSIGNED
     * cell it watches -- including this reservation's own cells, which were
     * claimed just above (rules exempt their own cells from watchedCells()
     * when the pattern is self-referential; see Rule::constraintsFor()).
     * Only if nothing on the board violates them are they registered. This
     * is what makes "a rule's invariant holds from the moment it exists"
     * true regardless of what order slots resolve in.
     *
     * @param int[] $touchedLevels
     */
    private function registerConstraintsIfConsistent(
        Reservation $reservation,
        ReservationSlot $slot,
        Board $board,
        int $level,
        array &$touchedLevels,
    ): bool {
        $constraints = [];
        foreach ($slot->rule->constraintsFor($reservation) as $c) {
            $constraints[] = $c;
        }

        foreach ($constraints as $c) {
            foreach ($c->watchedCells() as $cell) {
                $existing = $board->wordAt($cell);
                if ($existing !== null && $c->violatedBy($cell, $existing, $board, $board->ownerOf($cell))) {
                    $claimLevel = $board->claimLevelOf($cell);
                    if ($claimLevel !== null) {
                        $touchedLevels[] = $claimLevel;
                    }
                    return false;
                }
            }
        }

        foreach ($constraints as $c) {
            $board->registerConstraint($c, $level);
        }
        return true;
    }

    /** @param int[] $touchedLevels */
    private function exhausted(ReservationSlot $slot, bool $sawAnyScope, array $touchedLevels): Conflict
    {
        $label = $slot->demand->label;
        $reason = $sawAnyScope
            ? "every remaining candidate for '{$label}' (rule {$slot->rule->id()}) was rejected"
            : "no free scope at all exists for '{$label}' (rule {$slot->rule->id()})";
        return new Conflict($slot, $reason, array_values(array_unique($touchedLevels)));
    }

    private function budgetMessage(int $maxSteps): string
    {
        return "Step budget ({$maxSteps}) exhausted before the systematic search finished either way. "
            . 'This is not a verdict: the search space is too large for the budget, which on a chain this '
            . 'size usually means a structural tension between rules is forcing deep backtracking -- '
            . 'Advisor territory, not a knob to turn up.';
    }
}
