<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Solver\BoardView;

/**
 * Where a rule's geometry could possibly go, given the board's current
 * state. A new geometric relationship -- however strange -- is a new
 * implementation of this interface, never a change to the solver.
 */
interface ScopeGenerator
{
    /**
     * Yields every currently-possible scope (every cell in it free), in a
     * seeded-shuffled order so construction doesn't systematically cluster
     * near one structural landmark the way v1's "column 0 first" preference
     * did.
     *
     * CONTRACT -- the solver's correctness depends on both halves:
     *  - Lazy: the solver consumes this via iteration and usually stops
     *    early; never require the whole set to be realized (for "any single
     *    free cell" on a near-empty 12,000-cell board that set is huge).
     *  - Stateless and deterministic: the yielded sequence must be a pure
     *    function of (board state, seed) -- no caching across calls, no
     *    hidden mutable state. Systematic backtracking resumes a retried
     *    decision at candidate index N, which is only sound if re-invoking
     *    this method under the restored board state reproduces the exact
     *    same sequence.
     *
     * @return iterable<Scope>
     */
    public function candidates(BoardView $view, int $seed): iterable;
}
