<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Board\BoardSpec;

/**
 * The read-only face of a Board -- what Constraint::violatedBy() and a
 * rule's predicates are allowed to see. Board implements this directly
 * (rather than a separate wrapper object) since PHP has no cheap way to
 * enforce immutability at the object-identity level anyway; the interface
 * exists to make the intent explicit at every call site that takes a
 * BoardView, not a Board.
 */
interface BoardView
{
    public function spec(): BoardSpec;

    /** The committed word at $cell, or null if not yet assigned. */
    public function wordAt(int $cell): ?string;

    public function isAssigned(int $cell): bool;

    public function isFree(int $cell): bool;

    /** Which rule claimed $cell, or null if free -- StandingPatternConstraint needs it to know when a word dies. */
    public function ownerOf(int $cell): ?string;
}
