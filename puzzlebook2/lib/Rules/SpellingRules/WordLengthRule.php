<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word of exactly $length letters -- shared base for one card per length. */
abstract class WordLengthRule extends SpellingRuleBase
{
    protected function __construct(private int $length)
    {
    }

    public function failsWord(string $word): bool
    {
        return strlen($word) === $this->length;
    }

    public function describe(): string
    {
        return "words of exactly {$this->length} letters";
    }
}
