<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word of 5 to 6 letters whose first and last letters are
 * alphabet-adjacent (A then B, B then C, ...).
 *
 * Unrestricted, this matched 1,064 words -- too loose a catch for its spot
 * in the chain. The length band narrows it without changing what the rule
 * is actually about; narrowed again from 5-8 to 5-6 for the same reason.
 */
final class AlphabetAdjacentEndsRule extends SpellingRule
{
    private const MIN_LENGTH = 5;
    private const MAX_LENGTH = 6;

    public function failsWord(string $word): bool
    {
        $length = strlen($word);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }
        return WordTags::hasAlphabetAdjacentEnds($word);
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '-' . self::MAX_LENGTH
            . ' letters whose first and last letters are alphabet neighbours (A__B)';
    }
}
