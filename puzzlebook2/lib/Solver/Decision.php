<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * One committed decision on the solver's stack: which slot was resolved,
 * the trail mark taken BEFORE it was attempted (undoing to it erases the
 * decision completely), and where its candidate enumeration resumes if the
 * search ever backtracks into it.
 *
 * $resumeIndex is what makes the search SYSTEMATIC rather than a
 * reshuffle-and-retry: a slot's candidate sequence is deterministic given
 * its fixed seed and the board state at this node (which trail undo
 * restores exactly), so "the next candidate after the one whose subtree
 * just failed" is a well-defined, never-repeating position. Candidates
 * before it either failed here already or had their whole subtree
 * exhausted -- both proven dead for this node, both safe to skip forever.
 */
final class Decision
{
    public function __construct(
        public readonly ReservationSlot $slot,
        public readonly int $mark,
        public int $resumeIndex,
    ) {
    }
}
