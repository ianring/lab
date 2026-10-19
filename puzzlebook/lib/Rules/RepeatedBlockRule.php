<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out every word made of some group of two or more letters repeated
 * whole -- DADA (DA+DA), TOMTOM (TOM+TOM), PERIPERI (PERI+PERI).
 *
 * Single-letter repeats (AAAA) are deliberately NOT caught here -- that's
 * RepeatedLetterRule's job, and a word like ASSASSIN would otherwise trip
 * both rules for the same reason, which is confusing to a reader trying to
 * work out which instruction actually applies. This rule starts its search
 * at a two-letter block on purpose.
 *
 * The check: try every block length from 2 up to half the word's length: if
 * the word divides evenly into blocks of that length AND every block is
 * identical, the word fails. Cheap for the reader despite the definition
 * looking technical -- in practice you're just asking "does the first half
 * (or third, or quarter...) of this word repeat?", which the eye catches
 * quickly for short words and almost never fires for long ones.
 */
final class RepeatedBlockRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        $length = strlen($word);
        for ($blockLength = 2; $blockLength <= intdiv($length, 2); $blockLength++) {
            if ($length % $blockLength !== 0) {
                continue;
            }
            $block = substr($word, 0, $blockLength);
            if (str_repeat($block, intdiv($length, $blockLength)) === $word) {
                return true;
            }
        }
        return false;
    }

    public function describe(): string
    {
        return 'words made of the same group of letters repeated (DADA, TOMTOM, PERIPERI)';
    }
}
