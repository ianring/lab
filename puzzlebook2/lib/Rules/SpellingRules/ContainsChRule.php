<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing the letter pair CH (CHURCH, ANCHOR, MATCH). */
final class ContainsChRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('ch');
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word containing the letter pair CH, anywhere in it (CHURCH, ANCHOR, MATCH).';
    }
}
