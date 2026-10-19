<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Holds back 10 extra words of exactly 8 letters, for WordLength8Rule to clear. */
final class DecoyLength8Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(8, 10);
    }
}
