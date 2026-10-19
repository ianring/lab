<?php

declare(strict_types=1);

namespace Puzzlebook2\Feasibility;

use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Rule;

/**
 * Phase 0: necessary-condition checks cheap enough to always run before the
 * solver. Anything caught here is reported in plain words instead of being
 * discovered through hours of search:
 *
 *  - Supply: a Shaped demand's count must not exceed its pool size.
 *  - Cell budget: the chain's demands (count x cellsPerScope, summed) must
 *    equal the board's size exactly -- more and the chain can never fit,
 *    fewer and the board can never fill.
 *
 * Deliberately narrow: geometric feasibility beyond raw counts, and
 * Exact-demand supply, are the Advisor's job (IMPLEMENTATION.md section
 * 6.1), not yet built. Passing these checks proves nothing -- failing them
 * proves infeasibility.
 */
final class FeasibilityAnalyzer
{
    /** @param Rule[] $rules */
    public function check(array $rules, ChainContext $ctx): FeasibilityReport
    {
        $problems = [];
        $cellsDemanded = 0;

        foreach ($rules as $rule) {
            $demand = $rule->demand($ctx);
            $cellsDemanded += $demand->count * $demand->cellsPerScope;

            if ($demand->arity === Arity::Shaped && $demand->count > $demand->rawSupplyEstimate) {
                $problems[] = sprintf(
                    "Rule '%s' demands %d words but its pool has only ~%d.",
                    $rule->id(),
                    $demand->count,
                    $demand->rawSupplyEstimate,
                );
            }
        }

        $size = $ctx->board()->spec()->size();
        if ($cellsDemanded > $size) {
            $problems[] = sprintf(
                'The chain demands %d cells but the board has only %d -- it can never fit.',
                $cellsDemanded,
                $size,
            );
        } elseif ($cellsDemanded < $size) {
            $problems[] = sprintf(
                'The chain demands only %d of the board\'s %d cells -- %d would stay unfilled. '
                . 'Add or grow a filler rule to cover the remainder.',
                $cellsDemanded,
                $size,
                $size - $cellsDemanded,
            );
        }

        return new FeasibilityReport($problems);
    }
}
