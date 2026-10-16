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
 * Cross out every horizontally adjacent pair of STILL-SURVIVING words, in
 * the same row, where the left word's last letter equals the right word's
 * first letter -- disqualifying both words of the pair.
 *
 * BOTH HALVES MUST STILL BE STANDING. An earlier version read whatever was
 * printed, crossed out or not, which quietly assumed the reader marks up the
 * book with something translucent. Plenty of people will reach for a marker
 * instead, and then an eliminated word is simply gone -- so a rule that
 * needs its letters is unsolvable. No rule in this book requires reading a
 * word that has already been crossed out.
 *
 * Reading from a snapshot matters here, not just for tidiness: the two halves
 * have to count each other as surviving simultaneously. Checking live state
 * mid-pass would let the left word's removal stop the right one from being
 * removed, making the outcome depend on scan order.
 *
 * Positional, so there's no single per-word check to extract: whether a word
 * dies depends entirely on the word beside it. construct() therefore picks
 * genuinely adjacent cell pairs and asks for a left word and then a right
 * word that starts on the letter the left one ended on.
 */
final class MatchingNeighbourEndsRule implements RuleInterface, ConstructsCells
{
    /**
     * Letters a deliberate pair may join on -- i.e. letters that enough English words
     * both end with and begin with for the right half to be findable. Excludes
     * j, k, q, u, v, x, y and z: all of them are scarce as initials, and a pair that
     * joined on one would need a right-hand word from a pool of a few dozen.
     */
    private const USABLE_JOIN_LETTERS = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'w',
    ];


    public function apply(array $words, Grid $grid, array $keep): array
    {
        $keepBefore = $keep;

        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                for ($col = 0; $col < $grid->cols - 1; $col++) {
                    $left = $grid->linearIndex($page, $row, $col);
                    $right = $grid->linearIndex($page, $row, $col + 1);
                    if (!$keepBefore[$left] || !$keepBefore[$right]) {
                        continue;
                    }
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

        // Two shared plans for the whole rule, not one per cell -- see CellPlan.
        //
        // Left half: its LAST letter becomes the right half's required FIRST letter, so
        // it has to be a letter plenty of words begin with. Left unconstrained, a left
        // word ending in q, x or z would demand a right word starting with it, and
        // there are only a handful of those in the language. At prototype size that's a
        // rare annoyance; with thousands of pairs it's a guaranteed failure.
        $leftPlan = new CellPlan(
            $survivesBefore,
            static fn (string $word): bool => in_array(WordTags::lastLetter($word), self::USABLE_JOIN_LETTERS, true),
            'pairleft'
        );

        // Right half: resolved at fill time, once the left half actually has a word --
        // the one place a cell's requirement depends on its neighbour rather than on
        // anything decided up front. It finds that neighbour from its own cell index,
        // which is what lets every pair share this one plan. It also marks the letter
        // collision as wanted, so the builder doesn't try to avoid it.
        $rightPlan = CellPlan::matchingRightHalf($survivesBefore, 'pairright');

        for ($n = 0; $n < $pairsWanted; $n++) {
            [$left, $right] = $candidates[$n];
            $canvas->claimDoomed($left, $context->stepId(), $leftPlan);
            $canvas->claimDoomed($right, $context->stepId(), $rightPlan);
        }
    }

    public function constructionPriority(): int
    {
        // Needs two specific cells side by side, so it chooses before the
        // take-anything spelling rules but after whatever defines the survivors.
        return 50;
    }

    public function describe(): string
    {
        return "side-by-side SURVIVING pairs where the left word ends on the right word's first letter";
    }
}
