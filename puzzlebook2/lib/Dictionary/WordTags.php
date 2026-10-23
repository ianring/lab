<?php

declare(strict_types=1);

namespace Puzzlebook2\Dictionary;

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

    /** True if $word contains $runLength or more consecutive vowels (Y never counts). */
    public static function hasVowelRun(string $word, int $runLength): bool
    {
        $run = 0;
        $len = strlen($word);
        for ($i = 0; $i < $len; $i++) {
            if (!self::isVowel($word[$i])) {
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

    /** True if $word contains $needle anywhere in it ("ee", "z", "tion", ...). */
    public static function contains(string $word, string $needle): bool
    {
        return str_contains($word, $needle);
    }

    /**
     * True if $word's letters never go backwards through the alphabet -- "almost",
     * "begin", "chimp". Repeated letters are allowed ("bee" counts), since they don't
     * go backwards either.
     */
    public static function isInAlphabeticalOrder(string $word): bool
    {
        $len = strlen($word);
        for ($i = 1; $i < $len; $i++) {
            if ($word[$i] < $word[$i - 1]) {
                return false;
            }
        }
        return true;
    }

    /** True if BOTH the first and last letter of $word are in $letters. */
    public static function bothEndsIn(string $word, array $letters): bool
    {
        return in_array(self::firstLetter($word), $letters, true)
            && in_array(self::lastLetter($word), $letters, true);
    }

    /** Standard English Scrabble tile values. */
    private const SCRABBLE = [
        'a' => 1, 'b' => 3, 'c' => 3, 'd' => 2, 'e' => 1, 'f' => 4, 'g' => 2, 'h' => 4,
        'i' => 1, 'j' => 8, 'k' => 5, 'l' => 1, 'm' => 3, 'n' => 1, 'o' => 1, 'p' => 3,
        'q' => 10, 'r' => 1, 's' => 1, 't' => 1, 'u' => 1, 'v' => 4, 'w' => 4, 'x' => 8,
        'y' => 4, 'z' => 10,
    ];

    /** Sum of the letters' alphabet positions: A=1, B=2 ... Z=26. */
    public static function letterSum(string $word): int
    {
        $sum = 0;
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $sum += ord($word[$i]) - 96;
        }
        return $sum;
    }

    public static function isPrime(int $n): bool
    {
        if ($n < 2) {
            return false;
        }
        for ($d = 2; $d * $d <= $n; $d++) {
            if ($n % $d === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * The consonant/vowel shape of $word as a string of C and V -- "banana" is
     * "CVCVCV". Y counts as a consonant, as everywhere else here.
     */
    public static function consonantVowelPattern(string $word): string
    {
        $pattern = '';
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $pattern .= self::isVowel($word[$i]) ? 'V' : 'C';
        }
        return $pattern;
    }

    /** The highest number of times any single letter in $letters appears in $word. */
    public static function maxRepeatAmong(string $word, array $letters): int
    {
        $counts = [];
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $letter = $word[$i];
            if (in_array($letter, $letters, true)) {
                $counts[$letter] = ($counts[$letter] ?? 0) + 1;
            }
        }
        return $counts === [] ? 0 : max($counts);
    }

    /** What $word would score in Scrabble, ignoring board bonuses. */
    public static function scrabbleScore(string $word): int
    {
        $score = 0;
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $score += self::SCRABBLE[$word[$i]] ?? 0;
        }
        return $score;
    }

    /** How many of $word's letters are vowels (Y never counts). */
    public static function vowelCount(string $word): int
    {
        $count = 0;
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            if (self::isVowel($word[$i])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * True if $word contains the same CONSONANT letter twice in a row (HAPPY,
     * LETTER, SUMMER). Vowels doubled up (MOON, FREE) don't count here -- a
     * repeated vowel is already a vowel run of 2, VowelRunRule's territory,
     * so this stays a distinct, non-overlapping pattern.
     */
    public static function hasDoubledConsonant(string $word): bool
    {
        for ($i = 0, $len = strlen($word); $i < $len - 1; $i++) {
            if ($word[$i] === $word[$i + 1] && !self::isVowel($word[$i])) {
                return true;
            }
        }
        return false;
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

    /**
     * The mirror of isInAlphabeticalOrder(): true if $word's letters never go
     * FORWARD through the alphabet -- "zoom", "trod", "sponged". Repeated
     * letters are allowed (they don't go forward either).
     */
    public static function isInReverseAlphabeticalOrder(string $word): bool
    {
        $len = strlen($word);
        for ($i = 1; $i < $len; $i++) {
            if ($word[$i] > $word[$i - 1]) {
                return false;
            }
        }
        return true;
    }

    /**
     * True if $word is at least $minLength letters and one of its two
     * alternating tracks -- the letters at every other position -- is entirely
     * one repeated vowel: BANANA (a,a,a on the even track), BIKINI (i,i,i),
     * PAPAYA (a,a,a). The other track is unconstrained. The track must hold at
     * least three letters, so the pattern genuinely carries the word.
     */
    public static function hasAlternatingSameVowel(string $word, int $minLength = 6): bool
    {
        $len = strlen($word);
        if ($len < $minLength) {
            return false;
        }
        foreach ([0, 1] as $parity) {
            $track = [];
            for ($i = $parity; $i < $len; $i += 2) {
                $track[] = $word[$i];
            }
            if (count($track) < 3 || !self::isVowel($track[0])) {
                continue;
            }
            $same = true;
            foreach ($track as $letter) {
                if ($letter !== $track[0]) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if $word has an even length and its first half is an anagram of its
     * second half -- "teammate" (TEAM / MATE), the two halves using exactly the
     * same letters in a different order.
     */
    public static function halvesAreAnagrams(string $word): bool
    {
        $len = strlen($word);
        if ($len === 0 || $len % 2 !== 0) {
            return false;
        }
        $half = intdiv($len, 2);
        $front = str_split(substr($word, 0, $half));
        $back = str_split(substr($word, $half));
        sort($front);
        sort($back);
        return $front === $back;
    }

    /** True if $word contains all five vowels A, E, I, O and U somewhere (Y never counts). */
    public static function hasAllFiveVowels(string $word): bool
    {
        foreach (self::VOWELS as $v) {
            if (!str_contains($word, $v)) {
                return false;
            }
        }
        return true;
    }

    /** True if no letter of $word is used more than once -- every letter distinct. */
    public static function isIsogram(string $word): bool
    {
        return count(array_unique(str_split($word))) === strlen($word);
    }

    /** How many places in $word hold the same letter twice in a row (BALLOON -> 2: LL and OO). */
    public static function doubledLetterPairs(string $word): int
    {
        $pairs = 0;
        for ($i = 0, $len = strlen($word); $i < $len - 1; $i++) {
            if ($word[$i] === $word[$i + 1]) {
                $pairs++;
            }
        }
        return $pairs;
    }

    /** The number words that might hide inside a word, longest first so "ten" never pre-empts "ten"-in-a-longer one. */
    private const NUMBER_WORDS = ['three', 'seven', 'eight', 'four', 'five', 'nine', 'zero', 'one', 'two', 'six', 'ten'];

    /** True if a spelled-out number (ONE..TEN, ZERO) sits inside $word, and $word is not simply that number. */
    public static function hidesNumberWord(string $word): bool
    {
        foreach (self::NUMBER_WORDS as $n) {
            if ($word !== $n && str_contains($word, $n)) {
                return true;
            }
        }
        return false;
    }

    /** True if $word holds $runLength letters that are consecutive in the alphabet AND adjacent in the word (DEF, RST). */
    public static function hasConsecutiveAlphabetRun(string $word, int $runLength): bool
    {
        $len = strlen($word);
        for ($i = 0; $i + $runLength <= $len; $i++) {
            $run = true;
            for ($k = 1; $k < $runLength; $k++) {
                if (ord($word[$i + $k]) !== ord($word[$i]) + $k) {
                    $run = false;
                    break;
                }
            }
            if ($run) {
                return true;
            }
        }
        return false;
    }
}
