<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word where vowels make up exactly $numerator/$denominator
 * of the letters. Y never counts as a vowel.
 *
 * Note what the fraction implies: a word can only match if its length is a
 * multiple of $denominator. At one third that means 3, 6 and 9-letter words
 * only -- 4, 5, 7, 8 and 10-letter words are all untouchable however their
 * vowels fall. That's not a flaw, but it does mean the rule's footprint
 * depends as much on the length spread of the word list as on the fraction,
 * so it's worth checking bin/check.php rather than guessing.
 *
 * Worth printing as "exactly a third of the letters are vowels" plus a
 * worked example, since readers will otherwise wonder what to do with a
 * five-letter word.
 */
/** No card yet -- subclass with real numerator/denominator values to add one. */
abstract class VowelFractionRule extends SpellingRule
{
    protected function __construct(private int $numerator, private int $denominator)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->denominator <= 0) {
            return false;
        }
        $length = strlen($word);
        if ($length % $this->denominator !== 0) {
            return false;
        }
        // Cross-multiplied to stay in integers: vowels/length == num/den.
        return WordTags::vowelCount($word) * $this->denominator === $length * $this->numerator;
    }

    public function describe(): string
    {
        return "words that are exactly {$this->numerator}/{$this->denominator} vowels";
    }
}
