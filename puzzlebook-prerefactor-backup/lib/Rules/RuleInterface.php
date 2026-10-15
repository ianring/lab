<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;

/**
 * One elimination rule, fully self-contained: everything about what it
 * means lives in its own class file, next to its build()-side counterpart
 * where it has one (see SpellingRule).
 *
 * A "keep-mask" is a bool[] the same length as the word grid: true means
 * the word at that position has not been crossed out yet.
 */
interface RuleInterface
{
    /**
     * @param string[] $words
     * @param bool[] $keep
     * @return bool[]
     */
    public function apply(array $words, Grid $grid, array $keep): array;
}
