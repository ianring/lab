<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 4+ letters hiding a spelled-out number inside it (HONEST, OFTEN). */
final class HidesANumberRule extends SpellingRuleBase
{
    private const MIN_LENGTH = 4;

    public function failsWord(string $word): bool
    {
        return strlen($word) >= self::MIN_LENGTH && WordTags::hidesNumberWord($word);
    }

    public function describe(): string
    {
        return 'words of 4+ letters with a number word (ONE..TEN) hidden inside';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of FOUR OR MORE letters that hides a counting number spelled out inside it -- '
            . 'ONE, TWO, THREE and so on up to TEN (also ZERO), its letters sitting together in a row (hONEst, '
            . 'ofTEN, sTONE). The word itself must not simply be that number. A word hiding no number is safe.';
    }
}
