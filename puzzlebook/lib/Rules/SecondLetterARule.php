<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/** Cross out every six-letter word whose second letter is A. */
final class SecondLetterARule extends LetterAtPositionRule
{
    public function __construct()
    {
        parent::__construct(2, ['a'], 6);
    }
}
