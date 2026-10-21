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

    private const SPELLED = [
        3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE', 6 => 'SIX', 7 => 'SEVEN',
        8 => 'EIGHT', 9 => 'NINE', 10 => 'TEN', 11 => 'ELEVEN', 12 => 'TWELVE',
    ];

    /** The printed instruction; the chain's final step gets its closing line appended by BookWriter. */
    public function ruleText(): string
    {
        $spelled = self::SPELLED[$this->length] ?? (string) $this->length;
        return "Cross out every remaining word of exactly {$spelled} letters.";
    }
}
