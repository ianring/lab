<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 4+ letters whose letters never go backwards through the alphabet. */
final class AlphabeticalOrderRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        if (strlen($word) < 4) {
            return false;
        }
        return WordTags::isInAlphabeticalOrder($word);
    }

    public function describe(): string
    {
        return 'words with their letters in alphabetical order, 4+ letters only';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of FOUR OR MORE letters whose letters run in alphabetical order from first to last, never going backwards (ALMOST, BEGIN, CHIMP). A repeated letter does not count as going backwards, so BLESS and ALLOT go too. Words of three letters or fewer are safe.';
    }
}
