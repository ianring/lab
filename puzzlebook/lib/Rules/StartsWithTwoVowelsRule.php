<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose first two letters are both vowels (OASIS,
 * EASIER, AUTUMN). Y never counts as a vowel, as everywhere else in the book.
 *
 * Replaces VowelRunRule ("two vowels in a row anywhere") in the chain: that
 * rule's catch is a strict superset of DoubleEFewVowelsRule's (any word with
 * a double E has two adjacent vowels by definition), the same supersedes-an-
 * earlier-rule problem that got the old consonant_vowel_pattern/reversal
 * rules pulled elsewhere in this project. Checking only the START of the
 * word sidesteps the overlap -- a double-E word almost never ALSO starts
 * with two vowels.
 */
final class StartsWithTwoVowelsRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        if (strlen($word) < 2) {
            return false;
        }
        return WordTags::isVowel($word[0]) && WordTags::isVowel($word[1]);
    }

    public function describe(): string
    {
        return 'words starting with two vowels in a row';
    }
}
