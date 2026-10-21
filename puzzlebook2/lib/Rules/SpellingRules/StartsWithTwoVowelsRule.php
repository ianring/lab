<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word starting with two vowels in a row (OASIS, EASIER, AUTUMN). */
final class StartsWithTwoVowelsRule extends SpellingRuleBase
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

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word starting with two vowels in a row (OASIS, EASIER, AUTUMN).';
    }
}
