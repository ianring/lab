<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 4 letters, for WordLength4Rule to clear. */
final class DecoyLength4Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(4, 10);
    }
}
