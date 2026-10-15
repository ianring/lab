<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/** Cross out every word whose letter at $position (1-indexed) is one of $matches. */
final class LetterAtPositionRule extends SpellingRule
{
    /** @param string[] $matches */
    public function __construct(private int $position, private array $matches)
    {
    }

    public function failsWord(string $word): bool
    {
        $letter = WordTags::letterAt($word, $this->position);
        return $letter !== null && in_array($letter, $this->matches, true);
    }
}
