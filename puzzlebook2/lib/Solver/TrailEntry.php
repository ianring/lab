<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * One undoable mutation. Each concrete entry holds direct references to
 * exactly what it needs to reverse -- no entry needs the whole Board, which
 * keeps every entry independently unit-testable.
 */
interface TrailEntry
{
    public function undo(): void;
}
