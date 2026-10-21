<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word that both starts and ends with a vowel (IDEA, AORTA, AGENDA). */
final class VowelBothEndsRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::isVowel(WordTags::firstLetter($word)) && WordTags::isVowel(WordTags::lastLetter($word));
    }

    public function describe(): string
    {
        return 'words that start AND end with a vowel';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that both starts and ends with a vowel, not necessarily the same one (IDEA, AORTA, AGENDA, UMBRELLA).';
    }
}
