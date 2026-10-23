<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;

/**
 * Exactly one cell, named up front -- the primitive a PLANT needs: a chosen
 * word has to land at a chosen (page, row, column), not just "any free cell"
 * the way SingleFreeCellGenerator offers. Yields that one cell as a single
 * 'cell'-role scope, and only while it is free (it will be, because plants
 * are Exact-arity and schedule before any filler competes for it; if it is
 * somehow taken, yielding nothing makes the solver fail loudly rather than
 * silently placing the plant somewhere else).
 *
 * Trivially stateless and deterministic -- the one property the solver's
 * resume-by-index backtracking depends on.
 */
final class FixedCellGenerator implements ScopeGenerator
{
    public function __construct(private int $cell)
    {
    }

    public function candidates(BoardView $view, int $seed): iterable
    {
        if ($view->isFree($this->cell)) {
            yield new Scope(['cell' => $this->cell]);
        }
    }
}
