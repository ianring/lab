<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word ending in -ING (RUNNING, MORNING, STRING). */
final class EndsWithIngRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return strlen($word) > 3 && str_ends_with($word, 'ing');
    }

    public function describe(): string
    {
        return 'words ending in -ING';
    }
}
