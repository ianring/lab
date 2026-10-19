<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word of exactly 7 letters. */
final class WordLength7Rule extends WordLengthRule
{
    public function __construct()
    {
        parent::__construct(7);
    }
}
