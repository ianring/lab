<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * Undo record for a BasePool floor advance. The floor skips words that are
 * globally consumed -- but backtracking RELEASES words, so an advance that
 * was valid when made can strand released words below the floor, silently
 * shrinking supply with every backtrack (and tainting any later
 * infeasibility verdict). Rewinding the floor alongside the claims that
 * justified it keeps "words below the floor are consumed" true at every
 * restored state. A rewound-too-far floor is merely a little rescanning;
 * a not-rewound floor is lost words.
 */
final class PoolFloorEntry implements TrailEntry
{
    public function __construct(
        private BasePool $pool,
        private int $previousFloor,
    ) {
    }

    public function undo(): void
    {
        $this->pool->restoreFloor($this->previousFloor);
    }
}
