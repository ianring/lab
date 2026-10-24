<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every four-letter word in the ABBA pattern: first and last letter
 * the same, the two middle letters the same (NOON, PEEP, DEED).
 *
 * Note: a four-letter ABBA word is exactly a four-letter palindrome, so this is
 * a strict subset of PalindromeRule -- the incompatibility tool will mark them
 * so. Its value is being MORE spottable/specific than the general palindrome
 * rule; use it in place of PalindromeRule, not alongside it.
 */
final class AbbaPatternRule extends SpellingRuleBase
{
    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */
    public const INCOMPATIBLE_WITH = ['PalindromeRule'];

    public function failsWord(string $word): bool
    {
        return strlen($word) === 4
            && $word[0] === $word[3]
            && $word[1] === $word[2]
            && $word[0] !== $word[1];
    }

    public function describe(): string
    {
        return 'four-letter words in the ABBA pattern (first = last, two middles equal)';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every FOUR-letter word built on the pattern ABBA -- its first and last letters are the '
            . 'same as each other, and its two middle letters are the same as each other (NOON, PEEP, DEED). '
            . 'Such a word reads the same forwards and backwards.';
    }
}
