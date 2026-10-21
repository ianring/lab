<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word spelled using only B C D E H I K O X (capitals mirror-symmetric top to bottom). */
final class UsesOnlyLettersRule extends SpellingRuleBase
{
    private const LETTERS = ['b', 'c', 'd', 'e', 'h', 'i', 'k', 'o', 'x'];

    public function failsWord(string $word): bool
    {
        return WordTags::usesOnlyLetters($word, self::LETTERS);
    }

    public function describe(): string
    {
        return 'words spelled only from ' . strtoupper(implode('', self::LETTERS));
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that looks the same when reflected upside down. (they are spelled using only these letters: B C D E H I K O X)';
    }
}
