<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word with four or more consonants in a row (Y is never a
 * vowel).
 *
 * Four, not three: the (currently inactive) reveal spells out the literal
 * word "knight", whose "ght" is a run of exactly three.
 */
final class ConsonantRunRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasConsonantRun($word, 4);
    }

    public function describe(): string
    {
        return 'words with 4+ consonants in a row';
    }
}
