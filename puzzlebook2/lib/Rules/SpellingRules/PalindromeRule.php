<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word that reads the same forwards and backwards. */
final class PalindromeRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return WordTags::isPalindrome($word);
    }

    public function describe(): string
    {
        return 'words that read the same backwards';
    }
}
