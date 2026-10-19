<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word of four to six letters whose first AND last letter
 * both sit on the top row of a keyboard (Q W E R T Y U I O P).
 *
 * Needing both ends in a ten-letter set still matches a sizeable share of
 * any dictionary -- unrestricted, 2,342 words -- which is what makes this
 * useful for thinning a large book, but too loose a catch for its spot in
 * the chain. The length band narrows it without changing what the rule is
 * actually about. The upper end is also what lets the (currently inactive)
 * revealed word "eliminate" through: nine letters, well above the cap, so
 * unrestricted this would remove it.
 */
final class BothEndsInSetRule extends SpellingRule
{
    private const LETTERS = ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'];
    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 6;

    public function failsWord(string $word): bool
    {
        $length = strlen($word);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }
        return WordTags::bothEndsIn($word, self::LETTERS);
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '-' . self::MAX_LENGTH . ' letters with both ends in '
            . strtoupper(implode('', self::LETTERS));
    }
}
