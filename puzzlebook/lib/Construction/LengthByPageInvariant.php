<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;
use Puzzlebook\Rules\LengthByPageRule;

/**
 * Keeps every cell the length-by-page rule did NOT claim from accidentally
 * matching its page's target length.
 *
 * The rule crosses out every word of one length on each page, so a cell it
 * never claimed would be removed anyway if its word happened to be that
 * length -- including the answer, or a revealed word. That's a condition on
 * cells the rule doesn't own, which is exactly what CellPlan can't express
 * and FillInvariant can.
 *
 * Cells the rule DID claim are the ones meant to match, and they carry a plan
 * demanding that length, so they're left alone here: this only ever rejects a
 * word that would match by accident.
 */
final class LengthByPageInvariant implements FillInvariant
{
    /** @param array<int,bool> $claimed cells the rule placed on purpose, which must match */
    public function __construct(
        private LengthByPageRule $rule,
        private int $cellsPerPage,
        private array $claimed
    ) {
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        if (isset($this->claimed[$cell])) {
            return true;
        }
        return strlen($word) !== $this->rule->lengthForPage(intdiv($cell, $this->cellsPerPage));
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
        // Stateless: a page's target length is fixed from the start.
    }

    public function describe(): string
    {
        return 'the rule removing one word length per page';
    }
}
