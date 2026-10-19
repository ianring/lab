<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word of exactly $length letters that is spelled using
 * only letters from $allowedLetters (e.g. the eight tiles worth one point
 * in Scrabble: R, S, T, L, N, E, U, G) -- a word of any other length always
 * survives this one, untouched.
 */
/** No card yet -- subclass with a real length/allowedLetters combination to add one. */
abstract class RestrictedAlphabetLengthRule extends SpellingRule
{
    /** @param string[] $allowedLetters */
    protected function __construct(private int $length, private array $allowedLetters)
    {
    }

    public function failsWord(string $word): bool
    {
        return strlen($word) === $this->length && WordTags::usesOnlyLetters($word, $this->allowedLetters);
    }

    public function describe(): string
    {
        return "{$this->length}-letter words spelled only from "
            . strtoupper(implode('', $this->allowedLetters));
    }
}
