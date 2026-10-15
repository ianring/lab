<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/**
 * Cross out every surviving word that is a knight's move (as in chess)
 * from a word on the same page whose first letter is one of $initials.
 *
 * Crossed-out words still count as neighbours here: this reads what is
 * printed in a cell, not whether a highlighter has passed over it. (This
 * convention is stated in the book's front matter.) Positional, so there's
 * no build()-side failsWord() -- lib/ChainBuilder.php places a dedicated
 * anchor word near each survivor instead of picking words in isolation.
 */
final class KnightAdjacentInitialRule implements RuleInterface
{
    /** @param string[] $initials */
    public function __construct(private array $initials)
    {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        foreach ($words as $i => $word) {
            if (!$keep[$i]) {
                continue;
            }
            foreach ($grid->knightNeighbours($i) as $neighbourIndex) {
                if (in_array(WordTags::firstLetter($words[$neighbourIndex]), $this->initials, true)) {
                    $keep[$i] = false;
                    break;
                }
            }
        }
        return $keep;
    }
}
