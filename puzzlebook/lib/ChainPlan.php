<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Rules\ConstructsCells;
use Puzzlebook\Rules\SpellingRule;

/**
 * Everything the builder needs to know about a chain, read once out of
 * config/chain.json: which steps there are, which rule object each one uses,
 * which of them know how to construct themselves, and in what order they get
 * to claim cells.
 *
 * Pulled out of ChainBuilder so that reading a chain and building a book are
 * separate concerns -- and so bin/check.php can inspect a chain without
 * going anywhere near cell allocation.
 *
 * Note what is NOT here: any list of specific rule roles. An earlier version
 * of this returned a fixed tuple ("the pair step, the key step, the final
 * step..."), which meant every new mechanism needed another slot and another
 * branch in the builder. A chain is now just an ordered list of steps, some
 * of which happen to implement ConstructsCells.
 */
final class ChainPlan
{
    /** @var array[] the chain's steps, in order */
    private array $steps;

    /** @var array<int, SpellingRule> spelling rules that run during the book, in order */
    private array $spellingRules = [];

    /** @var array<int,int> step id => how many spelling rules run before it */
    private array $spellingRulesBefore = [];

    /** @var array<int, array<int, SpellingRule>> step id => the spelling steps before it */
    private array $earlierSpellingSteps = [];

    /** @var array<int, SpellingRule> step id => rule, for spelling steps, in chain order */
    private array $spellingStepsById = [];

    /** @var array<int,float> step id => keep_fraction, for steps that declared one */
    private array $keepFractions = [];

    /** @var array<int, array{0:int,1:ConstructsCells,2:int}> [stepId, rule, priority] */
    private array $constructors = [];

    /** @var int[] ids of steps whose budget is derived rather than set by keep_fraction */
    private array $derivedBudgetSteps = [];

    /** Optional: a chain is allowed to have none. See revealWords()/revealInterval(). */
    private ?array $revealStep = null;
    private ?array $finalStep = null;
    private ?SpellingRule $finalRule = null;

    private function __construct(array $steps)
    {
        $this->steps = $steps;
    }

    public static function fromConfig(array $chain): self
    {
        $plan = new self(self::numberSteps($chain)['steps']);
        $plan->read();
        return $plan;
    }

    /**
     * Fills in each step's 'id' from its position, so config/chain.json doesn't have
     * to carry hand-written numbers.
     *
     * A step's number is just where it sits in the list -- it was never independent
     * information, and writing it out by hand meant every insertion renumbered
     * everything after it. That went wrong three times in one afternoon. An explicit
     * 'id' is still honoured if one is given, for a chain that wants to skip numbers.
     */
    public static function numberSteps(array $chain): array
    {
        foreach ($chain['steps'] as $index => $step) {
            if (!isset($step['id'])) {
                $chain['steps'][$index]['id'] = $index + 1;
            }
        }
        return $chain;
    }

    private function read(): void
    {
        $revealStep = null;

        // First pass: find the final step, since rules that outlive themselves need it.
        foreach ($this->steps as $step) {
            if (($step['kind'] ?? '') === 'eliminate' && !empty($step['final'])) {
                if ($this->finalStep !== null) {
                    throw new \RuntimeException("Only one step can be marked 'final' => true.");
                }
                $this->finalStep = $step;
                $rule = Engine::ruleFromConfig($step['rule']);
                if (!$rule instanceof SpellingRule) {
                    throw new \RuntimeException(
                        "The final step (id {$step['id']}) has to be a pure spelling rule, since it decides "
                        . 'which of the last words standing is removed and which is the answer.'
                    );
                }
                $this->finalRule = $rule;
            }
        }

        foreach ($this->steps as $step) {
            if (($step['kind'] ?? '') === 'reveal') {
                if ($revealStep !== null) {
                    throw new \RuntimeException(
                        'This builder places one reveal. A chain with several needs multi-reveal support '
                        . 'adding first (see README, "Known limits").'
                    );
                }
                $revealStep = $step;
                continue;
            }

            $isFinal = !empty($step['final']);
            $rule = Engine::ruleFromConfig($step['rule']);

            // The final step runs after everything, so it is not part of the prefix
            // that ordinary words have to survive, and it never claims cells.
            if (!$isFinal) {
                $this->spellingRulesBefore[$step['id']] = count($this->spellingRules);
                $this->earlierSpellingSteps[$step['id']] = $this->spellingStepsById;

                $construction = $step['construction'] ?? null;
                if ($construction === null) {
                    throw new \RuntimeException(
                        "Step {$step['id']} is an eliminate step with no 'construction' key, so the builder "
                        . "doesn't know how many cells it should claim. Give it a construction.keep_fraction, "
                        . "or construction.derived => true if its reach is decided by its own geometry."
                    );
                }

                if (!$rule instanceof ConstructsCells) {
                    throw new \RuntimeException(
                        "Step {$step['id']} uses rule type '{$step['rule']['type']}', which doesn't know how "
                        . 'to build its own cells. Give that rule class a construct() method (implement '
                        . 'Puzzlebook\\Rules\\ConstructsCells) -- see lib/Rules/MatchingNeighbourEndsRule.php '
                        . 'for a worked example.'
                    );
                }

                $this->constructors[] = [$step['id'], $rule, $rule->constructionPriority()];

                if (!empty($construction['derived'])) {
                    $this->derivedBudgetSteps[] = $step['id'];
                } elseif (isset($construction['keep_fraction'])) {
                    $this->keepFractions[$step['id']] = (float) $construction['keep_fraction'];
                } else {
                    throw new \RuntimeException(
                        "Step {$step['id']} has a 'construction' key with neither keep_fraction nor "
                        . "derived => true, so there's no way to tell how many cells it should claim."
                    );
                }

                // A spelling rule contributes to the prefix every later word must pass.
                if ($rule instanceof SpellingRule) {
                    $this->spellingRules[] = $rule;
                    $this->spellingStepsById[$step['id']] = $rule;
                }
            }
        }

        // A chain is allowed to have no reveal at all -- Book 1 doesn't, while it's
        // being built without the mechanisms that compete for the same survivor
        // slots. See revealWords()/revealInterval() for what an absent one returns.
        if ($this->finalStep === null) {
            throw new \RuntimeException("config/chain.json needs one step marked 'final' => true.");
        }
        $this->revealStep = $revealStep;

        usort($this->constructors, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
    }

    // --------------------------------------------------------------------- accessors

    /** @return SpellingRule[] in chain order */
    public function spellingRules(): array
    {
        return $this->spellingRules;
    }

    public function spellingRuleCount(): int
    {
        return count($this->spellingRules);
    }

    /** @return array<int, array{0:int,1:ConstructsCells,2:int}> [stepId, rule, priority], fussiest first */
    public function constructorsByPriority(): array
    {
        return $this->constructors;
    }

    /**
     * True if this step's reach is set by a keep_fraction, false if it's derived from
     * the rule's own geometry.
     *
     * The builder needs this because a derived rule takes cells without a budget, so
     * every such rule has to claim BEFORE the budgets for the others are worked out --
     * otherwise the arithmetic promises cells that a derived rule then takes.
     */
    public function hasBudget(int $stepId): bool
    {
        return isset($this->keepFractions[$stepId]);
    }

    /** @return string[] empty if this chain has no reveal step. */
    public function revealWords(): array
    {
        return $this->revealStep === null ? [] : explode(' ', $this->revealStep['expected']);
    }

    /** 0 if this chain has no reveal step. */
    public function revealInterval(): int
    {
        return $this->revealStep === null ? 0 : $this->revealStep['reveal']['params']['n'];
    }

    public function revealStepId(): ?int
    {
        return $this->revealStep === null ? null : $this->revealStep['id'];
    }

    public function hasReveal(): bool
    {
        return $this->revealStep !== null;
    }

    public function finalStepId(): int
    {
        return $this->finalStep['id'];
    }

    public function finalRule(): SpellingRule
    {
        return $this->finalRule;
    }

    /**
     * @param string[] $wordPool
     * @param array<int,string[]> $prefixPools
     */
    public function contextFor(
        int $stepId,
        int $budget,
        int $targetSurvivors,
        int $seedBase,
        array $wordPool = [],
        string $wordPoolHash = '',
        array $prefixPools = []
    ): ConstructionContext {
        return new ConstructionContext(
            $stepId,
            $budget,
            $this->spellingRulesBefore[$stepId] ?? count($this->spellingRules),
            count($this->spellingRules),
            $this->finalStep['id'],
            $this->finalRule,
            $targetSurvivors,
            $seedBase + $stepId * 101,
            $this->earlierSpellingSteps[$stepId] ?? [],
            $wordPool,
            $wordPoolHash,
            $prefixPools
        );
    }

    /**
     * How many cells each claiming step may take, so that between them they account
     * for exactly the $available cells the survivor-defining rules left behind.
     *
     * keep_fraction sets the PACING -- its share of the notional flow down the book --
     * and the last step with a keep_fraction absorbs the rounding, so the totals come
     * out exact however the geometry landed.
     *
     * @return array<int,int> step id => cells it may claim
     */
    public function budgetsFor(int $available, int $bookSize): array
    {
        $budgets = [];
        $remaining = $bookSize;
        foreach ($this->keepFractions as $stepId => $keepFraction) {
            $take = (int) round($remaining * (1 - $keepFraction));
            $budgets[$stepId] = $take;
            $remaining -= $take;
        }
        foreach ($this->derivedBudgetSteps as $stepId) {
            $budgets[$stepId] = 0;
        }

        if ($this->keepFractions === []) {
            return $budgets;
        }

        $ids = array_keys($this->keepFractions);
        $lastId = $ids[count($ids) - 1];
        $budgets[$lastId] += $available - array_sum($budgets);

        if ($budgets[$lastId] < 0) {
            throw new ConstructionRetry(
                "step {$lastId} would have to claim {$budgets[$lastId]} cells (a negative number): the "
                . 'earlier keep_fractions already account for more of the book than is left'
            );
        }

        return $budgets;
    }

    /** @param string $answer the word the book must end on */
    public function validateReservedWords(string $answer): void
    {
        $revealWords = $this->revealWords();

        $declared = $this->revealStep !== null ? ($this->revealStep['reveal']['params']['count'] ?? null) : null;
        if ($declared !== null && $declared !== count($revealWords)) {
            throw new \RuntimeException(
                "Step {$this->revealStep['id']} declares count={$declared} but 'expected' splits into "
                . count($revealWords) . ' words.'
            );
        }

        $seen = [];
        foreach ($revealWords as $w) {
            if (isset($seen[$w])) {
                throw new \RuntimeException(
                    "The revealed phrase uses '{$w}' twice, but each word can only be placed in the book "
                    . 'once. Reword the phrase so every word in it is different.'
                );
            }
            $seen[$w] = true;
        }
        if (isset($seen[$answer])) {
            throw new \RuntimeException(
                "The answer '{$answer}' also appears in the revealed phrase, but each word can only be "
                . 'placed once.'
            );
        }

        foreach (array_merge([$answer], $revealWords) as $word) {
            foreach ($this->spellingRules as $index => $rule) {
                if ($rule->failsWord($word)) {
                    throw new \RuntimeException(
                        "'{$word}' has to survive every spelling rule to still be there when it's needed, "
                        . 'but spelling rule #' . ($index + 1) . ' crosses it out. Reword it, or loosen '
                        . 'that rule.'
                    );
                }
            }
        }

        if ($this->finalRule->failsWord($answer)) {
            throw new \RuntimeException(
                "The answer '{$answer}' is crossed out by the final step {$this->finalStep['id']}, so "
                . 'nothing would be left standing. Pick an answer that rule does not match.'
            );
        }
    }
}
