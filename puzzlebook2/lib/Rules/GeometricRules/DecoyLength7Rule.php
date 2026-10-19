<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** Holds back 10 extra words of exactly 7 letters, for WordLength7Rule to clear. */
final class DecoyLength7Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(7, 10);
    }
}
