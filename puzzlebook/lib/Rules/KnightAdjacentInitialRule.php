<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/**
 * UNUSED, and it breaks the book's reading rule as written -- see below.
 *
 * Cross out every surviving word that is a knight's move (as in chess)
 * from a word on the same page whose first letter is one of $initials.
 *
 * This reads the neighbour's first letter whether or not the neighbour has
 * been crossed out, which the book no longer permits: a reader using a
 * marker can't read an eliminated word at all, so the rule would be
 * unsolvable for them (see convention 3 in config/README.md). It was written
 * before that constraint was understood, and its whole construction scheme
 * depended on anchors that die early and are read anyway.
 *
 * To revive it, require the anchor to be still standing -- which also means
 * reworking construction, since anchors could then no longer be ordinary
 * filler that dies on schedule. KnightAdjacentSurvivingLengthRule is the
 * version that gets this right, and is what the finale uses.
 */
/** No card yet -- subclass with real initials to add one (if revived, see the docblock above). */
abstract class KnightAdjacentInitialRule implements RuleInterface
{
    /** @param string[] $initials */
    protected function __construct(private array $initials)
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

    public function describe(): string
    {
        return "words a knight's move from a word starting with "
            . strtoupper(implode('/', $this->initials));
    }
}
