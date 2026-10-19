<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;

/**
 * The book-in-progress that rules write their construction decisions into:
 * which cell each rule is going to eliminate, and what shape that cell's
 * word must be. No actual words yet -- this is purely the plan.
 *
 * Rules interact with the book ONLY through this object. That's what keeps a
 * rule's construction logic inside the rule class: it asks the canvas what's
 * still free, claims what it needs, and says what shape of word belongs
 * there. It never touches the builder's internals, and the builder never
 * needs to know what the rule was doing.
 *
 * Two kinds of claim:
 *
 *   claimDoomed()   -- a cell eliminated BEFORE the reveal is read. Ordinary
 *                      filler. The reveal never counts it.
 *   claimSurvivor() -- a cell still standing WHEN the reveal is read, which
 *                      the reveal therefore counts. Scarce and load-bearing,
 *                      so claiming one is checked against the cells the
 *                      builder has forbidden (the answer's neighbourhood).
 */
final class Canvas
{
    private Grid $grid;
    private int $size;

    /** @var array<int,CellPlan|null> */
    private array $plans;

    /** @var array<int,int|null> cell => id of the step that eliminates it (null = nothing does) */
    private array $killedBy;

    /** @var array<int,bool> cells still standing when the reveal is read */
    private array $survivors = [];

    /** @var array<int,bool> cells that must never become survivors */
    private array $survivorForbidden = [];

    /** @var array<int,bool> survivor cells whose word is incidental and may be swapped out */
    private array $replaceable = [];

    /** @var FillInvariant[] conditions spanning many cells, enforced while words are chosen */
    private array $invariants = [];

    /** @var array<string,bool> specific words already spoken for during CONSTRUCTION */
    private array $reservedWords = [];

    /** @var array<int,bool> cells exempt from every OTHER rule's fill-time invariant */
    private array $invariantExempt = [];

    public function __construct(Grid $grid)
    {
        $this->grid = $grid;
        $this->size = $grid->size();
        $this->plans = array_fill(0, $this->size, null);
        $this->killedBy = array_fill(0, $this->size, null);
    }

    public function grid(): Grid
    {
        return $this->grid;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * A point-in-time copy of every mutable thing a rule's construct() can
     * change, for ChainBuilder's local-retry loop: snapshot before a
     * construction step, restore to it if that step (or anything after it,
     * up to and including filling and verifying) fails in a way blamed on
     * that step.
     *
     * Every property except $invariants is a plain value array -- PHP
     * copies those on write, not on read, so capturing them here is cheap
     * and restoring them later can't be corrupted by anything that mutated
     * the ORIGINAL array in between. $invariants holds objects
     * (UniquePageSignature and friends), which PHP arrays hold by
     * reference, not by value -- so each one is cloned. Their only mutable
     * state is itself plain arrays ($seen, $byPage), which PHP's default
     * shallow clone copies correctly; nothing here needs a custom
     * __clone(). Without this, restoring $invariants to an "earlier" array
     * would still hand back objects carrying bookkeeping from the fill
     * attempt that just got abandoned -- recording matches against words
     * that are about to be un-placed.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'plans' => $this->plans,
            'killedBy' => $this->killedBy,
            'survivors' => $this->survivors,
            'survivorForbidden' => $this->survivorForbidden,
            'replaceable' => $this->replaceable,
            'invariants' => array_map(static fn (FillInvariant $i): FillInvariant => clone $i, $this->invariants),
            'reservedWords' => $this->reservedWords,
            'invariantExempt' => $this->invariantExempt,
        ];
    }

    /** @param array<string,mixed> $snapshot from a prior call to snapshot() */
    public function restore(array $snapshot): void
    {
        $this->plans = $snapshot['plans'];
        $this->killedBy = $snapshot['killedBy'];
        $this->survivors = $snapshot['survivors'];
        $this->survivorForbidden = $snapshot['survivorForbidden'];
        $this->replaceable = $snapshot['replaceable'];
        $this->invariants = $snapshot['invariants'];
        $this->reservedWords = $snapshot['reservedWords'];
        $this->invariantExempt = $snapshot['invariantExempt'];
    }

    /** True if nothing has claimed this cell yet. */
    public function isFree(int $cell): bool
    {
        return $this->plans[$cell] === null;
    }

    /** @return int[] every unclaimed cell, in reading order. */
    public function freeCells(): array
    {
        $free = [];
        for ($i = 0; $i < $this->size; $i++) {
            if ($this->plans[$i] === null) {
                $free[] = $i;
            }
        }
        return $free;
    }

    /** @return int[] every unclaimed cell, in a deterministic shuffled order. */
    public function freeCellsShuffled(int $seed): array
    {
        return Rng::shuffle($this->freeCells(), $seed);
    }

    /**
     * Side-by-side cell pairs where BOTH halves are still free -- for rules that
     * need two adjacent cells they can control together.
     *
     * Only non-overlapping pairs are offered (columns 0-1, 2-3, 4-5 of a row), so
     * claiming one can never strand the other half of another pair.
     *
     * @return array<array{0:int,1:int}>
     */
    public function freeHorizontalPairs(int $seed): array
    {
        $pairs = [];
        for ($page = 0; $page < $this->grid->pages; $page++) {
            for ($row = 0; $row < $this->grid->rows; $row++) {
                for ($col = 0; $col + 1 < $this->grid->cols; $col += 2) {
                    $left = $this->grid->linearIndex($page, $row, $col);
                    $right = $this->grid->linearIndex($page, $row, $col + 1);
                    if ($this->plans[$left] === null && $this->plans[$right] === null) {
                        $pairs[] = [$left, $right];
                    }
                }
            }
        }
        return Rng::shuffle($pairs, $seed);
    }

    /**
     * Vertically adjacent cell pairs where BOTH halves are still free -- the same
     * idea as freeHorizontalPairs(), one column down instead of one row across.
     * Only non-overlapping pairs are offered (rows 0-1, 2-3, ... of a column).
     *
     * @return array<array{0:int,1:int}>
     */
    public function freeVerticalPairs(int $seed): array
    {
        $pairs = [];
        for ($page = 0; $page < $this->grid->pages; $page++) {
            for ($col = 0; $col < $this->grid->cols; $col++) {
                for ($row = 0; $row + 1 < $this->grid->rows; $row += 2) {
                    $top = $this->grid->linearIndex($page, $row, $col);
                    $bottom = $this->grid->linearIndex($page, $row + 1, $col);
                    if ($this->plans[$top] === null && $this->plans[$bottom] === null) {
                        $pairs[] = [$top, $bottom];
                    }
                }
            }
        }
        return Rng::shuffle($pairs, $seed);
    }

    // ------------------------------------------------------------ survivor bookkeeping

    /** Mark a cell as one that must never be left standing when the reveal is read. */
    public function forbidSurvivor(int $cell): void
    {
        $this->survivorForbidden[$cell] = true;
    }

    public function mayBeSurvivor(int $cell): bool
    {
        return !isset($this->survivorForbidden[$cell]);
    }

    public function survivorCount(): int
    {
        return count($this->survivors);
    }

    /** @return int[] the cells still standing when the reveal is read, in reading order. */
    public function survivors(): array
    {
        $cells = array_keys($this->survivors);
        sort($cells);
        return $cells;
    }

    public function isSurvivor(int $cell): bool
    {
        return isset($this->survivors[$cell]);
    }

    // ------------------------------------------------------------------- claiming

    /** Claim a cell that is eliminated before the reveal is read. */
    public function claimDoomed(int $cell, int $killedByStepId, CellPlan $plan): void
    {
        $this->assertFree($cell, $killedByStepId);
        $this->plans[$cell] = $plan;
        $this->killedBy[$cell] = $killedByStepId;
    }

    /**
     * Claim a cell that is still standing when the reveal is read.
     *
     * $killedByStepId is the step that eventually removes it, or null for the one
     * word that is never removed at all.
     *
     * $replaceable says whether this cell's actual word is incidental. A cell the
     * rule needs a specific shape in -- a launch pad the rule fires from -- is NOT
     * replaceable, and swapping its word would break the rule. A cell that merely
     * happens to be standing here IS replaceable, and the reveal is free to put one
     * of its words there. Rules declare this; nothing else has to understand why.
     */
    public function claimSurvivor(int $cell, ?int $killedByStepId, CellPlan $plan, bool $replaceable = false): void
    {
        $this->assertFree($cell, $killedByStepId);
        if (!$this->mayBeSurvivor($cell)) {
            throw new ConstructionRetry(
                "cell {$cell} can't be left standing -- it's inside a zone the build reserved"
            );
        }
        $this->plans[$cell] = $plan;
        $this->killedBy[$cell] = $killedByStepId;
        $this->survivors[$cell] = true;
        if ($replaceable) {
            $this->replaceable[$cell] = true;
        }
    }

    /** True if this survivor's word is incidental and the reveal may swap it out. */
    public function isReplaceable(int $cell): bool
    {
        return isset($this->replaceable[$cell]);
    }

    // --------------------------------------------------------- construction-time words

    /**
     * Claim a SPECIFIC word during construction, before any filler is chosen --
     * for a rule that picks genuine word PAIRS up front (two anagrams, a word
     * hiding inside another) rather than a shape to be satisfied later. Without
     * this, two different pair rules scanning the dictionary independently in the
     * same attempt could both choose the same word for their own pair, and
     * whichever one filled its cell second would find the word already placed
     * somewhere else in the book.
     *
     * Throws ConstructionRetry rather than failing outright: a genuine collision
     * between two rules' picks is rare and specific to this attempt's random
     * choices, not a fact about the dictionary -- a different seed picks
     * different words.
     */
    public function reserveWord(string $word): void
    {
        if (isset($this->reservedWords[$word])) {
            throw new ConstructionRetry(
                "'{$word}' was already claimed by another rule's pair earlier in this attempt"
            );
        }
        $this->reservedWords[$word] = true;
    }

    public function isWordReserved(string $word): bool
    {
        return isset($this->reservedWords[$word]);
    }

    /**
     * Every word claimed during construction -- handed to Dictionary::reserveAll()
     * before any ordinary filler is chosen, alongside the answer and the revealed
     * words, so nothing else in the book can pick the same word twice.
     *
     * @return string[]
     */
    public function reservedWords(): array
    {
        return array_keys($this->reservedWords);
    }

    /**
     * Marks a cell whose word was chosen deliberately, during construction, by a
     * rule that validated it for ITS OWN purpose already (see reserveWord()) --
     * exempt from every OTHER rule's fill-time invariant, not just its own.
     *
     * Why this needs to be separate from a rule's own invariant exemption (the
     * $exempt a PageSignaturePairRule/ContainedWordOnPageRule passes to its OWN
     * UniquePageSignature/NoContainmentOnPage): a predetermined word has no
     * fallback if some invariant rejects it -- unlike ordinary filler, it can't
     * try a different candidate -- so subjecting it to an invariant that belongs
     * to a DIFFERENT rule can only waste an attempt, never usefully validate
     * anything. Sometimes this is even unfixable by retrying at all: a reversal
     * pair is unavoidably also an anagram pair (reversing a word can't change
     * which letters it contains), so one rule's own exemption list was never
     * going to be enough on its own. The answer's cell deliberately does NOT get
     * this treatment: its cell is randomised every attempt, so a collision there
     * really is attempt-dependent, worth retrying rather than blanket-exempting.
     */
    public function exemptFromInvariants(int $cell): void
    {
        $this->invariantExempt[$cell] = true;
    }

    public function isExemptFromInvariants(int $cell): bool
    {
        return isset($this->invariantExempt[$cell]);
    }

    /**
     * Swap in a different plan for a cell that's already claimed, keeping its
     * survivor status and which step kills it. Used to drop the revealed words into
     * the survivor slots the reveal will read.
     */
    public function rewritePlan(int $cell, CellPlan $plan): void
    {
        if ($this->plans[$cell] === null) {
            throw new \LogicException("Cell {$cell} has no plan to rewrite.");
        }
        $this->plans[$cell] = $plan;
    }

    public function planFor(int $cell): ?CellPlan
    {
        return $this->plans[$cell];
    }

    /**
     * True if $cell has a king's-move neighbour, other than $excludeCell,
     * ALREADY COMMITTED to a word of exactly $length letters -- either an
     * existing literal of that length, or an ordinary cell whose plan fixes
     * its length (CellPlan::fixedLength()) to it, like LongShortNeighbourRule's
     * own cells. Checking fixedLength() too matters: a cell committed to
     * "must be exactly 3 letters" is just as rigid as a literal for this
     * purpose, even though its actual word isn't chosen until fillWords() --
     * a 9-letter literal placed next to it later would make EVERY 3-letter
     * candidate fail there, not occasionally but always, since the
     * constraint is positional, not about any specific word's spelling.
     *
     * Checked LIVE at the moment of placement rather than from a one-time
     * snapshot -- which matters because any of this book's literal-placing
     * rules (AnagramPairOnPageRule, SameEndingVerticalPairRule,
     * MatchingNeighbourEndsRule, LongShortNeighbourRule) can place a
     * pair-half before another one ever runs, so a snapshot taken only at
     * one rule's own construct() time can't see what a DIFFERENT rule does
     * either before or after it. Checking live, every time any of them is
     * about to claim a cell for a word of length 9 or 3, closes that gap
     * regardless of which rule runs first: whichever placement happens
     * SECOND is always the one checking, and finds the first one already
     * there -- whether that first one is a literal or just a committed shape.
     */
    public function hasAdjacentLiteralOfLength(int $cell, int $length, ?int $excludeCell = null): bool
    {
        foreach ($this->grid->surroundingNeighbours($cell) as $neighbour) {
            if ($neighbour === $excludeCell) {
                continue;
            }
            $plan = $this->plans[$neighbour];
            if ($plan === null) {
                continue;
            }
            $word = $plan->literalWord();
            $committedLength = $word !== null ? strlen($word) : $plan->fixedLength();
            if ($committedLength === $length) {
                return true;
            }
        }
        return false;
    }

    /**
     * Register a condition spanning many cells, for a rule that fires on coincidences
     * between words rather than on any one word's own letters. See FillInvariant.
     */
    public function addInvariant(FillInvariant $invariant): void
    {
        $this->invariants[] = $invariant;
    }

    /** @return FillInvariant[] */
    public function invariants(): array
    {
        return $this->invariants;
    }

    /**
     * True if $word may be committed as a LITERAL at $cell without breaking any
     * invariant registered SO FAR -- the single, generic check every literal-
     * placing rule (AnagramPairOnPageRule, SameEndingVerticalPairRule,
     * MatchingNeighbourEndsRule, LongShortNeighbourRule, ...) should call before
     * committing a no-fallback word, instead of each one hand-writing its own
     * knowledge of what some OTHER specific rule cares about (corners, lengths,
     * or whatever a future rule invents). A rule that owns a cross-cutting
     * constraint expresses it ONCE, as a FillInvariant on its own construct();
     * everyone else just asks this, without knowing or caring what any
     * invariant is actually about.
     *
     * Only catches constraints registered BEFORE this call -- which is why
     * rules that own one (CornerContainsLetterRule, LongShortNeighbourRule)
     * use a low constructionPriority(), running before the letter-matching
     * pair rules so their invariant already exists by the time those rules
     * place anything. $placed is passed as [] since no invariant currently
     * placed at construction time reads it -- every one so far depends only
     * on the word and its position, not on what else has been filled in.
     */
    public function wordSatisfiesInvariants(string $word, int $cell): bool
    {
        foreach ($this->invariants as $invariant) {
            if (!$invariant->allows($word, $cell, [], $this->grid)) {
                return false;
            }
        }
        return true;
    }

    /**
     * How many cells each step actually ends up eliminating, keyed by step id.
     *
     * Worth having separately from the budgets: a rule whose reach is derived from its
     * own geometry has no budget at all, and a rule that claims cells in groups may not
     * spend its budget exactly. This is what really happened.
     *
     * @return array<int,int> step id => cells claimed
     */
    public function claimsByStep(): array
    {
        $counts = [];
        foreach ($this->killedBy as $stepId) {
            if ($stepId === null) {
                continue;
            }
            $counts[$stepId] = ($counts[$stepId] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Every distinct kind of word this layout asks for, with how many cells want it
     * and which step asked -- grouped by the plans' labels.
     *
     * This is what makes bin/check.php able to report on EVERY rule rather than only
     * the spelling ones: instead of keeping its own idea of what each rule needs, it
     * reads the demands the rules actually declared while laying out their cells. A
     * rule with a word-shape requirement can't be left out of the check, because the
     * requirement is right here in the plan it wrote.
     *
     * @return array<string, array{cells: int, plan: CellPlan, steps: int[]}>
     */
    public function demandsByLabel(): array
    {
        $demands = [];
        foreach ($this->plans as $cell => $plan) {
            if ($plan === null) {
                continue;
            }
            $label = $plan->label();
            if (!isset($demands[$label])) {
                $demands[$label] = ['cells' => 0, 'plan' => $plan, 'steps' => []];
            }
            $demands[$label]['cells']++;
            $step = $this->killedBy[$cell];
            if ($step !== null && !in_array($step, $demands[$label]['steps'], true)) {
                $demands[$label]['steps'][] = $step;
            }
        }
        foreach ($demands as $label => $_) {
            sort($demands[$label]['steps']);
        }
        return $demands;
    }

    public function killedByFor(int $cell): ?int
    {
        return $this->killedBy[$cell];
    }

    private function assertFree(int $cell, ?int $stepId): void
    {
        if ($cell < 0 || $cell >= $this->size) {
            throw new \LogicException("Cell {$cell} is outside the grid.");
        }
        if ($this->plans[$cell] !== null) {
            throw new ConstructionRetry(
                "step " . ($stepId ?? '?') . " tried to claim cell {$cell}, which is already taken"
            );
        }
    }
}
