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
/** No card yet -- subclass with a real test/n/remainder combination to add one. */
abstract class LetterSumRule extends SpellingRule
{
    /**
     * $remainder shifts WHICH slice of words the rule catches without changing how
     * big the slice is. That is what makes this rule usable at all: "a multiple of
     * 9" happens to hit POSITIONED (126) and CHESS (54), both of which the revealed
     * phrase needs, while "leaves 2 when divided by 9" catches just as many words
     * and misses every protected one.
     */
    protected function __construct(private string $test = 'prime', private int $n = 0, private int $remainder = 0)
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
            : $sum % $this->n === $this->remainder;
    }

    public function describe(): string
    {
        if ($this->test === 'prime') {
            return 'words whose A=1..Z=26 letter sum is prime';
        }
        return $this->remainder === 0
            ? "words whose A=1..Z=26 letter sum is a multiple of {$this->n}"
            : "words whose A=1..Z=26 letter sum leaves {$this->remainder} when divided by {$this->n}";
    }
}
