<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word whose first two AND last two letters are all on the keyboard top row (QWERTYUIOP). */
final class BothEndPairsInSetRule extends SpellingRuleBase
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
