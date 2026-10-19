<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out every word whose first TWO letters and last TWO letters are all
 * on the top row of a keyboard (Q W E R T Y U I O P) -- four letters have to
 * qualify, not two, and there's no length cap.
 *
 * Narrower sibling of BothEndsInSetRule (single letter at each end, capped
 * length): checking a pair at each end instead of one letter cuts the catch
 * down sharply without a length restriction doing the work instead.
 */
final class BothEndPairsInSetRule extends SpellingRule
{
    private const LETTERS = ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'];

    public function failsWord(string $word): bool
    {
        if (strlen($word) < 2) {
            return false;
        }
        foreach (str_split(substr($word, 0, 2)) as $letter) {
            if (!in_array($letter, self::LETTERS, true)) {
                return false;
            }
        }
        foreach (str_split(substr($word, -2)) as $letter) {
            if (!in_array($letter, self::LETTERS, true)) {
                return false;
            }
        }
        return true;
    }

    public function describe(): string
    {
        return 'words whose first two AND last two letters are all in '
            . strtoupper(implode('', self::LETTERS));
    }
}
