<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word with the same consonant letter twice in a row
 * (HAPPY, LETTER, SUMMER, PUZZLE).
 *
 * Distinct from RepeatedLetterRule (which needs the SAME consonant to
 * appear four or more times anywhere in the word, not necessarily
 * adjacent) and from VowelRunRule (adjacent VOWELS, not consonants) --
 * doubled consonants are one of English spelling's most common patterns,
 * giving this rule real room to absorb cells without competing much with
 * either.
 */
final class DoubledConsonantRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasDoubledConsonant($word);
    }

    public function describe(): string
    {
        return 'words with the same consonant twice in a row';
    }
}
