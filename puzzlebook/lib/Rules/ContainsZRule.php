<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word containing the letter Z. */
final class ContainsZRule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('z');
    }
}
