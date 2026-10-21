<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 6 letters or fewer with two vowels in a row (RAIN, HOUSE, QUIET). */
final class VowelRunRule extends SpellingRuleBase
{
    private const MAX_LENGTH = 6;

    public function failsWord(string $word): bool
    {
        if (strlen($word) > self::MAX_LENGTH) {
            return false;
        }
        return WordTags::hasVowelRun($word, 2);
    }

    public function describe(): string
    {
        return 'words with 2+ vowels in a row, max ' . self::MAX_LENGTH . ' letters';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of SIX LETTERS OR FEWER with two vowels side by side (Y does not count as a vowel), as in RAIN, HOUSE or QUIET.';
    }
}
