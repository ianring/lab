<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out every word of exactly $length letters.
 *
 * The book's last step: by the time it runs, the only words of that length
 * still standing are the knight's-move launch pads (which that rule leaves
 * alone by design) and any revealed word that happened to be the same
 * length. Clearing them all leaves the answer, which is any other length.
 *
 * Shared base for a family of cards, one per length -- WordLength3Rule
 * through WordLength10Rule, matching LengthByPageRule's 3-10 span. A book's
 * final step is whichever one matches its chosen answer's length.
 */
abstract class WordLengthRule extends SpellingRule
{
    protected function __construct(private int $length)
    {
    }

    public function failsWord(string $word): bool
    {
        return strlen($word) === $this->length;
    }

    public function fixedLength(): ?int
    {
        return $this->length;
    }

    public function describe(): string
    {
        return "words of exactly {$this->length} letters";
    }
}
