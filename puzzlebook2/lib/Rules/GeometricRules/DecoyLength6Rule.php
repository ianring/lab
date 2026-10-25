<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** Holds back 12 extra words of exactly 6 letters, for WordLength6Rule to clear. */
final class DecoyLength6Rule extends DecoyLengthRule
{
    public function __construct()
    {
        parent::__construct(6, 12);
    }
}
