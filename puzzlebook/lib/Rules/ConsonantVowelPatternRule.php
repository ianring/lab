<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose consonant/vowel shape is exactly $pattern --
 * "CVCVCVC" catches MONITOR, GENERIC, PARADOX and MEDICAL.
 *
 * The pattern's own length fixes the word length, so this is always a
 * precise rule rather than a greedy one. Y counts as a consonant.
 *
 * Cheap for the reader despite looking fussy, because it bails out on the
 * first letter that doesn't fit -- most words fail at position 1 or 2.
 */
final class ConsonantVowelPatternRule extends SpellingRule
{
    private string $pattern;

    public function __construct(string $pattern)
    {
        $this->pattern = strtoupper($pattern);
    }

    public function failsWord(string $word): bool
    {
        if (strlen($word) !== strlen($this->pattern)) {
            return false;
        }
        return WordTags::consonantVowelPattern($word) === $this->pattern;
    }

    public function describe(): string
    {
        return "words shaped {$this->pattern} (consonant/vowel)";
    }
}
