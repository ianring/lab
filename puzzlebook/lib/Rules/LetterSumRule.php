<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose letter-sum passes $test, where each letter is
 * worth its position in the alphabet (A=1 ... Z=26).
 *
 * $test is 'prime' or 'multiple', the latter using $n. Sums for 3-10 letter
 * words land roughly between 15 and 150, where primes are about one number
 * in five, so the prime test is a reasonably greedy rule.
 *
 * READER EFFORT: this is the most expensive kind of rule in the book, and
 * worth placing accordingly. A=1..Z=26 is at least derivable rather than
 * looked up (unlike Scrabble values), but the sums are larger and harder to
 * add, and neither test short-circuits -- you can't know whether a sum is
 * prime until you've finished adding. The work is (words still standing) x
 * (one full addition each), so it belongs late, where few words remain.
 */
final class LetterSumRule extends SpellingRule
{
    public function __construct(private string $test = 'prime', private int $n = 0)
    {
        if (!in_array($test, ['prime', 'multiple'], true)) {
            throw new \InvalidArgumentException("Letter-sum test '{$test}' isn't 'prime' or 'multiple'.");
        }
        if ($test === 'multiple' && $n <= 0) {
            throw new \InvalidArgumentException("A 'multiple' letter-sum test needs a positive n.");
        }
    }

    public function failsWord(string $word): bool
    {
        $sum = WordTags::letterSum($word);
        return $this->test === 'prime'
            ? WordTags::isPrime($sum)
            : $sum % $this->n === 0;
    }

    public function describe(): string
    {
        return $this->test === 'prime'
            ? 'words whose A=1..Z=26 letter sum is prime'
            : "words whose A=1..Z=26 letter sum is a multiple of {$this->n}";
    }
}
