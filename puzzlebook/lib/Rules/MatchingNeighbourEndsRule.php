<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/**
 * Cross out every horizontally adjacent pair of cells, in the same row,
 * where the left word's last letter equals the right word's first letter --
 * disqualifying BOTH words in the pair, not just one. A crossed-out word
 * still counts as a neighbour here: this reads what is printed, not what is
 * still highlighted.
 *
 * Positional, so there's no single per-word check to extract: whether a word
 * dies depends entirely on the word beside it. construct() therefore picks
 * genuinely adjacent cell pairs and asks for a left word and then a right
 * word that starts on the letter the left one ended on.
 */
final class MatchingNeighbourEndsRule implements RuleInterface, ConstructsCells
{
    public function apply(array $words, Grid $grid, array $keep): array
    {
        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                for ($col = 0; $col < $grid->cols - 1; $col++) {
                    $left = $grid->linearIndex($page, $row, $col);
                    $right = $grid->linearIndex($page, $row, $col + 1);
                    if (WordTags::lastLetter($words[$left]) === WordTags::firstLetter($words[$right])) {
                        $keep[$left] = false;
                        $keep[$right] = false;
                    }
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        // This rule always takes two cells at a time, so an odd budget can't be met
        // exactly; the builder accounts for that when it works out the pacing.
        $pairsWanted = intdiv($context->budget(), 2);
        if ($pairsWanted <= 0) {
            return;
        }

        $candidates = $canvas->freeHorizontalPairs($context->seed());
        if (count($candidates) < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} needs {$pairsWanted} side-by-side pairs of free cells, but only "
                . count($candidates) . ' are available'
            );
        }

        $survivesBefore = $context->spellingRulesBefore();

        for ($n = 0; $n < $pairsWanted; $n++) {
            [$left, $right] = $candidates[$n];

            // The left half is unconstrained apart from surviving the earlier rules;
            // whatever letter it ends on becomes the requirement for the right half.
            $canvas->claimDoomed(
                $left,
                $context->stepId(),
                new CellPlan($survivesBefore, static fn (): bool => true, 'pairleft')
            );

            // The right half is resolved at fill time, once the left half actually has
            // a word -- the one place a cell's requirement depends on its neighbour's
            // word rather than on anything decided up front. matchingRightHalf() also
            // marks the collision as wanted, so the builder doesn't try to avoid it.
            $canvas->claimDoomed(
                $right,
                $context->stepId(),
                CellPlan::matchingRightHalf($survivesBefore, 'pairright', $left)
            );
        }
    }

    public function constructionPriority(): int
    {
        // Needs two specific cells side by side, so it chooses before the
        // take-anything spelling rules but after whatever defines the survivors.
        return 50;
    }
}
