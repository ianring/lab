<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word containing a double E that has at most one other
 * vowel besides the pair itself (COFFEE has just the double E and no other
 * vowel; FOURTEEN has the double E plus O and A, too many).
 *
 * Narrower replacement for plain "contains a double E" (ContainsDoubleERule),
 * which matched every double-E word regardless of how vowel-dense the rest
 * of it was -- too loose a catch for its spot in the chain.
 */
final class DoubleEFewVowelsRule extends SpellingRule
{
    private const MAX_OTHER_VOWELS = 1;

    public function failsWord(string $word): bool
    {
        if (!WordTags::contains($word, 'ee')) {
            return false;
        }
        return WordTags::vowelCount($word) <= 2 + self::MAX_OTHER_VOWELS;
    }

    /** Every match contains a literal "ee", so it can never satisfy a corner
     *  CornerContainsLetterRule left E-free -- see SpellingRule::avoidsCorners(). */
    public function avoidsCorners(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'words with a double E and at most ' . self::MAX_OTHER_VOWELS . ' other vowel';
    }
}
