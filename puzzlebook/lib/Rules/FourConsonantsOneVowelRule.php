<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every five-letter word made of four consonants and exactly one
 * vowel (TRUST, CRISP, GHOST). Y never counts as a vowel, as everywhere else
 * in the book.
 */
final class FourConsonantsOneVowelRule extends SpellingRule
{
    private const LENGTH = 5;
    private const VOWELS = 1;

    public function failsWord(string $word): bool
    {
        if (strlen($word) !== self::LENGTH) {
            return false;
        }
        return WordTags::vowelCount($word) === self::VOWELS;
    }

    public function describe(): string
    {
        return 'five-letter words with exactly one vowel (four consonants, one vowel)';
    }
}
