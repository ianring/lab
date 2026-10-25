<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** Holds back 15 extra words of exactly 8 letters, for WordLength8Rule to clear. */
final class DecoyLength8Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(8, 15);
    }
}
