<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * Standard SAT/CSP trail-based undo log: every mutation the solver makes
 * (claims, constraint registrations, pool-floor advances) is pushed here. A
 * checkpoint is just "remember the trail's current length"; undoing pops
 * and reverses entries back to that length. This is what makes
 * backtracking cost proportional to what actually changed -- not a
 * full-board copy, and never a redo of the whole fill pass, which was v1's
 * single most expensive recurring problem.
 */
final class Trail
{
    /** @var TrailEntry[] */
    private array $entries = [];

    public function mark(): int
    {
        return count($this->entries);
    }

    public function push(TrailEntry $e): void
    {
        $this->entries[] = $e;
    }

    public function undoTo(int $mark): void
    {
        while (count($this->entries) > $mark) {
            $e = array_pop($this->entries);
            $e->undo();
        }
    }

    public function length(): int
    {
        return count($this->entries);
    }
}
