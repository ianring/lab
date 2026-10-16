<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;

/**
 * A rule that is a pure function of a word's own spelling -- never its
 * position, never its neighbours. failsWord() is both halves at once: it's
 * what apply() uses to solve the rule over the whole grid, and it's exactly
 * the check construct() needs to build a word that fails this rule on
 * purpose. One method, read once, used both ways -- the two can never
 * quietly disagree about what the rule means.
 *
 * Because that's true of every spelling rule, construct() is implemented
 * here once and inherited: a new pure spelling check needs nothing but
 * failsWord(), and it can be dropped straight into the chain.
 */
abstract class SpellingRule implements RuleInterface, ConstructsCells
{
    /** True if $word fails this rule and should be crossed out. */
    abstract public function failsWord(string $word): bool;

    /**
     * @param string[] $words
     * @param bool[] $keep
     * @return bool[]
     */
    public function apply(array $words, Grid $grid, array $keep): array
    {
        foreach ($words as $i => $word) {
            if ($keep[$i] && $this->failsWord($word)) {
                $keep[$i] = false;
            }
        }
        return $keep;
    }

    /**
     * Claim any $budget free cells and ask for words that pass every rule before
     * this one and fail this one. Position is irrelevant to a spelling rule, so
     * which cells it gets doesn't matter -- it takes whatever is going.
     */
    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $budget = $context->budget();
        if ($budget <= 0) {
            return;
        }

        $free = $canvas->freeCellsShuffled($context->seed());
        if (count($free) < $budget) {
            throw new ConstructionRetry(
                "step {$context->stepId()} needs {$budget} cells but only " . count($free) . ' are free'
            );
        }

        // ONE plan, shared by every cell this rule claims. They all want exactly the
        // same thing, and a plan is immutable, so building one per cell would cost an
        // object and a closure each time -- thousands of them at full book size, which
        // is enough to exhaust PHP's default memory limit on its own.
        $plan = new CellPlan(
            $context->spellingRulesBefore(),
            fn (string $word): bool => $this->failsWord($word),
            'stage' . $context->stepId()
        );

        for ($n = 0; $n < $budget; $n++) {
            $canvas->claimDoomed($free[$n], $context->stepId(), $plan);
        }
    }

    public function constructionPriority(): int
    {
        // Takes any cell at all, so it goes last and lets fussier rules choose first.
        return 100;
    }
}
