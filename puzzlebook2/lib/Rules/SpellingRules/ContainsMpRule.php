<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "mp" -- a self-contained card in the substring family. */
final class ContainsMpRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('mp');
    }
}
