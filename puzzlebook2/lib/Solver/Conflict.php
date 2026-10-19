<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * Why a slot's attempt failed: every candidate it was allowed to try (from
 * its resume position onward) was rejected or unavailable.
 *
 * $responsibleLevels is DIAGNOSTIC only: the decision levels of registered
 * constraints that rejected at least one tried candidate. It is knowingly
 * incomplete -- failures caused by word consumption or by cells being
 * claimed are not attributed -- which is exactly why the solver does NOT
 * use it to backjump: conflict-directed backjumping is only sound when the
 * conflict set is complete, and pretending otherwise would silently skip
 * parts of the search space. Backtracking is therefore chronological and
 * systematic; these levels exist for explain/Advisor output, nothing else.
 */
final class Conflict
{
    /** @param int[] $responsibleLevels */
    public function __construct(
        public readonly ReservationSlot $slot,
        public readonly string $description,
        public readonly array $responsibleLevels,
    ) {
    }
}
