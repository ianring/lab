<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word that contains all five vowels A, E, I, O and U (EDUCATION, SEQUOIA). */
final class AllFiveVowelsRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasAllFiveVowels($word);
    }

    public function describe(): string
    {
        return 'words that contain all five vowels A, E, I, O and U';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that contains ALL FIVE vowels -- it must have an A, an E, an I, an O and a U '
            . 'somewhere in it (EDUCATION, EQUATION, DIALOGUE). The letter Y does not count. A word missing even '
            . 'one of the five vowels is safe.';
    }
}
