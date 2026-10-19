<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word of exactly 3 letters. */
final class WordLength3Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(3);
    }
}
