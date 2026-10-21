<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word with the same consonant twice in a row (HAPPY, LETTER, SUMMER). */
final class DoubledConsonantRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasDoubledConsonant($word);
    }

    public function describe(): string
    {
        return 'words with the same consonant twice in a row';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word with the same consonant letter twice in a row (HAPPY, LETTER, SUMMER, PUZZLE).';
    }
}
