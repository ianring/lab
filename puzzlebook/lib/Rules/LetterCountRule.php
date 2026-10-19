<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out every word containing three or more of the letter I, anywhere in
 * it -- they don't need to be next to each other.
 *
 * Catches words like INITIAL, IGNITION, INCISIVE and INVITING. It's a
 * small-footprint rule by nature (few words repeat one letter three times in
 * nine), so it's here for variety of activity rather than for thinning the
 * book -- counting occurrences is a different motion for the reader than any
 * of the pattern rules.
 */
final class LetterCountRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return substr_count($word, 'i') >= 3;
    }

    public function describe(): string
    {
        return 'words with 3+ of the letter I';
    }
}
