<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every word of exactly 6 letters. */
final class WordLength6Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(6);
    }
}
