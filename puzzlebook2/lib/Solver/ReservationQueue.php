<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * Every rule's demand, expanded into individual slots, in ONE global
 * priority queue -- never processed rule-by-rule. This is the direct
 * replacement for v1's hand-tuned constructionPriority() integers: which
 * slot resolves next is a computed property of how constrained it is, not
 * a number a rule author has to guess and re-tune per new rule.
 *
 * Ties break FIFO by insertion sequence (the second priority component),
 * so the pop order is fully deterministic -- a solve with the same seed
 * and the same chain is exactly reproducible, pop for pop.
 */
final class ReservationQueue
{
    private \SplPriorityQueue $heap;

    private int $seq = 0;

    public function __construct()
    {
        // SplPriorityQueue extracts the HIGHEST priority first; slots use an
        // ascending priorityKey() (smaller = more constrained = wanted
        // first), so both components are negated.
        $this->heap = new \SplPriorityQueue();
    }

    public function push(ReservationSlot $slot): void
    {
        $this->heap->insert($slot, [-$slot->priorityKey(), -$this->seq++]);
    }

    public function isEmpty(): bool
    {
        return $this->heap->isEmpty();
    }

    public function count(): int
    {
        return $this->heap->count();
    }

    /** Removes and returns the most-constrained pending slot. */
    public function pop(): ReservationSlot
    {
        return $this->heap->extract();
    }
}
