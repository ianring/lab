<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word with $length or more vowels in a row -- optionally
 * only when the word is no longer than $maxLength. The mirror of
 * ConsonantRunRule, and like it, Y never counts as a vowel.
 *
 * At $length 2 this is one of the greediest rules available: English is full
 * of adjacent vowel pairs (RAIN, HOUSE, PIECE, QUIET), so it thins a large
 * book substantially in one pass while staying trivial to apply by hand --
 * the reader just looks for two vowels touching.
 *
 * $maxLength is the escape hatch for a long word that has to survive. Since
 * the word pool is 3-9 letters, a cap at 9 costs the rule nothing at all and
 * still lets a longer reserved word (one the reveal spells out) through.
 */
final class VowelRunRule extends SpellingRule
{
    public function __construct(private int $length, private ?int $maxLength = null)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->maxLength !== null && strlen($word) > $this->maxLength) {
            return false;
        }
        return WordTags::hasVowelRun($word, $this->length);
    }

    public function describe(): string
    {
        return "words with {$this->length}+ vowels in a row"
            . ($this->maxLength === null ? '' : ", max {$this->maxLength} letters");
    }
}
