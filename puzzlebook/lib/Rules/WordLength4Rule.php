<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word of exactly 4 letters. */
final class WordLength4Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(4);
    }
}
