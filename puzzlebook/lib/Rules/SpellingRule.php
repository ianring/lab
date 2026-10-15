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

        $failsThisRule = fn (string $word): bool => $this->failsWord($word);

        for ($n = 0; $n < $budget; $n++) {
            $canvas->claimDoomed(
                $free[$n],
                $context->stepId(),
                new CellPlan(
                    $context->spellingRulesBefore(),
                    static fn (string $word): bool => $failsThisRule($word),
                    'stage' . $context->stepId()
                )
            );
        }
    }

    public function constructionPriority(): int
    {
        // Takes any cell at all, so it goes last and lets fussier rules choose first.
        return 100;
    }
}
