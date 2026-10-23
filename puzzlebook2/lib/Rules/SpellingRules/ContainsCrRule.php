<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "cr" -- a self-contained card in the substring family. */
final class ContainsCrRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('cr');
    }
}
