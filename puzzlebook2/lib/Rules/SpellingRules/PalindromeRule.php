<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word that reads the same forwards and backwards. */
final class PalindromeRule extends SpellingRuleBase
{
    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */
    public const INCOMPATIBLE_WITH = ['AbbaPatternRule'];

    public function failsWord(string $word): bool
    {
        return WordTags::isPalindrome($word);
    }

    public function describe(): string
    {
        return 'words that read the same backwards';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that reads the same forwards and backwards.';
    }
}
