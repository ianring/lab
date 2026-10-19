<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Constraints\Constraint;
use Puzzlebook2\Constraints\ConstraintStore;

final class ConstraintRegistrationEntry implements TrailEntry
{
    public function __construct(
        private ConstraintStore $store,
        private Constraint $constraint,
    ) {
    }

    public function undo(): void
    {
        $this->store->unregister($this->constraint);
    }
}
