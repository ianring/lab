<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 8+ letters that never repeats a letter (DANGEROUS, LUMBERJACK). */
final class LongIsogramRule extends SpellingRuleBase
{
    private const MIN_LENGTH = 8;

    public function failsWord(string $word): bool
    {
        return strlen($word) >= self::MIN_LENGTH && WordTags::isIsogram($word);
    }

    public function describe(): string
    {
        return 'words of 8+ letters with no repeated letter';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of EIGHT OR MORE letters in which no letter is used more than once -- every '
            . 'single letter different from all the others (DANGEROUS, LUMBERJACK, BACKGROUND). Shorter words are '
            . 'safe, and so is any long word that repeats even one letter.';
    }
}
