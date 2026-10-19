<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Solver\FilledBoard;

/**
 * One elimination rule, fully self-contained. Everything about how it
 * might collide with some OTHER rule is expressed through the constraints
 * it declares (constraintsFor() / globalConstraints()), never by
 * referencing another rule's class.
 */
interface Rule
{
    /** Matches the 'type' name used in config/chain.json -- the class's short name. */
    public function id(): string;

    /** Short, for diagnostics -- "words with a double E and at most 1 other vowel". */
    public function describe(): string;

    /** Full, reader-facing instruction (config rule_text usually overrides this for print). */
    public function ruleText(): string;

    /**
     * Elimination semantics, pure function of a SOLVED board and the
     * step-entry keep snapshot: which cells (possibly none) does this
     * rule's pattern cross out at $scope? Most rules return either every
     * cell of the scope or none; SurroundedCaptureRule returns only the
     * centre. The union over allScopes() is what the step removes.
     *
     * @return int[] cells to cross out
     */
    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array;

    /**
     * @return iterable<Scope> every scope this rule's geometry could apply
     *     to, independent of board content -- every single cell for a
     *     spelling rule, every vertically-adjacent pair for a vertical
     *     pair rule. Used by ChainRunner/Engine (elimination), never
     *     during construction -- construction uses demand()'s
     *     ScopeGenerator, which additionally knows which cells are free.
     */
    public function allScopes(BoardSpec $spec): iterable;

    /** What this rule needs, in the abstract, before any cell exists. */
    public function demand(ChainContext $ctx): Demand;

    /**
     * @return iterable<\Puzzlebook2\Constraints\Constraint> what any OTHER
     *     word, anywhere else on the board, must respect now that this
     *     reservation exists. Empty for a rule with no cross-cutting
     *     requirement (the common case).
     *
     * The solver validates each returned constraint against every
     * ALREADY-ASSIGNED cell it watches before accepting the reservation --
     * including this reservation's own just-claimed cells. So when the
     * pattern is self-referential (e.g. "no neighbour may share my first
     * letter" where the partner cell IS a neighbour), exclude the
     * reservation's own cells from watchedCells(), or the constraint
     * rejects its own words and the rule can never place itself -- the
     * exact trap v1's partner-exemption bug fell into, now surfaced
     * immediately instead of at verification time.
     */
    public function constraintsFor(Reservation $reservation): iterable;

    /**
     * @return iterable<\Puzzlebook2\Constraints\Constraint> constraints
     *     that exist from the START of the solve, before any reservation
     *     -- the rule's standing invariants. This is where a geometric
     *     rule declares "no UNPLANNED instance of my pattern may form
     *     among words that will still be standing when I run" (v2's
     *     generic replacement for v1's NoUnplannedVerticalEndingMatch /
     *     NoUnplannedCornerLetter / NoLengthAdjacentToOpposingLiteral
     *     one-off invariant classes -- see StandingPatternConstraint).
     *     Empty for pure spelling rules, whose "pattern" never spans cells.
     */
    public function globalConstraints(ChainContext $ctx): iterable;

    /**
     * 'eliminate' (most rules, the final step included), 'decoy' (claims
     * survivor cells the FINAL step clears -- apply is a no-op), or
     * 'answer' (the one cell that survives everything). ChainPlan derives
     * each cell's death step from this.
     */
    public function kind(): string;
}
