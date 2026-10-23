<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 3+ letters built only from capital letters drawn with straight lines (no curves). */
final class StraightLineLettersRule extends SpellingRuleBase
{
    /** The capitals whose simplest printed form is all straight strokes, no curve. */
    private const STRAIGHT = ['a', 'e', 'f', 'h', 'i', 'k', 'l', 'm', 'n', 't', 'v', 'w', 'x', 'y', 'z'];

    public function failsWord(string $word): bool
    {
        if (strlen($word) < 3) {
            return false;
        }
        return WordTags::usesOnlyLetters($word, self::STRAIGHT);
    }

    public function describe(): string
    {
        return 'words of 3+ letters written with only straight-line capitals';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Think of each letter as its simplest printed CAPITAL. Some capitals are drawn with only '
            . 'straight lines and no curve at all: A E F H I K L M N T V W X Y Z. Cross out every word of '
            . 'THREE OR MORE letters built entirely from those straight-line capitals (ANVIL, KITTEN, WHITE). '
            . 'If a word uses even one curved letter -- B C D G J O P Q R S U -- it is safe.';
    }
}
