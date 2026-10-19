<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 3 letters, for WordLength3Rule to clear. */
final class DecoyLength3Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(3, 10);
    }
}
