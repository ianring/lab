<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word of exactly $length letters that also ends in $ending.
 * A word of any other length, or ending in any other letter, survives.
 *
 * This is the book's last step: by the time it runs, the only words of that
 * shape still standing are the "key" words the knight's-move rule launched
 * from, so clearing them leaves the answer alone.
 */
/** No card yet -- subclass with a real length/ending combination to add one. */
abstract class LengthAndEndingRule extends SpellingRule
{
    protected function __construct(private int $length, private string $ending)
    {
    }

    public function failsWord(string $word): bool
    {
        return strlen($word) === $this->length && WordTags::lastLetter($word) === $this->ending;
    }

    public function describe(): string
    {
        return "{$this->length}-letter words ending in " . strtoupper($this->ending);
    }
}
