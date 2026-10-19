<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 10 letters, for WordLength10Rule to clear. */
final class DecoyLength10Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(10, 10);
    }
}
