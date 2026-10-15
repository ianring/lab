<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Rules\SpellingRule;

/**
 * What a rule is told about the chain it belongs to, when it's asked to
 * construct its part of the book.
 *
 * Deliberately small: a rule gets its own budget and position, plus the few
 * chain-wide facts it might legitimately need (the rules before it, the rule
 * that ends the book, how many survivors the reveal wants). Anything beyond
 * this and rules would start depending on each other's internals.
 */
final class ConstructionContext
{
    private int $stepId;
    private int $budget;
    private int $spellingRulesBefore;
    private int $spellingRulesTotal;
    private ?int $finalStepId;
    private ?SpellingRule $finalRule;
    private int $targetSurvivors;
    private int $seed;

    /** @var array<int, SpellingRule> step id => rule, for spelling rules running before this step */
    private array $earlierSpellingSteps;

    /** @param array<int, SpellingRule> $earlierSpellingSteps */
    public function __construct(
        int $stepId,
        int $budget,
        int $spellingRulesBefore,
        int $spellingRulesTotal,
        ?int $finalStepId,
        ?SpellingRule $finalRule,
        int $targetSurvivors,
        int $seed,
        array $earlierSpellingSteps = []
    ) {
        $this->earlierSpellingSteps = $earlierSpellingSteps;
        $this->stepId = $stepId;
        $this->budget = $budget;
        $this->spellingRulesBefore = $spellingRulesBefore;
        $this->spellingRulesTotal = $spellingRulesTotal;
        $this->finalStepId = $finalStepId;
        $this->finalRule = $finalRule;
        $this->targetSurvivors = $targetSurvivors;
        $this->seed = $seed;
    }

    /** The id of the step being constructed -- what to record as the killer of its cells. */
    public function stepId(): int
    {
        return $this->stepId;
    }

    /** How many cells this step is meant to eliminate. */
    public function budget(): int
    {
        return $this->budget;
    }

    /** How many spelling rules run BEFORE this step -- the prefix its words must survive. */
    public function spellingRulesBefore(): int
    {
        return $this->spellingRulesBefore;
    }

    /** How many spelling rules the chain has in total. */
    public function spellingRulesTotal(): int
    {
        return $this->spellingRulesTotal;
    }

    /** The step that ends the book, for a rule whose cells must outlive it. */
    public function finalStepId(): ?int
    {
        return $this->finalStepId;
    }

    /** The final step's rule, so a rule can shape words to be caught (or spared) by it. */
    public function finalRule(): ?SpellingRule
    {
        return $this->finalRule;
    }

    /** How many words should still be standing when the reveal is read. */
    public function targetSurvivors(): int
    {
        return $this->targetSurvivors;
    }

    /** A deterministic seed unique to this step and this build attempt. */
    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * The spelling rules that run before this step, keyed by step id, in chain order.
     *
     * For a rule that needs a PRECONDITION -- "only fires where some other cell has
     * already been crossed out" -- this is how it arranges one: claim that cell and
     * shape its word to fail one of these earlier rules, so it is reliably gone by
     * the time this step runs. Without it, a rule would have to hope another rule
     * happened to kill the right cell at the right time.
     *
     * @return array<int, SpellingRule>
     */
    public function earlierSpellingSteps(): array
    {
        return $this->earlierSpellingSteps;
    }
}
