<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * Crossword slot `_ I G H _` -- five-letter words with IGH in the middle. A
 * small, elegant showpiece (~13 words), nearly all of them instantly familiar:
 * EIGHT, LIGHT, NIGHT, RIGHT, SIGHT, TIGHT. Named for NIGHT; the slot is the rule.
 */
final class CrosswordNightRule extends CrosswordSlotRule
{
    protected function pattern(): string
    {
        return '_igh_';
    }

    protected function examples(): array
    {
        return ['eight', 'light', 'night'];
    }
}
