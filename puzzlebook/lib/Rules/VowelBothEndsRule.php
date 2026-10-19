<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word that both starts and ends with a vowel -- not
 * necessarily the same one (IDEA, AORTA, AGENDA, UMBRELLA).
 *
 * Distinct from StartsWithTwoVowelsRule (which checks the first TWO
 * letters, nothing about the last) and from AlphabetAdjacentEndsRule
 * (which cares about alphabet adjacency between the ends, not vowel-ness).
 */
final class VowelBothEndsRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return WordTags::isVowel(WordTags::firstLetter($word)) && WordTags::isVowel(WordTags::lastLetter($word));
    }

    public function describe(): string
    {
        return 'words that start AND end with a vowel';
    }
}
