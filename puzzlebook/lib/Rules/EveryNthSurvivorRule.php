<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;

/**
 * Cross out every $n-th surviving word, counting every word not yet
 * crossed out from the start of the book. Positional (depends on rank
 * among survivors), not a pure spelling check, so it has no build()-side
 * counterpart the way SpellingRule subclasses do -- lib/ChainBuilder.php
 * places its victim by tracking the same running count itself.
 */
final class EveryNthSurvivorRule implements RuleInterface
{
    public function __construct(private int $n)
    {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $rank = 0;
        foreach ($words as $i => $word) {
            if (!$keep[$i]) {
                continue;
            }
            $rank++;
            if ($rank % $this->n === 0) {
                $keep[$i] = false;
            }
        }
        return $keep;
    }

    public function describe(): string
    {
        return "every {$this->n}th surviving word";
    }
}
