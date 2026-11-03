<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * Crossword slot `_ A _ E` -- four-letter words with A as the 2nd letter and E
 * as the 4th (~147 words): BAKE, CAGE, CAKE, GAME, LAKE, MAZE, WAVE. The
 * shortest slot in the family, so the most forgiving to check by eye. Named for
 * BAKE; the slot is the rule.
 */
final class CrosswordBakeRule extends CrosswordSlotRule
{
    protected function pattern(): string
    {
        return '_a_e';
    }

    protected function examples(): array
    {
        return ['cake', 'game', 'lake'];
    }
}
