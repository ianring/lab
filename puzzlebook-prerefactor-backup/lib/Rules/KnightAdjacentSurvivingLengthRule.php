<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;

/**
 * Cross out every surviving word that is a knight's move (as in chess) from
 * a word of exactly $length letters that is ALSO still surviving.
 *
 * Unlike the other positional rules, a crossed-out word does NOT count as a
 * neighbour here -- the whole point is that two still-standing five-letter
 * words a knight's move apart take each other out simultaneously, so this
 * checks a snapshot of $keep taken before the rule runs, not $keep as it's
 * updated mid-pass (which would make the result depend on iteration order).
 *
 * Positional, so there's no build()-side failsWord() -- lib/ChainBuilder.php
 * builds a web of $length-letter words, each knight-adjacent to at least one
 * other, so that every survivor reaching this rule has something alive next
 * door to cancel it out.
 */
final class KnightAdjacentSurvivingLengthRule implements RuleInterface
{
    public function __construct(private int $length)
    {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $keepBefore = $keep;
        foreach ($words as $i => $word) {
            if (!$keepBefore[$i]) {
                continue;
            }
            foreach ($grid->knightNeighbours($i) as $neighbourIndex) {
                if ($keepBefore[$neighbourIndex] && strlen($words[$neighbourIndex]) === $this->length) {
                    $keep[$i] = false;
                    break;
                }
            }
        }
        return $keep;
    }
}
