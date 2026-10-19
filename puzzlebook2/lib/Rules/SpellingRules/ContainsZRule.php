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
}
