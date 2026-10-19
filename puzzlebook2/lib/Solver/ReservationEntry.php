<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * A cell's domain and its word are created together, exactly once, at the
 * moment a reservation claims it -- there is no "cell already had a domain
 * before this" case to track, which is what keeps this a single entry type
 * instead of two that have to stay in sync.
 */
final class ReservationEntry implements TrailEntry
{
    public function __construct(
        private Board $board,
        private int $cell,
        private string $word,
    ) {
    }

    public function undo(): void
    {
        $this->board->releaseCell($this->cell, $this->word);
    }
}
