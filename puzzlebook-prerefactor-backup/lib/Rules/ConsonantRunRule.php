<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/** Cross out every word with $length or more consonants in a row (Y is never a vowel). */
final class ConsonantRunRule extends SpellingRule
{
    public function __construct(private int $length)
    {
    }

    public function failsWord(string $word): bool
    {
        return WordTags::hasConsonantRun($word, $this->length);
    }
}
