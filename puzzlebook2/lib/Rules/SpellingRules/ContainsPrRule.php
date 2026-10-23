<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "pr" -- a self-contained card in the substring family. */
final class ContainsPrRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('pr');
    }
}
