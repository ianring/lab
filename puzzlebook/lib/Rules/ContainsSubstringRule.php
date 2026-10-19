<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word containing $needle anywhere in it.
 *
 * Shared base for a family of cards -- a single letter is just a
 * one-character substring, so the same check covers "words with a Z"
 * (ContainsZRule) and "words with a double E" (ContainsDoubleERule), with
 * only the printed instruction differing. A new substring is a new concrete
 * subclass, not a new parameter: see ContainsZRule for the shape.
 */
abstract class ContainsSubstringRule extends SpellingRule
{
    protected function __construct(private string $needle)
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
