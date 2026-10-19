<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Construction\NoLengthAdjacentToOpposingLiteral;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;

/**
 * Cross out every 9-letter word that's adjacent (horizontally, vertically,
 * or diagonally) to a 3-letter word -- both get crossed out. Overlaps are
 * fully allowed and expected: a 3-letter word with three 9-letter neighbours
 * eliminates all four, not just one pair.
 *
 * Unlike the letter-matching pair rules (AnagramPairOnPageRule,
 * SameEndingVerticalPairRule, MatchingNeighbourEndsRule), this one needs no
 * SPECIFIC matching word -- any 9-letter word and any 3-letter word qualify,
 * so both halves are ordinary (non-literal) cells with full dictionary
 * fallback, not a reserved pair that can be orphaned.
 *
 * The real risk runs the OTHER direction: one of THOSE rules' literal
 * pair-halves (chosen for reasons that have nothing to do with length)
 * landing next to this rule's own cell, or next to each other, and
 * accidentally being length 9 and 3. Rather than this rule trying to guard
 * against that itself, it registers NoLengthAdjacentToOpposingLiteral and
 * runs at a LOW constructionPriority() -- before AnagramPairOnPageRule,
 * SameEndingVerticalPairRule, and MatchingNeighbourEndsRule -- so the
 * invariant already exists by the time any of them checks
 * Canvas::wordSatisfiesInvariants() before committing a literal word. This
 * rule's own placement logic doesn't need to check anything at all: nothing
 * literal exists yet when it runs.
 */
final class LongShortNeighbourRule implements RuleInterface, ConstructsCells
{
    private const LONG = 9;
    private const SHORT = 3;

    /**
     * Same reasoning as every other literal-pair rule's MAX_PAIRS_PER_PAGE: keep
     * density proportional to book size rather than fixed, so a bigger book
     * doesn't concentrate proportionally more risk here.
     */
    private const MAX_PAIRS_PER_PAGE = 1.2;

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $snapshot = $keep;
        foreach ($snapshot as $i => $alive) {
            if (!$alive) {
                continue;
            }
            $len = strlen($words[$i]);
            if ($len !== self::LONG && $len !== self::SHORT) {
                continue;
            }
            foreach ($grid->surroundingNeighbours($i) as $j) {
                if (!$snapshot[$j]) {
                    continue;
                }
                $otherLen = strlen($words[$j]);
                if (($len === self::LONG && $otherLen === self::SHORT)
                    || ($len === self::SHORT && $otherLen === self::LONG)) {
                    $keep[$i] = false;
                    $keep[$j] = false;
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $grid = $canvas->grid();
        $partnerOf = [];

        $target = min(
            intdiv($context->budget(), 2),
            (int) round($grid->pages * self::MAX_PAIRS_PER_PAGE)
        );

        if ($target > 0) {
            // No neighbour-safety check needed here at all: this rule's priority
            // guarantees it runs before anything else in the chain has placed a
            // single literal word, so every candidate pair is trivially safe.
            $free = array_flip($canvas->freeCellsShuffled($context->seed()));
            $used = [];
            $candidatePairs = [];
            foreach (array_keys($free) as $cellA) {
                if (isset($used[$cellA])) {
                    continue;
                }
                foreach (Rng::shuffle($grid->surroundingNeighbours($cellA), $context->seed() + $cellA) as $cellB) {
                    if (!isset($free[$cellB]) || isset($used[$cellB])) {
                        continue;
                    }
                    $candidatePairs[] = [$cellA, $cellB];
                    $used[$cellA] = true;
                    $used[$cellB] = true;
                    break;
                }
            }

            if (count($candidatePairs) < $target) {
                throw new ConstructionRetry(
                    "step {$context->stepId()} wanted {$target} adjacent (9-letter, 3-letter) pairs of "
                    . 'free cells, but only ' . count($candidatePairs) . ' are available without risking '
                    . 'an existing reserved pair'
                );
            }

            $prefix = $context->spellingRulesBefore();
            $longPlan = new CellPlan(
                $prefix,
                static fn (string $word): bool => strlen($word) === self::LONG,
                'longshort9-' . $context->stepId(),
                description: "step {$context->stepId()}: {$this->describe()} (9-letter word)",
                fixedLength: self::LONG
            );
            $shortPlan = new CellPlan(
                $prefix,
                static fn (string $word): bool => strlen($word) === self::SHORT,
                'longshort3-' . $context->stepId(),
                description: "step {$context->stepId()}: {$this->describe()} (3-letter word)",
                fixedLength: self::SHORT
            );

            for ($n = 0; $n < $target; $n++) {
                [$cellA, $cellB] = $candidatePairs[$n];
                $canvas->claimDoomed($cellA, $context->stepId(), $longPlan);
                $canvas->claimDoomed($cellB, $context->stepId(), $shortPlan);
                $partnerOf[$cellA] = $cellB;
                $partnerOf[$cellB] = $cellA;
            }
        }

        // Registered regardless of $target: apply() scans the WHOLE grid for this
        // adjacency on every build, so the risk of some OTHER rule's literal word
        // landing next to another one of the opposing length exists even if this
        // rule placed zero deliberate pairs of its own. Checks $canvas live (see
        // the invariant's own docblock), not a snapshot, so it correctly sees
        // every literal ANY rule places for the rest of this attempt -- including
        // ones placed by rules that run AFTER this one. $partnerOf excludes each
        // of this rule's own pairs from being flagged as a violation of themselves.
        $canvas->addInvariant(new NoLengthAdjacentToOpposingLiteral($canvas, $partnerOf));
    }

    public function constructionPriority(): int
    {
        // Low enough to run before AnagramPairOnPageRule/SameEndingVerticalPairRule/
        // MatchingNeighbourEndsRule (45/50/50), same reasoning as
        // CornerContainsLetterRule: this rule's own placements need no safety
        // check at all when nothing literal exists yet, and those three rules'
        // placements can check this rule's invariant before committing.
        return 21;
    }

    public function describe(): string
    {
        return '9-letter words adjacent to 3-letter words (and vice versa)';
    }
}
