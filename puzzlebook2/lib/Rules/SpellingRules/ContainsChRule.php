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
}
