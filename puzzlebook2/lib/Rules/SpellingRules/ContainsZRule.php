<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing the letter Z. */
final class ContainsZRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('z');
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word containing the letter Z.';
    }
}
