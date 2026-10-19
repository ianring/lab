<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;
use Puzzlebook2\Solver\Rng;

/**
 * Any single free cell, anywhere on the board -- ordinary spelling-rule
 * filler. Stateless: the order is recomputed from the seed on every call,
 * so the sequence is a pure function of (board state, seed) -- the property
 * the solver's resume-by-index backtracking depends on, and what lets one
 * generator instance serve many slots, each with its own seed.
 *
 * $avoidCells: cells never offered at all -- for a rule whose every word
 * would violate a standing invariant there (an unwinnable search, skipped
 * up front rather than discovered empty over and over).
 */
final class SingleFreeCellGenerator implements ScopeGenerator
{
    /** @var array<int,true> */
    private array $avoid;

    /** @param int[] $avoidCells */
    public function __construct(array $avoidCells = [])
    {
        $this->avoid = array_fill_keys($avoidCells, true);
    }

    public function candidates(BoardView $view, int $seed): iterable
    {
        foreach (Rng::shuffle($view->spec()->allCells(), $seed) as $cell) {
            if ($view->isFree($cell) && !isset($this->avoid[$cell])) {
                yield new Scope(['cell' => $cell]);
            }
        }
    }
}
