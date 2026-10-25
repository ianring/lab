<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** Holds back 8 extra words of exactly 9 letters, for WordLength9Rule to clear. */
final class DecoyLength9Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(9, 8);
    }
}
