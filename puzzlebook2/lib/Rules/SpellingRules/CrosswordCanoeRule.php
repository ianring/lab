<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * Crossword slot `C _ _ _ E` -- five-letter words that start with C and end
 * with E (~90 words): CABLE, CACHE, CANOE, CARVE, CASTE, CAUSE. Named for CANOE;
 * the slot is the rule.
 */
final class CrosswordCanoeRule extends CrosswordSlotRule
{
    protected function pattern(): string
    {
        return 'c___e';
    }

    protected function examples(): array
    {
        return ['cable', 'canoe', 'cause'];
    }
}
