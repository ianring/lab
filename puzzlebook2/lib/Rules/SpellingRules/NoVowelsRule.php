<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 3+ letters with no A, E, I, O or U in it (RHYTHM, CRYPT). */
final class NoVowelsRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        if (strlen($word) < 3) {
            return false;
        }
        return WordTags::vowelCount($word) === 0;
    }

    public function describe(): string
    {
        return 'words of 3+ letters with no vowel (A, E, I, O, U) in them';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of three or more letters that contains none of the vowels `A`, `E`, `I`, `O`, `U`. The letter `Y` does not count as a vowel here, so these rare words are spelled with only `Y` and consonants (`RHYTHM`, `CRYPT`, `NYMPH`).';
    }
}
