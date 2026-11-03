<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * Crossword slot `_ A _ _ E _` -- six-letter words with A as the 2nd letter and
 * E as the 5th (~719 words): MASTER, MARKET, GARDEN, BADGER, BASKET, CARPET.
 * The broadest slot in the family. Named for MASTER; the slot is the rule.
 */
final class CrosswordMasterRule extends CrosswordSlotRule
{
    protected function pattern(): string
    {
        return '_a__e_';
    }

    protected function examples(): array
    {
        return ['master', 'market', 'garden'];
    }
}
