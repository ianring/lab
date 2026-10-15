<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose letter at $position (1-indexed) is one of
 * $matches -- optionally only when the word is exactly $length letters long.
 *
 * The length restriction is what turns a very greedy rule into a precise
 * one. "Second letter is A" matches a large slice of any dictionary; "SIX
 * LETTER word whose second letter is A" matches a small, calibrated one.
 * Leave $length null for no restriction.
 */
final class LetterAtPositionRule extends SpellingRule
{
    /** @param string[] $matches */
    public function __construct(private int $position, private array $matches, private ?int $length = null)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->length !== null && strlen($word) !== $this->length) {
            return false;
        }
        $letter = WordTags::letterAt($word, $this->position);
        return $letter !== null && in_array($letter, $this->matches, true);
    }

    public function describe(): string
    {
        return "words whose letter {$this->position} is " . strtoupper(implode('/', $this->matches))
            . ($this->length === null ? '' : ", {$this->length}-letter words only");
    }
}
