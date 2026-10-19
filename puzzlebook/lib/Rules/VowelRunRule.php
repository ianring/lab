<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word with two or more vowels in a row, as long as the word
 * is no longer than nine letters. The mirror of ConsonantRunRule, and like
 * it, Y never counts as a vowel.
 *
 * One of the greediest rules available: English is full of adjacent vowel
 * pairs (RAIN, HOUSE, PIECE, QUIET), so it thins a large book substantially
 * in one pass while staying trivial to apply by hand -- the reader just
 * looks for two vowels touching.
 *
 * The length cap is the escape hatch for a long word that has to survive.
 */
final class VowelRunRule extends SpellingRule
{
    private const MAX_LENGTH = 6;

    public function failsWord(string $word): bool
    {
        if (strlen($word) > self::MAX_LENGTH) {
            return false;
        }
        return WordTags::hasVowelRun($word, 2);
    }

    public function describe(): string
    {
        return 'words with 2+ vowels in a row, max ' . self::MAX_LENGTH . ' letters';
    }
}
