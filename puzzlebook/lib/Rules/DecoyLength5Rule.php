<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 5 letters, for WordLength5Rule to clear. */
final class DecoyLength5Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(5, 10);
    }
}
