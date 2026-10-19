<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose consonant/vowel shape is exactly $pattern --
 * "CVCVCV" catches COCOCO, DELICE, BALAMO.
 *
 * The pattern's own length fixes the word length, so this is always a
 * precise rule rather than a greedy one. Y counts as a consonant.
 *
 * Cheap for the reader despite looking fussy, because it bails out on the
 * first letter that doesn't fit -- most words fail at position 1 or 2.
 */
final class ConsonantVowelPatternRule extends SpellingRule
{
    private const PATTERN = 'CVCVCV';

    public function failsWord(string $word): bool
    {
        if (strlen($word) !== strlen(self::PATTERN)) {
            return false;
        }
        return WordTags::consonantVowelPattern($word) === self::PATTERN;
    }

    public function describe(): string
    {
        return 'words shaped ' . self::PATTERN . ' (consonant/vowel)';
    }
}
