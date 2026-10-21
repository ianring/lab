<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every five-letter word with exactly one vowel (TRUST, CRISP, GHOST). */
final class FourConsonantsOneVowelRule extends SpellingRuleBase
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

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every FIVE-letter word made of four consonants and exactly one vowel (TRUST, CRISP, GHOST).';
    }
}
