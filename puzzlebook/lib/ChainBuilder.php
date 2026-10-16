<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Rules\ConstructsCells;
use Puzzlebook\Rules\SpellingRule;

/**
 * Builds the book. This class is an ALLOCATOR, not a library of rule
 * knowledge: it decides how many cells each step may claim, asks each rule
 * to claim them, puts the revealed words where the reveal will read them,
 * then fills in real dictionary words satisfying whatever each cell's plan
 * asks for. It then verifies the result by replaying the whole chain.
 *
 * Nothing here knows what a palindrome is, what a knight's move is, or that
 * some rules work in pairs. That all lives in lib/Rules/*, behind
 * ConstructsCells::construct(), so adding a new mechanism means writing one
 * class -- not editing this file.
 *
 * The four phases:
 *
 *   1. Reserve the answer. Its cell is chosen first, and its knight's-move
 *      neighbourhood is marked off-limits for survivors, so no rule can
 *      arrange for the answer to be crossed out.
 *   2. Let the rules claim cells, in construction-priority order -- rules
 *      that DEFINE which words survive go first, then rules needing
 *      particular geometry, then rules that will take any free cell.
 *      Budgets are worked out between those tiers, because the number of
 *      survivors isn't known until the survivor-defining rules have run.
 *   3. Put the revealed words in the slots the reveal will actually read.
 *   4. Fill every cell with a real word matching its plan, in reading order.
 *
 * If an arrangement violates a constraint, that attempt is abandoned and
 * another seed tried (see ConstructionRetry). A build fails only after every
 * attempt fails, and then it names the constraints that blocked it.
 */
final class ChainBuilder
{
    /** How many arrangements to try before declaring the rule set unbuildable. */
    private const MAX_ATTEMPTS = 400;

    private const ANSWER_SEED = 31000;
    private const STEP_SEED = 32000;

    /** Survivors beyond the reveal's strict minimum, so the last slots aren't knife-edge. */
    private const SURVIVOR_SLACK = 8;

    /** @return array{grid: array{pages:int,rows:int,cols:int}, words: string[], attempts: int} */
    public static function build(string $layoutPath, string $chainPath, string $wordlistPath): array
    {
        $layout = require $layoutPath;
        $chain = require $chainPath;
        $grid = new Grid($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);

        $plan = ChainPlan::fromConfig($chain);
        $plan->validateReservedWords($chain['answer']);

        /** @var array<string,int> $retryReasons */
        $retryReasons = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $words = self::attempt($attempt, $grid, $plan, $chain, $layout, $wordlistPath);
                self::verify($words, $grid, $chain);

                return [
                    'grid' => ['pages' => $grid->pages, 'rows' => $grid->rows, 'cols' => $grid->cols],
                    'words' => $words,
                    'attempts' => $attempt + 1,
                ];
            } catch (ConstructionRetry $e) {
                $reason = $e->getMessage();
                $retryReasons[$reason] = ($retryReasons[$reason] ?? 0) + 1;
            }
        }

        arsort($retryReasons);
        $detail = '';
        $shown = 0;
        foreach ($retryReasons as $reason => $n) {
            if ($shown++ >= 6) {
                $detail .= "\n  ... and " . (count($retryReasons) - 6) . ' other reason(s)';
                break;
            }
            $detail .= "\n  {$n}x  {$reason}";
        }
        throw new \RuntimeException(
            'Tried ' . self::MAX_ATTEMPTS . ' arrangements and none satisfied every constraint. What blocked them:'
            . $detail
            . "\n\nRun bin/check.php for a breakdown of the arithmetic and word supply."
        );
    }

    // ------------------------------------------------------------------ one attempt

    /** @return string[] */
    private static function attempt(
        int $attempt,
        Grid $grid,
        ChainPlan $plan,
        array $chain,
        array $layout,
        string $wordlistPath
    ): array {
        $canvas = self::layOutCells($attempt, $grid, $plan, $chain);

        return self::fillWords(
            $canvas,
            new Dictionary($wordlistPath, $layout['filler_excluded_initial'] ?? ''),
            $plan,
            array_merge([$chain['answer']], $plan->revealWords())
        );
    }

    /**
     * Works out which rule eliminates which cell, and what shape each cell's word has
     * to be -- the whole layout, with no words chosen yet.
     *
     * Split out from attempt() so bin/check.php can run it and then inspect the
     * resulting CellPlans to see what each rule is really asking the dictionary for.
     * That keeps the check honest: it reads the demands the rules actually declare
     * rather than a second, hand-maintained list of what we think they need.
     */
    public static function layOutCells(int $attempt, Grid $grid, ChainPlan $plan, array $chain): Canvas
    {
        $canvas = new Canvas($grid);
        $size = $grid->size();
        $answer = $chain['answer'];
        $revealWords = $plan->revealWords();
        $revealN = $plan->revealInterval();
        $targetSurvivors = $revealN * count($revealWords) + self::SURVIVOR_SLACK;

        // --- 1. The answer, and the zone that protects it. ---

        $answerCell = Rng::shuffle(range(0, $size - 1), self::ANSWER_SEED + $attempt)[0];
        foreach ($grid->knightNeighbours($answerCell) as $n) {
            $canvas->forbidSurvivor($n);
        }
        $canvas->claimSurvivor(
            $answerCell,
            null,
            CellPlan::literal($answer, $plan->spellingRuleCount(), 'answer'),
            false
        );

        // --- 2. Let the rules claim their cells, fussiest first. ---

        // Two passes, split by whether a rule HAS a budget rather than by priority.
        //
        // A rule whose reach is 'derived' takes however many cells its own geometry
        // dictates, with no budget to cap it. So every derived rule has to claim before
        // the budgets for the others are worked out -- otherwise the arithmetic shares
        // out cells that a derived rule then takes, and whichever rule happens to run
        // last comes up short by exactly that much. (That was a real bug: the corner
        // rule quietly took ~200 cells the budgets had already promised elsewhere.)
        //
        // Priority still orders the rules WITHIN each pass.
        $constructors = $plan->constructorsByPriority();
        $budgeted = [];

        foreach ($constructors as [$stepId, $rule]) {
            if ($plan->hasBudget($stepId)) {
                $budgeted[] = [$stepId, $rule];
                continue;
            }
            $rule->construct($canvas, $plan->contextFor($stepId, 0, $targetSurvivors, self::STEP_SEED + $attempt));
        }

        // Now the budgets can be exact: this is the number of cells genuinely left to
        // share, after everything that sizes itself has taken its share.
        $budgets = $plan->budgetsFor(count($canvas->freeCells()), $size);

        foreach ($budgeted as [$stepId, $rule]) {
            $rule->construct(
                $canvas,
                $plan->contextFor($stepId, $budgets[$stepId] ?? 0, $targetSurvivors, self::STEP_SEED + $attempt)
            );
        }

        // A rule that claims cells in groups -- pairs, say -- can't always spend its
        // budget to the last cell, so a few may be left with nothing to eliminate them.
        // Hand those to the most flexible rule in the chain (one that takes any cell),
        // rather than failing over a rounding remainder.
        $leftOver = $canvas->freeCells();
        if ($leftOver !== []) {
            $absorber = null;
            foreach ($constructors as [$stepId, $rule]) {
                if ($rule instanceof SpellingRule) {
                    $absorber = [$stepId, $rule];
                }
            }
            if ($absorber === null) {
                throw new ConstructionRetry(
                    count($leftOver) . ' cells were left over and no rule in the chain will take any cell'
                );
            }
            [$stepId, $rule] = $absorber;
            $rule->construct(
                $canvas,
                $plan->contextFor($stepId, count($leftOver), $targetSurvivors, self::STEP_SEED + $attempt)
            );
            if ($canvas->freeCells() !== []) {
                throw new ConstructionRetry(
                    count($canvas->freeCells()) . ' cells were left with no rule to eliminate them'
                );
            }
        }

        // --- 3. The revealed words go where the reveal will read them. ---

        $survivors = $canvas->survivors();
        foreach ($revealWords as $k => $word) {
            $position = ($k + 1) * $revealN;
            if (!isset($survivors[$position - 1])) {
                throw new ConstructionRetry(
                    'the reveal wanted survivor #' . $position . ' but only ' . count($survivors) . ' survive'
                );
            }
            $cell = $survivors[$position - 1];
            if (!$canvas->isReplaceable($cell)) {
                throw new ConstructionRetry(
                    "reveal slot #{$position} landed on a cell whose word the rules need as it is"
                );
            }
            $canvas->rewritePlan($cell, CellPlan::literal($word, $plan->spellingRuleCount(), 'reveal'));
        }

        return $canvas;
    }

    // ------------------------------------------------------------------ word filling

    /**
     * Walks the grid in reading order and asks the dictionary for a word satisfying
     * each cell's plan. Fully generic: the only thing this adds to what a plan asks
     * for is the one constraint no single rule can see -- that a word must not
     * accidentally recreate whatever its horizontal neighbours would make of it.
     *
     * @param string[] $reservedWords
     * @return string[]
     */
    private static function fillWords(
        Canvas $canvas,
        Dictionary $dictionary,
        ChainPlan $plan,
        array $reservedWords
    ): array {
        $grid = $canvas->grid();
        $size = $canvas->size();
        $words = array_fill(0, $size, null);
        $spellingRules = $plan->spellingRules();
        $invariants = $canvas->invariants();

        // Claim the words that are needed at specific cells before any filler is
        // handed out -- they pass every rule, so they'd otherwise be valid filler
        // somewhere earlier and be gone by the time their own cell comes up.
        $dictionary->reserveAll($reservedWords);

        for ($i = 0; $i < $size; $i++) {
            $cellPlan = $canvas->planFor($i);
            if ($cellPlan === null) {
                throw new \LogicException("Cell {$i} was never given a plan.");
            }
            [, , $col] = $grid->coordsOf($i);

            // A word must not start on the letter its left neighbour ended on -- that
            // would make an accidental matching pair and cross both of them out. The
            // one exception is a cell whose plan says it WANTS that match, i.e. the
            // right half of a deliberate pair.
            $leftLast = null;
            if ($col > 0 && $words[$i - 1] !== null && !$cellPlan->allowsLeftMatch()) {
                $leftLast = WordTags::lastLetter($words[$i - 1]);
            }
            // And it must not end on the letter the cell to its right is pinned to --
            // unless that cell wants the match, in which case it's deliberate.
            $rightFirst = null;
            if ($col < $grid->cols - 1) {
                $rightPlan = $canvas->planFor($i + 1);
                if ($rightPlan !== null && !$rightPlan->allowsLeftMatch()) {
                    $rightFirst = $rightPlan->staticFirstLetter();
                }
            }

            // A word decided in advance is placed directly: it was reserved in the
            // dictionary up front, so a lookup would never hand it back.
            $literal = $cellPlan->literalWord();
            if ($literal !== null) {
                if ($leftLast !== null && $literal[0] === $leftLast) {
                    throw new ConstructionRetry(
                        "'{$literal}' has to sit at a specific cell, but would pair with its left neighbour there"
                    );
                }
                if ($rightFirst !== null && WordTags::lastLetter($literal) === $rightFirst) {
                    throw new ConstructionRetry(
                        "'{$literal}' has to sit at a specific cell, but would pair with its right neighbour there"
                    );
                }
                // Predetermined words skip the dictionary, so they'd skip the invariant
                // checks with it -- but a revealed word can collide with a page-wide
                // condition just as easily as any other.
                foreach ($invariants as $invariant) {
                    if (!$invariant->allows($literal, $i, $words, $grid)) {
                        throw new ConstructionRetry(
                            "'{$literal}' has to sit at a specific cell, but breaks "
                            . $invariant->describe() . ' there'
                        );
                    }
                }
                $words[$i] = $literal;
                foreach ($invariants as $invariant) {
                    $invariant->record($literal, $i, $grid);
                }
                continue;
            }

            // $words is captured BY REFERENCE deliberately. By value, PHP would have to
            // separate and copy the whole array every time the next cell is filled --
            // once per cell, so quadratic in book size. The closure only reads it.
            $prefix = $cellPlan->survivesPrefix();
            $predicate = static function (string $candidate) use (
                $cellPlan,
                &$words,
                $spellingRules,
                $prefix,
                $leftLast,
                $rightFirst,
                $i,
                $invariants,
                $grid
            ): bool {
                if ($leftLast !== null && $candidate[0] === $leftLast) {
                    return false;
                }
                if ($rightFirst !== null && WordTags::lastLetter($candidate) === $rightFirst) {
                    return false;
                }
                for ($j = 0; $j < $prefix; $j++) {
                    if ($spellingRules[$j]->failsWord($candidate)) {
                        return false;
                    }
                }
                if (!$cellPlan->shapeAllows($candidate, $words, $i)) {
                    return false;
                }
                // Checked last: these are the costliest tests, and the cheap ones above
                // will already have rejected most candidates.
                foreach ($invariants as $invariant) {
                    if (!$invariant->allows($candidate, $i, $words, $grid)) {
                        return false;
                    }
                }
                return true;
            };

            // Where the plan pins the first letter, draw from that letter's own words
            // instead of scanning the whole pool -- the difference is large at full size.
            $pinned = $cellPlan->firstLetter($words, $i);
            $words[$i] = $pinned === null
                ? $dictionary->fillerMatching($predicate, $cellPlan->label())
                : $dictionary->fillerStartingWith($pinned, $predicate, $cellPlan->label());

            foreach ($invariants as $invariant) {
                $invariant->record($words[$i], $i, $grid);
            }
        }

        return $words;
    }

    // ------------------------------------------------------------------ verification

    /**
     * Re-plays the whole chain with Engine and throws on the first thing that
     * doesn't match. This is the independent check on everything above:
     * construction decides, this confirms.
     *
     * @param string[] $words
     */
    private static function verify(array $words, Grid $grid, array $chain): void
    {
        $keep = array_fill(0, count($words), true);

        foreach ($chain['steps'] as $step) {
            if ($step['kind'] === 'eliminate') {
                $keep = Engine::applyRule($words, $grid, $keep, $step['rule']);

                if (!empty($step['final'])) {
                    $survivors = Engine::survivingIndices($keep);
                    if (count($survivors) !== 1) {
                        $survivorWords = array_map(static fn (int $i): string => $words[$i], $survivors);
                        throw new ConstructionRetry(
                            'the final step left ' . count($survivors) . ' words instead of 1'
                            . ($survivors !== [] && count($survivors) <= 6
                                ? ' (' . implode(', ', $survivorWords) . ')'
                                : '')
                        );
                    }
                    if ($words[$survivors[0]] !== $chain['answer']) {
                        throw new ConstructionRetry(
                            "the last word standing was '{$words[$survivors[0]]}', not '{$chain['answer']}'"
                        );
                    }
                }
                continue;
            }

            $revealed = Engine::computeReveal($words, $grid, $keep, $step['reveal'])['text'];
            if ($revealed !== $step['expected']) {
                throw new ConstructionRetry(
                    "step {$step['id']}'s reveal read '{$revealed}' instead of the expected phrase"
                );
            }
        }
    }
}
