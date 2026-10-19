<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 6+ letters using 3 or fewer distinct letters (REFERRER, BANANA). */
final class ThreeDistinctLettersRule extends SpellingRuleBase
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
