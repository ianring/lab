<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * Crossword slot `_ A _ _ Y` -- five-letter words with A as the 2nd letter and
 * Y as the last (~240 words): BADLY, BAGGY, BANDY, HANDY, PARTY, TARDY. Named
 * for BAGGY; the slot is the rule.
 */
final class CrosswordBaggyRule extends CrosswordSlotRule
{
    protected function pattern(): string
    {
        return '_a__y';
    }

    protected function examples(): array
    {
        return ['baggy', 'handy', 'party'];
    }
}
