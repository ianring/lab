<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 6+ letters where every other letter is the same single vowel (BANANA, BIKINI). */
final class AlternatingVowelRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasAlternatingSameVowel($word, 6);
    }

    public function describe(): string
    {
        return 'words of 6+ letters where every other letter is the same vowel';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of SIX OR MORE letters in which every OTHER letter is the same single '
            . 'vowel -- read the 1st, 3rd, 5th... letters, or the 2nd, 4th, 6th..., and one of those two '
            . 'runs is nothing but one repeated vowel (BANANA, BIKINI, PAPAYA). Shorter words are safe.';
    }
}
