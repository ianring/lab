<?php

declare(strict_types=1);

namespace Puzzlebook2\Feasibility;

enum RecommendationKind: string
{
    /** Change a step's keep_fraction -- its demand is out of proportion to its real supply. */
    case Rebalance = 'rebalance';
    /** Move a step to a different position -- its supply is better before/after some other rule. */
    case Reorder = 'reorder';
    /** Two rules are structurally fighting -- one of them should change or go. */
    case Incompatibility = 'incompatibility';
    /** A hard config error -- missing keys, duplicate rules, impossible counts. */
    case Config = 'config';
}

/**
 * One concrete, machine-generated "change this" -- the Advisor's output
 * unit. The optional structured fields are what makes a recommendation
 * APPLICABLE by AutoTuner rather than merely readable by a human: a
 * Rebalance carries the computed keep_fraction, a Reorder carries the step
 * to move. Recommendations without them (config errors, incompatibilities)
 * require a human decision by nature.
 */
final class Recommendation
{
    public function __construct(
        public readonly RecommendationKind $kind,
        public readonly string $message,
        public readonly ?int $stepId = null,
        public readonly ?float $suggestedKeepFraction = null,
        public readonly bool $moveEarlier = false,
    ) {
    }

    public function isAutoApplicable(): bool
    {
        return $this->stepId !== null && ($this->suggestedKeepFraction !== null || $this->moveEarlier);
    }

    public function render(): string
    {
        return sprintf('[%s] %s', $this->kind->value, $this->message);
    }
}
