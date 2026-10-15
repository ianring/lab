<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * Pure, structural checks on a single word's spelling. Nothing here looks at
 * what a word means -- only at its letters and their positions -- so none of
 * it requires English fluency to apply by hand.
 */
final class WordTags
{
    private const VOWELS = ['a', 'e', 'i', 'o', 'u'];

    public static function isVowel(string $letter): bool
    {
        return in_array($letter, self::VOWELS, true);
    }

    public static function isPalindrome(string $word): bool
    {
        return $word === strrev($word);
    }

    /** True if $word contains $runLength or more consecutive non-vowel letters. */
    public static function hasConsonantRun(string $word, int $runLength): bool
    {
        $run = 0;
        $len = strlen($word);
        for ($i = 0; $i < $len; $i++) {
            if (self::isVowel($word[$i])) {
                $run = 0;
                continue;
            }
            $run++;
            if ($run >= $runLength) {
                return true;
            }
        }
        return false;
    }

    /** 1-indexed letter lookup; null if $word is shorter than $position. */
    public static function letterAt(string $word, int $position): ?string
    {
        $index = $position - 1;
        if ($index < 0 || $index >= strlen($word)) {
            return null;
        }
        return $word[$index];
    }

    public static function firstLetter(string $word): string
    {
        return $word[0];
    }

    public static function lastLetter(string $word): string
    {
        return $word[strlen($word) - 1];
    }

    /** True if the word's last letter is the one immediately after its first letter in the alphabet (A__B, B__C, ...). */
    public static function hasAlphabetAdjacentEnds(string $word): bool
    {
        if (strlen($word) < 2) {
            return false;
        }
        return (ord(self::lastLetter($word)) - ord(self::firstLetter($word))) === 1;
    }

    /** True if every letter in $word is one of $allowedLetters (each a single lowercase letter). */
    public static function usesOnlyLetters(string $word, array $allowedLetters): bool
    {
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            if (!in_array($word[$i], $allowedLetters, true)) {
                return false;
            }
        }
        return true;
    }
}
