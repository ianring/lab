<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/**
 * Cross out every horizontally adjacent pair of cells, in the same row,
 * where the left word's last letter equals the right word's first letter
 * -- disqualifies BOTH words in the pair, not just one. Like the
 * knight's-move rule, a crossed-out word still counts as a neighbour.
 * Positional (depends on what's printed next door), so lib/ChainBuilder.php
 * builds deliberate matching pairs at the grid level rather than picking
 * words in isolation.
 */
final class MatchingNeighbourEndsRule implements RuleInterface
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
}
