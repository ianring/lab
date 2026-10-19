<?php

declare(strict_types=1);

namespace Puzzlebook2\Chain;

/**
 * Maps a rule id (the owner recorded on each claimed cell) to the step at
 * which that rule's words DIE -- the one fact StandingPatternConstraint
 * needs: a word claimed by step j is still standing when step k runs iff
 * j >= k. Decoy cells die at the FINAL step, not their own; the answer
 * never dies (PHP_INT_MAX).
 */
final class StepResolver
{
    /** @param array<string,int> $deathIndexByRuleId */
    public function __construct(private array $deathIndexByRuleId)
    {
    }

    public function deathIndexOf(string $ruleId): int
    {
        if (!isset($this->deathIndexByRuleId[$ruleId])) {
            throw new \LogicException("StepResolver knows no rule '{$ruleId}' -- ChainPlan did not register it.");
        }
        return $this->deathIndexByRuleId[$ruleId];
    }

    /** Is a word claimed by $ruleId still standing when step $stepIndex runs? */
    public function standingAt(string $ruleId, int $stepIndex): bool
    {
        return $this->deathIndexOf($ruleId) >= $stepIndex;
    }
}
