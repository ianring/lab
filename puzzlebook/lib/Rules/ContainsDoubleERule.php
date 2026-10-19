<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word containing a double E. */
final class ContainsDoubleERule extends ContainsSubstringRule
{
    public function __construct()
    {
        parent::__construct('ee');
    }
}
