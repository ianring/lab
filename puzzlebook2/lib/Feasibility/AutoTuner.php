<?php

declare(strict_types=1);

namespace Puzzlebook2\Feasibility;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Solver\Board;

/**
 * The software side of "tell me what needs to change -- then change it":
 * takes a chain config, applies the Advisor's auto-applicable
 * recommendations one at a time (a keep_fraction set to the computed
 * value; a starved step moved one position earlier), re-runs the full
 * Phase-0 analysis after each change, and stops when the chain is clean
 * or nothing applicable remains. Every change is recorded in plain words.
 *
 * It never touches config/chain.json itself -- the tuned chain is returned
 * (bin/advise.php writes it to chain.tuned.json) and the human decides
 * whether to adopt it. The analysis is the machine's; the taste stays
 * yours.
 */
final class AutoTuner
{
    private const MAX_ITERATIONS = 20;

    /**
     * @return array{chain: array, changes: string[], clean: bool}
     */
    public static function tune(array $chainConfig, BoardSpec $spec, Dictionary $dictionary, int $seed): array
    {
        $chain = ChainPlan::numberSteps($chainConfig);
        $changes = [];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $plan = ChainPlan::load($chain, new Board($spec), $dictionary, $seed);
            $recs = Advisor::advise($plan, $dictionary);

            $applicable = null;
            foreach ($recs as $rec) {
                if ($rec->isAutoApplicable()) {
                    $applicable = $rec;
                    break;
                }
            }
            if ($applicable === null) {
                $clean = array_filter($recs, static fn (Recommendation $r): bool => $r->kind !== RecommendationKind::Incompatibility) === [];
                return ['chain' => $chain, 'changes' => $changes, 'clean' => $recs === [] || $clean];
            }

            $pos = self::positionOf($chain, $applicable->stepId);
            if ($pos === null) {
                break;
            }

            if ($applicable->suggestedKeepFraction !== null) {
                $old = $chain['steps'][$pos]['construction']['keep_fraction'] ?? null;
                $chain['steps'][$pos]['construction']['keep_fraction'] = $applicable->suggestedKeepFraction;
                $changes[] = sprintf(
                    'step %d (%s): keep_fraction %s -> %.4f',
                    $applicable->stepId,
                    $chain['steps'][$pos]['rule']['type'] ?? '?',
                    $old === null ? '-' : sprintf('%.4f', (float) $old),
                    $applicable->suggestedKeepFraction,
                );
            } elseif ($applicable->moveEarlier && $pos > 0) {
                [$chain['steps'][$pos - 1], $chain['steps'][$pos]] = [$chain['steps'][$pos], $chain['steps'][$pos - 1]];
                $changes[] = sprintf(
                    'step %d (%s): moved one position earlier (now before step %d)',
                    $applicable->stepId,
                    $chain['steps'][$pos - 1]['rule']['type'] ?? '?',
                    $chain['steps'][$pos]['id'],
                );
            } else {
                break; // an applicable rec we cannot act on (already at front) -- stop rather than loop
            }
        }

        return ['chain' => $chain, 'changes' => $changes, 'clean' => false];
    }

    private static function positionOf(array $chain, int $stepId): ?int
    {
        foreach ($chain['steps'] as $pos => $step) {
            if (($step['id'] ?? null) === $stepId) {
                return $pos;
            }
        }
        return null;
    }
}
