<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Rules\Reservation;

/** A successful slot resolution: the committed reservation, plus WHICH candidate (by enumeration index) it was -- the number Decision::$resumeIndex is derived from. */
final class SlotCommit
{
    public function __construct(
        public readonly int $candidateIndex,
        public readonly Reservation $reservation,
    ) {
    }
}
