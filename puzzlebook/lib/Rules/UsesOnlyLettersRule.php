<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word spelled using nothing but letters whose capital forms
 * are mirror-symmetric top to bottom: B C D E H I K O X.
 *
 * The RULE_TEXT names the letters explicitly, because the reader can't be
 * expected to work out which ones the author had in mind -- and shouldn't
 * have to. The mirror-symmetry is a description of why this set was chosen,
 * not something the reader needs to reason about.
 *
 * A different letter set (straight-stroke letters, Scrabble one-point tiles,
 * ...) is a different card: subclass with its own hardcoded set rather than
 * reusing this one. See LetterAtPositionRule for the same "narrow a greedy
 * rule down" idea applied to a single position instead of the whole word.
 */
final class UsesOnlyLettersRule extends SpellingRule
{
    private const LETTERS = ['b', 'c', 'd', 'e', 'h', 'i', 'k', 'o', 'x'];

    public function failsWord(string $word): bool
    {
        return WordTags::usesOnlyLetters($word, self::LETTERS);
    }

    public function describe(): string
    {
        return 'words spelled only from ' . strtoupper(implode('', self::LETTERS));
    }
}
