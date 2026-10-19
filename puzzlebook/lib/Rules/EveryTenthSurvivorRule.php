<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every tenth surviving word, counting from the start of the book. */
final class EveryTenthSurvivorRule extends EveryNthSurvivorRule
{
    public function __construct()
    {
        parent::__construct(10);
    }
}
