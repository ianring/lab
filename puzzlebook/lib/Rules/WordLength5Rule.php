<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word of exactly 5 letters. */
final class WordLength5Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(5);
    }
}
