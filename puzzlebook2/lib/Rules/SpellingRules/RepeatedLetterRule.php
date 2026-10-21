<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word in which some single consonant appears four or more times. */
final class RepeatedLetterRule extends SpellingRuleBase
{
    private const CONSONANTS = [
        'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm',
        'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z',
    ];
    private const TIMES = 4;

    public function failsWord(string $word): bool
    {
        return WordTags::maxRepeatAmong($word, self::CONSONANTS) >= self::TIMES;
    }

    public function describe(): string
    {
        return 'words where one consonant appears ' . self::TIMES . '+ times';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word in which the SAME consonant appears four or more times (ASSASSIN has four S).';
    }
}
