<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 9 letters, for WordLength9Rule to clear. */
final class DecoyLength9Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(9, 10);
    }
}
