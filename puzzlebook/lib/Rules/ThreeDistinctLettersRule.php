<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out every word of six letters or more spelled using three or fewer
 * DISTINCT letters, however often each repeats (REFERRER re-uses R, E, F;
 * BANANA re-uses B, A, N).
 */
final class ThreeDistinctLettersRule extends SpellingRule
{
    private const MIN_LENGTH = 6;
    private const MAX_DISTINCT = 3;

    public function failsWord(string $word): bool
    {
        if (strlen($word) < self::MIN_LENGTH) {
            return false;
        }
        return count(array_unique(str_split($word))) <= self::MAX_DISTINCT;
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '+ letters using ' . self::MAX_DISTINCT
            . ' or fewer distinct letters (REFERRER, BANANA)';
    }
}
