<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word with two or more doubled-letter pairs (BALLOON, COMMITTEE). */
final class StackedDoublesRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::doubledLetterPairs($word) >= 2;
    }

    public function describe(): string
    {
        return 'words with two or more doubled letters';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that has at least TWO doubled letters -- two separate places where the same '
            . 'letter appears twice in a row (BALLOON has LL and OO; COMMITTEE has MM, TT and EE). A word with '
            . 'only one doubled letter, or none, is safe.';
    }
}
