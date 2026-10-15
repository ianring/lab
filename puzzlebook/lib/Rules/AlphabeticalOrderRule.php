<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose letters never go backwards through the
 * alphabet -- "almost", "begin", "chimp" -- optionally only when the word is
 * at least $minLength letters long.
 *
 * Repeated letters count as being in order, since they don't go backwards
 * either: "bee" and "all" are crossed out. That's worth stating in the
 * printed instruction, because it's the one case a reader might reasonably
 * rule either way.
 *
 * $minLength matters more than it looks. Short words are very often in
 * alphabetical order by chance ("any", "bet", "cow", "dry"), so a minimum
 * both calibrates how greedy the rule is and is how a short word that has to
 * survive is let through.
 */
final class AlphabeticalOrderRule extends SpellingRule
{
    public function __construct(private ?int $minLength = null)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->minLength !== null && strlen($word) < $this->minLength) {
            return false;
        }
        return WordTags::isInAlphabeticalOrder($word);
    }

    public function describe(): string
    {
        return 'words with their letters in alphabetical order'
            . ($this->minLength === null ? '' : ", {$this->minLength}+ letters only");
    }
}
