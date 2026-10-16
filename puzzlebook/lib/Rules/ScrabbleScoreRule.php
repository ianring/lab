<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose Scrabble score is a multiple of $multipleOf
 * (board bonuses ignored -- just the tile values added up).
 *
 * A nice change of activity: it's arithmetic rather than pattern-spotting,
 * and it needs the tile values printed somewhere in the book's front matter
 * or the reader can't apply it at all.
 *
 * Footprint is roughly 1/$multipleOf of whatever reaches it, so a multiple of
 * 4 catches about a quarter of the words -- greedy, and tunable by changing
 * the multiple. $remainder shifts WHICH slice it catches without changing how
 * big the slice is, which is handy when a word you need to survive happens to
 * land on the wrong one.
 */
final class ScrabbleScoreRule extends SpellingRule
{
    public function __construct(private int $multipleOf, private int $remainder = 0)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->multipleOf <= 0) {
            return false;
        }
        return WordTags::scrabbleScore($word) % $this->multipleOf === $this->remainder;
    }

    public function describe(): string
    {
        return $this->remainder === 0
            ? "words whose Scrabble score is a multiple of {$this->multipleOf}"
            : "words whose Scrabble score leaves {$this->remainder} when divided by {$this->multipleOf}";
    }
}
