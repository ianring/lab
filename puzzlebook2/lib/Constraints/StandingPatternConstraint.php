<?php

declare(strict_types=1);

namespace Puzzlebook2\Constraints;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\StepResolver;
use Puzzlebook2\Solver\BoardView;

/**
 * The generic "no UNPLANNED instance of my pattern" invariant -- one class
 * replacing v1's whole family of hand-written invariant classes
 * (NoUnplannedVerticalEndingMatch, NoUnplannedCornerLetter,
 * NoLengthAdjacentToOpposingLiteral, ...), each of which existed because a
 * geometric rule's pattern must only ever fire where that rule planned it.
 *
 * The reasoning, once, in full: a geometric rule at step k crosses out any
 * scope of its geometry whose words are ALL still standing when k runs and
 * match its pattern. Words standing at k are exactly those claimed by
 * steps >= k (plus decoys and the answer, which die at the final step or
 * never -- StepResolver knows). So an ACCIDENTAL pattern among such words
 * would cross out cells some later step budgeted for -- or the answer
 * itself. The one exemption: a scope whose cells ALL belong to rule k is
 * planned by definition (its own reservations -- and two of its own
 * reservations touching is harmless, both die at k anyway).
 *
 * violatedBy() therefore rejects a candidate word iff placing it would
 * COMPLETE such an unplanned scope: every other cell assigned, every
 * owner (candidate's claimant included) still standing at k, not all
 * owned by rule k, pattern satisfied. Scopes with free cells are left to
 * whoever later claims the last cell -- a completed bad pattern is always
 * caught at its final cell.
 */
final class StandingPatternConstraint implements Constraint
{
    /**
     * @param \Closure(int, BoardSpec): iterable<\Puzzlebook2\Rules\Scope> $scopesTouching
     *     every scope of the owner rule's geometry that includes the given cell
     * @param \Closure(array<string,string>): bool $pattern role=>word, true = the rule would eliminate this scope
     */
    public function __construct(
        private string $ownerRuleId,
        private int $stepIndex,
        private StepResolver $resolver,
        private BoardSpec $spec,
        private \Closure $scopesTouching,
        private \Closure $pattern,
        private string $label,
    ) {
    }

    public function violatedBy(int $cell, string $candidate, BoardView $view, ?string $claimantRuleId = null): bool
    {
        // A word that dies before step k can never be part of a standing
        // pattern at k. Unknown claimant = conservatively standing.
        if ($claimantRuleId !== null && !$this->resolver->standingAt($claimantRuleId, $this->stepIndex)) {
            return false;
        }

        foreach (($this->scopesTouching)($cell, $this->spec) as $scope) {
            $words = [];
            $allOwnedByMe = true;
            $applicable = true;

            foreach ($scope->cellsByRole as $role => $c) {
                if ($c === $cell) {
                    $words[$role] = $candidate;
                    if ($claimantRuleId !== $this->ownerRuleId) {
                        $allOwnedByMe = false;
                    }
                    continue;
                }
                $w = $view->wordAt($c);
                if ($w === null) {
                    $applicable = false; // incomplete -- the last claimant will be checked instead
                    break;
                }
                $owner = $view->ownerOf($c);
                if ($owner === null || !$this->resolver->standingAt($owner, $this->stepIndex)) {
                    $applicable = false; // that word is dead before k -- no standing pattern possible here
                    break;
                }
                if ($owner !== $this->ownerRuleId) {
                    $allOwnedByMe = false;
                }
                $words[$role] = $w;
            }

            if (!$applicable || $allOwnedByMe) {
                continue;
            }
            if (($this->pattern)($words)) {
                return true;
            }
        }

        return false;
    }

    public function watchedCells(): array
    {
        return $this->spec->allCells();
    }

    public function describe(): string
    {
        return $this->label;
    }
}
