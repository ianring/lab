<?php

declare(strict_types=1);

namespace Puzzlebook2\Constraints;

use Puzzlebook2\Solver\BoardView;

/**
 * A rule's declared "how to avoid colliding with me." A rule that reserves
 * a scope hands back a list of these; the solver checks every candidate,
 * anywhere on the board, against the full accumulated set, uniformly, for
 * as long as this constraint stays registered. No rule ever references
 * another rule's class -- this interface is the only thing they share.
 */
interface Constraint
{
    /**
     * Given a candidate word for $cell, does it break this constraint?
     * Called for every candidate at every cell this constraint watches
     * (see ConstraintStore) -- keep this cheap.
     *
     * $claimantRuleId identifies which rule is trying to place the
     * candidate (the owning rule id for an already-assigned cell being
     * re-validated). Constraints that care which step a word dies at --
     * StandingPatternConstraint above all -- need it; most ignore it.
     * Null means unknown: treat the word as potentially standing forever
     * (the conservative reading).
     */
    public function violatedBy(int $cell, string $candidate, BoardView $view, ?string $claimantRuleId = null): bool;

    /** @return int[] cells this constraint currently cares about, for ConstraintStore indexing. */
    public function watchedCells(): array;

    public function describe(): string;
}
