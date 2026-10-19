<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every word of 8 or more letters whose letters strictly
 * alternate consonant-vowel the whole way through, starting with a
 * consonant -- MACARONI is CVCVCVCV, DEGENERATE is CVCVCVCVCV. The strict
 * alternation forces an even length, so in practice this catches words of
 * 8, 10, 12... letters. Y counts as a consonant, as everywhere else.
 */
final class AlternatingConsonantVowelRule extends SpellingRuleBase
{
    private const MIN_LENGTH = 8;

    public function failsWord(string $word): bool
    {
        $len = strlen($word);
        if ($len < self::MIN_LENGTH || $len % 2 !== 0) {
            return false;
        }
        return WordTags::consonantVowelPattern($word) === str_repeat('CV', intdiv($len, 2));
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '+ letters alternating consonant-vowel throughout (MACARONI)';
    }
}
