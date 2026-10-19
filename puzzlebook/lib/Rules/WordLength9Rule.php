<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word of exactly 9 letters. */
final class WordLength9Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(9);
    }
}
