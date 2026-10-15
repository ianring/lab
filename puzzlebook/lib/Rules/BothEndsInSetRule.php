<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word whose first AND last letter both come from $letters --
 * optionally only when the word is no longer than $maxLength.
 *
 * The set is given in config, so the same rule covers "both ends on the top
 * row of a keyboard" (QWERTYUIOP), "both ends a vowel", or any other group
 * worth naming in a printed instruction. Nothing here cares what the group
 * means; that's the rule_text's job.
 *
 * Needing both ends in a ten-letter set still matches a sizeable share of
 * any dictionary, which is what makes this useful for thinning a large book.
 * $maxLength reins it in -- and is also how a long word that has to survive
 * (a word the reveal spells out, say) is let through.
 */
final class BothEndsInSetRule extends SpellingRule
{
    /** @param string[] $letters */
    public function __construct(private array $letters, private ?int $maxLength = null)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->maxLength !== null && strlen($word) > $this->maxLength) {
            return false;
        }
        return WordTags::bothEndsIn($word, $this->letters);
    }

    public function describe(): string
    {
        return 'words with both ends in ' . strtoupper(implode('', $this->letters))
            . ($this->maxLength === null ? '' : ", max {$this->maxLength} letters");
    }
}
