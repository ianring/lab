<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 4+ letters whose letters never go forward through the alphabet. */
final class ReverseAlphabeticalOrderRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        if (strlen($word) < 4) {
            return false;
        }
        return WordTags::isInReverseAlphabeticalOrder($word);
    }

    public function describe(): string
    {
        return 'words with their letters in reverse alphabetical order, 4+ letters only';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of FOUR OR MORE letters whose letters run in REVERSE alphabetical order '
            . 'from first to last, never going forwards -- each letter is the same as, or earlier in the '
            . 'alphabet than, the one before it (ZOOM, TROD, SPONGED). A repeated letter does not count as '
            . 'going forwards. Words of three letters or fewer are safe.';
    }
}
