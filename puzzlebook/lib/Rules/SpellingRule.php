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
     * Overridden by a subclass whose own parameters mean EVERY word it could ever
     * accept is exactly one length (LetterAtPositionRule, WordLengthRule) -- lets
     * construct() hand the filler a pool already narrowed to that length instead of
     * every surviving word of every length, most of which could never match anyway.
     * Null (the default) means length isn't part of what this rule checks at all.
     */
    public function fixedLength(): ?int
    {
        return null;
    }

    /**
     * True for a rule whose failsWord() can NEVER be satisfied by a word lacking
     * the letter 'e' -- DoubleEFewVowelsRule (needs a literal "ee") is the only
     * current example. CornerContainsLetterRule leaves most corners deliberately
     * E-free for other rules to use normally, which is fine for the vast
     * majority of rules (plenty of their matches lack E too) -- but a rule that
     * GUARANTEES E can never fill one of those corners, not occasionally but
     * every single time it tries, so excluding corners here outright avoids an
     * unwinnable dictionary search rather than discovering it empty at fill
     * time, over and over. Default false: nearly every rule doesn't need this.
     */
    public function avoidsCorners(): bool
    {
        return false;
    }

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
        if ($this->avoidsCorners()) {
            $corners = array_flip($canvas->grid()->allCorners());
            $free = array_values(array_filter($free, static fn (int $c): bool => !isset($corners[$c])));
        }
        if (count($free) < $budget) {
            throw new ConstructionRetry(
                "step {$context->stepId()} needs {$budget} cells but only " . count($free) . ' are free'
            );
        }

        // ONE plan, shared by every cell this rule claims. They all want exactly the
        // same thing, and a plan is immutable, so building one per cell would cost an
        // object and a closure each time -- thousands of them at full book size, which
        // is enough to exhaust PHP's default memory limit on its own.
        $shortClass = substr(strrchr(static::class, '\\'), 1);
        $plan = new CellPlan(
            $context->spellingRulesBefore(),
            fn (string $word): bool => $this->failsWord($word),
            'stage' . $context->stepId(),
            description: "step {$context->stepId()} [{$shortClass}]: {$this->describe()}",
            fixedLength: $this->fixedLength()
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
