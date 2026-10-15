<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/** Cross out every word whose first and last letters are alphabet-adjacent (A then B, B then C, ...). */
final class AlphabetAdjacentEndsRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return WordTags::hasAlphabetAdjacentEnds($word);
    }
}
