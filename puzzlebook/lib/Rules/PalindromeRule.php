<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/** Cross out every word that reads the same forwards and backwards. */
final class PalindromeRule extends SpellingRule
{
    public function failsWord(string $word): bool
    {
        return WordTags::isPalindrome($word);
    }
}
