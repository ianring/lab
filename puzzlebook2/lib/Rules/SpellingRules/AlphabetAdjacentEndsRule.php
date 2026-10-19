<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of 5-6 letters whose first and last letters are alphabet-adjacent (A then B, ...). */
final class AlphabetAdjacentEndsRule extends SpellingRuleBase
{
    private const MIN_LENGTH = 5;
    private const MAX_LENGTH = 6;

    public function failsWord(string $word): bool
    {
        $length = strlen($word);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }
        return WordTags::hasAlphabetAdjacentEnds($word);
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '-' . self::MAX_LENGTH
            . ' letters whose first and last letters are alphabet neighbours (A__B)';
    }
}
