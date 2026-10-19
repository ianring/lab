<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word containing $needle anywhere in it -- shared base for a family of cards. */
abstract class ContainsSubstringRule extends SpellingRuleBase
{
    protected function __construct(private string $needle)
    {
    }

    public function failsWord(string $word): bool
    {
        return WordTags::contains($word, $this->needle);
    }

    public function describe(): string
    {
        return "words containing '{$this->needle}'";
    }
}
