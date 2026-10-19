<?php

declare(strict_types=1);

namespace Puzzlebook2\Feasibility;

use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Rules\SpellingRuleBase;
use Puzzlebook2\Solver\SolveResult;

/**
 * The layer that does the intelligent work when a chain won't build: given
 * the resolved plan (and, after a failed solve, the solver's conflict
 * tally), it produces CONCRETE recommendations -- "lower this
 * keep_fraction to X", "move this step before that one", "these two rules
 * are fighting" -- instead of leaving diagnosis to whoever is reading the
 * output. The human picks which recommendation to take; the software does
 * the analysis. (IMPLEMENTATION.md section 6.1.)
 */
final class Advisor
{
    /** Demand with less than this much supply headroom gets a rebalance warning. */
    private const MIN_HEADROOM = 1.5;

    /** Suggested keep_fractions aim for this headroom. */
    private const TARGET_HEADROOM = 2.0;

    /**
     * @return Recommendation[]
     */
    public static function advise(ChainPlan $plan, Dictionary $dictionary, ?SolveResult $solveResult = null): array
    {
        $recs = [];

        foreach ($plan->problems() as $problem) {
            $recs[] = new Recommendation(RecommendationKind::Config, $problem);
        }

        // ---- supply-vs-demand headroom, with concrete keep_fraction fixes ----
        foreach ($plan->demands() as $pos => $demand) {
            $step = $plan->steps()[$pos];
            $rule = $plan->rules()[$pos];
            if ($demand->count === 0 || $demand->rawSupplyEstimate === PHP_INT_MAX) {
                continue;
            }
            $binding = min($demand->rawSupplyEstimate, $demand->geometricCapacity);
            $headroom = $binding / $demand->count;
            if ($headroom >= self::MIN_HEADROOM) {
                continue;
            }
            $suggestion = '';
            $suggestedKf = null;
            if (isset($step['construction']['keep_fraction'])) {
                // demand ~= remaining * (1 - kf)  =>  kf for target headroom:
                $targetDemand = (int) floor($binding / self::TARGET_HEADROOM) * $demand->cellsPerScope;
                $kf = (float) $step['construction']['keep_fraction'];
                $impliedRemaining = $demand->count * $demand->cellsPerScope / max(1e-9, 1 - $kf);
                $suggestedKf = round(1 - ($targetDemand / max(1.0, $impliedRemaining)), 4);
                $suggestion = sprintf(
                    ' Suggest keep_fraction %.3f (now %.3f) to bring demand near %d at %.1fx headroom.',
                    $suggestedKf,
                    $kf,
                    $targetDemand,
                    self::TARGET_HEADROOM,
                );
            }
            $recs[] = new Recommendation(
                RecommendationKind::Rebalance,
                sprintf(
                    "Step %d (%s): demand %d against binding supply ~%d -- only %.1fx headroom.%s",
                    $step['id'],
                    $rule->id(),
                    $demand->count,
                    $binding,
                    $headroom,
                    $suggestion,
                ),
                stepId: $step['id'],
                suggestedKeepFraction: $suggestedKf,
            );
        }

        // ---- reorder analysis: would a starved spelling rule see more supply earlier? ----
        // Recompute each spelling rule's catch at every earlier position in
        // the chain (v1's AlphabetAdjacentEndsRule move, done by software).
        $spellingRules = [];
        foreach ($plan->rules() as $pos => $rule) {
            if ($rule instanceof SpellingRuleBase && $pos !== $plan->finalPos()) {
                $spellingRules[$pos] = $rule;
            }
        }
        foreach ($plan->demands() as $pos => $demand) {
            $rule = $plan->rules()[$pos];
            if (!$rule instanceof SpellingRuleBase || $demand->count === 0) {
                continue;
            }
            if ($demand->count <= $demand->rawSupplyEstimate) {
                continue; // not starved; reordering is churn for nothing
            }
            // Supply if this rule ran FIRST -- its ceiling anywhere.
            $atFront = 0;
            foreach ($dictionary->all() as $w) {
                if ($rule->failsWord($w)) {
                    $atFront++;
                }
            }
            if ($atFront >= $demand->count) {
                $recs[] = new Recommendation(
                    RecommendationKind::Reorder,
                    sprintf(
                        "Step %d (%s) is starved at its position (supply %d < demand %d) but would see %d matches "
                        . 'earlier in the chain -- earlier rules are eating its catch. Move it up, or shrink it.',
                        $plan->steps()[$pos]['id'],
                        $rule->id(),
                        $demand->rawSupplyEstimate,
                        $demand->count,
                        $atFront,
                    ),
                    stepId: $plan->steps()[$pos]['id'],
                    moveEarlier: true,
                );
            }
        }

        // ---- solver-failure forensics ----------------------------------
        if ($solveResult !== null && !$solveResult->isSuccess()) {
            arsort($conflicts = $solveResult->conflictTally);
            $top = array_slice($conflicts, 0, 3, true);
            if ($top !== []) {
                $parts = [];
                foreach ($top as $ruleId => $n) {
                    $parts[] = "{$ruleId} ({$n} dead ends)";
                }
                $recs[] = new Recommendation(RecommendationKind::Incompatibility, sprintf(
                    'The search thrashed hardest on: %s. These rules\' demands are the ones the rest of the '
                    . 'chain cannot accommodate -- lower their keep_fractions, loosen their patterns, or '
                    . 'check which standing guard keeps rejecting them (run with the conflict log).',
                    implode(', ', $parts),
                ));
            }
        }

        return $recs;
    }
}
