<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 3+ letters spelled only with the musical note letters A-G (CABBAGE, FACADE, DECAF). */
final class MusicalNotesRule extends SpellingRuleBase
{
    /** The seven note letters a musician can play. */
    private const NOTES = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];

    public function failsWord(string $word): bool
    {
        return strlen($word) >= 3 && WordTags::usesOnlyLetters($word, self::NOTES);
    }

    public function describe(): string
    {
        return 'words of 3+ letters spelled only with the note letters A to G';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'The seven musical notes are named with the letters A, B, C, D, E, F and G. Cross out every word '
            . 'of THREE OR MORE letters that can be spelled using only those seven note letters -- a word you '
            . 'could in theory play (CABBAGE, FACADE, BADGE). A word that needs any other letter is safe.';
    }
}
