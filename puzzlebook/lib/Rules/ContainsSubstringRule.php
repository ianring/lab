<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word containing $needle anywhere in it.
 *
 * Covers both "words with a double E" (needle "ee") and "words with a Z"
 * (needle "z") -- a single letter is just a one-character substring, so the
 * same rule does both and the printed instruction is what differs.
 */
final class ContainsSubstringRule extends SpellingRule
{
    public function __construct(private string $needle)
    {
    }

    public function failsWord(string $word): bool
    {
        return WordTags::contains($word, $this->needle);
    }

    public function describe(): string
    {
        return "words containing '{$this->needle}'";
    }
}
