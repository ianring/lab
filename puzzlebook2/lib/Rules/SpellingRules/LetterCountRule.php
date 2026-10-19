<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word containing three or more of the letter I. */
final class LetterCountRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return substr_count($word, 'i') >= 3;
    }

    public function describe(): string
    {
        return 'words with 3+ of the letter I';
    }
}
