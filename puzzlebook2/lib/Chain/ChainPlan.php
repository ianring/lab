<?php

declare(strict_types=1);

namespace Puzzlebook2\Chain;

use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Rules\AnswerRule;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\GeometricRules\DecoyLengthRule;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\SpellingRuleBase;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\ReservationSlot;

/**
 * Everything the builder needs to know about a chain, read once out of
 * config/chain.json: the ordered steps, each step's Rule object, the
 * keep_fraction cell budgets (v1's cascade math, ported), every step's
 * prefix survivor pool, each rule's death step (StepResolver), and the
 * fully-resolved Demands -- with the absorber (the LAST keep_fraction
 * step) stretched or shrunk so demand cells sum to exactly the board size.
 *
 * problems() reports everything wrong in plain words BEFORE any solving:
 * supply shortfalls, cell-budget mismatches, an answer that can't survive,
 * duplicate rule types. A non-empty problems() means "don't bother
 * solving, here's what to change" -- the check/advise half of the
 * "build it or tell me why not" contract.
 */
final class ChainPlan
{
    /** @var array[] numbered config steps, in order */
    private array $steps = [];

    /** @var array<int,Rule> step position => rule */
    private array $rules = [];

    /** @var array<int,ChainContext> step position => context */
    private array $contexts = [];

    /** @var array<int,Demand> step position => resolved demand */
    private array $demands = [];

    private ?AnswerRule $answerRule = null;
    private ?ChainContext $answerContext = null;
    private ?Demand $answerDemand = null;

    /** @var \Puzzlebook2\Rules\PlantRule[] fixed words at fixed cells (mysteries, acrostics) */
    private array $plantRules = [];
    /** @var ChainContext[] parallel to $plantRules */
    private array $plantContexts = [];
    /** @var Demand[] parallel to $plantRules */
    private array $plantDemands = [];

    private int $finalPos = -1;
    private ?StepResolver $resolver = null;

    /** @var string[] */
    private array $problems = [];

    /**
     * @param array<int,array{cell:int,word:string,id?:string,role?:string}> $plantSpecs
     */
    private function __construct(
        private array $chain,
        private Board $board,
        private Dictionary $dictionary,
        private int $seed,
        private array $plantSpecs = [],
        private ?int $answerCell = null,
    ) {
    }

    /**
     * @param array<int,array{cell:int,word:string,id?:string,role?:string}> $plants
     *     Fixed words pinned at fixed cells -- the Mystery words. Each is
     *     placed (Exact arity, like the answer) and must be crossed out by
     *     some spelling rule (a dead word, still readable); ChainPlan
     *     validates that and records its death step.
     * @param ?int $answerCell pin the answer to this exact (page-edge) cell so
     *     the book's answer PAGE is known before solving; null = free pick.
     */
    public static function load(array $chainConfig, Board $board, Dictionary $dictionary, int $seed, array $plants = [], ?int $answerCell = null): self
    {
        $plan = new self(self::numberSteps($chainConfig), $board, $dictionary, $seed, $plants, $answerCell);
        $plan->read();
        return $plan;
    }

    /** Fills in each step's 'id' from its position -- hand-numbering went wrong three times in one v1 afternoon. */
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
        $this->steps = $this->chain['steps'];
        $spec = $this->board->spec();
        $size = $spec->size();
        $answer = $this->chain['answer'] ?? null;
        if (!is_string($answer) || $answer === '') {
            $this->problems[] = "config/chain.json has no 'answer'.";
            return;
        }

        // ---- rules, final step, duplicate detection -------------------
        $seenIds = [];
        foreach ($this->steps as $pos => $step) {
            if (($step['kind'] ?? '') === 'reveal') {
                $this->problems[] = "Step {$step['id']} is a reveal -- v2 does not support reveals yet.";
                return;
            }
            $rule = Engine::ruleFromConfig($step['rule']);
            $this->rules[$pos] = $rule;
            if (isset($seenIds[$rule->id()])) {
                $this->problems[] = "Rule type '{$rule->id()}' appears twice -- cell ownership would be ambiguous.";
            }
            $seenIds[$rule->id()] = true;

            if (!empty($step['final'])) {
                if ($this->finalPos !== -1) {
                    $this->problems[] = "Only one step can be marked 'final' => true.";
                }
                $this->finalPos = $pos;
                if (!$rule instanceof SpellingRuleBase) {
                    $this->problems[] = "The final step (id {$step['id']}) has to be a pure spelling rule.";
                }
            }
        }

        // ---- incompatible rule pairs (declared via INCOMPATIBLE_WITH) ----
        // Two rules whose matches so overlap that a book must not hold both:
        // a subset rule beside its superset (ContainsOnt beside ContainsNt),
        // or two pair rules that would police each other's plants (semordnilap
        // vs anagram). Symmetric -- either side's declaration is enough.
        // bin/compute-incompatibilities.php stamps the spelling ones; pair/
        // geometric ones are hand-declared on the class.
        $declared = [];
        foreach ($this->rules as $rule) {
            $rc = new \ReflectionClass($rule);
            $const = $rc->hasConstant('INCOMPATIBLE_WITH') ? $rc->getConstant('INCOMPATIBLE_WITH') : [];
            $declared[$rule->id()] = is_array($const) ? $const : [];
        }
        $present = array_keys($declared);
        foreach ($present as $x => $a) {
            foreach (array_slice($present, $x + 1) as $b) {
                if (in_array($b, $declared[$a], true) || in_array($a, $declared[$b], true)) {
                    $this->problems[] = "Rules '{$a}' and '{$b}' are declared incompatible "
                        . '(one subsumes the other), so a book must not use both.';
                }
            }
        }

        if ($this->finalPos === -1) {
            $this->problems[] = "config/chain.json needs one step marked 'final' => true.";
            return;
        }
        if ($this->problems !== []) {
            return;
        }
        /** @var SpellingRuleBase $finalRule */
        $finalRule = $this->rules[$this->finalPos];

        // ---- prefix pools (words surviving every spelling rule before each step) ----
        $prefixPools = [];
        $pool = $this->dictionary->all();
        foreach ($this->steps as $pos => $step) {
            $prefixPools[$pos] = $pool;
            $rule = $this->rules[$pos];
            if ($rule instanceof SpellingRuleBase && $pos !== $this->finalPos) {
                $pool = array_values(array_filter($pool, static fn (string $w): bool => !$rule->failsWord($w)));
            }
        }
        $fullSurvivors = $pool; // survives every non-final spelling rule

        // ---- answer validation ---------------------------------------
        if (!in_array($answer, $fullSurvivors, true)) {
            $this->problems[] = "The answer '{$answer}' does not survive every spelling rule (or is not in the word list).";
        } elseif ($finalRule->failsWord($answer)) {
            $this->problems[] = "The answer '{$answer}' is crossed out by the final step -- nothing would be left standing.";
        }

        // ---- death map ------------------------------------------------
        $death = [];
        foreach ($this->rules as $pos => $rule) {
            $death[$rule->id()] = $rule->kind() === 'decoy' ? $this->finalPos : $pos;
        }
        if ($this->answerCell !== null && ($this->answerCell < 0 || $this->answerCell >= $size)) {
            $this->problems[] = "answerCell {$this->answerCell} is outside 0.." . ($size - 1) . '.';
        }
        $this->answerRule = new AnswerRule($answer, $this->answerCell);
        $death[$this->answerRule->id()] = PHP_INT_MAX;

        // ---- plants (fixed words at fixed cells: mysteries, acrostics) -----
        // Built like the answer, but each dies at the earliest spelling rule
        // its word fails -- recorded in the death map so geometric rules'
        // standing logic treats it correctly.
        $usedCells = [];
        foreach ($this->plantSpecs as $spec0) {
            $cell = (int) ($spec0['cell'] ?? -1);
            $id = (string) ($spec0['id'] ?? ('plant#' . $cell));
            $role = (string) ($spec0['role'] ?? 'plant');
            if ($cell < 0 || $cell >= $size) {
                $this->problems[] = "Plant '{$id}' names cell {$cell}, outside 0.." . ($size - 1) . '.';
                continue;
            }
            if (isset($usedCells[$cell])) {
                $this->problems[] = "Two plants claim cell {$cell}.";
                continue;
            }
            $usedCells[$cell] = true;

            if (isset($spec0['letter'])) {
                // ACROSTIC plant: fix only the first letter; the word is any
                // eliminable one that starts with it (dictionary fallback).
                $letter = strtolower((string) $spec0['letter']);
                $candidates = $this->acrosticCandidates($letter, $answer);
                if ($candidates === []) {
                    $this->problems[] = "Acrostic plant at cell {$cell}: no eliminable word starts with '{$letter}'.";
                    continue;
                }
                // Conservative, always-safe death: no geometric rule runs after
                // the final step, so treating it as standing throughout never
                // misleads a standing-pattern constraint. Replay eliminates it
                // via the spelling rule it actually fails.
                $deathPos = $this->finalPos;
            } else {
                // MYSTERY plant: one exact word; its identity matters.
                $word = (string) ($spec0['word'] ?? '');
                if ($word === '') {
                    $this->problems[] = "Plant '{$id}' has neither a word nor a letter.";
                    continue;
                }
                if ($word === $answer) {
                    $this->problems[] = "Plant at cell {$cell} is the answer word '{$word}' -- a plant must be eliminable.";
                    continue;
                }
                $deathPos = $this->plantDeathStep($word);
                if ($deathPos === null) {
                    $this->problems[] = "Plant '{$word}' (cell {$cell}) survives every spelling rule -- it would be a "
                        . 'second survivor. Choose a word some rule crosses out.';
                    continue;
                }
                $candidates = [$word];
            }

            if (isset($death[$id])) {
                $this->problems[] = "Plant id '{$id}' is not unique.";
                continue;
            }
            $death[$id] = $deathPos;
            $this->plantRules[] = new \Puzzlebook2\Rules\PlantRule($id, $cell, $candidates, $role);
        }

        $this->resolver = new StepResolver($death);

        // ---- keep_fraction budgets (v1 cascade math, ported) -----------
        $decoyCells = 0;
        foreach ($this->rules as $pos => $rule) {
            if ($rule instanceof DecoyLengthRule) {
                $decoyCells += $rule->demand($this->bareContext($pos, 0, $fullSurvivors))->count;
            }
        }
        $available = $size - $decoyCells - 1 - count($this->plantRules); // -1 answer, -K plants

        $budgets = [];
        $keepFractionPositions = [];
        $remaining = $size;
        foreach ($this->steps as $pos => $step) {
            if ($pos === $this->finalPos) {
                continue;
            }
            $construction = $step['construction'] ?? null;
            if ($construction === null) {
                $this->problems[] = "Step {$step['id']} is an eliminate step with no 'construction' key.";
                continue;
            }
            if (!empty($construction['derived'])) {
                $budgets[$pos] = 0;
                continue;
            }
            if (!isset($construction['keep_fraction'])) {
                $this->problems[] = "Step {$step['id']} has 'construction' with neither keep_fraction nor derived => true.";
                continue;
            }
            $take = (int) round($remaining * (1 - (float) $construction['keep_fraction']));
            $budgets[$pos] = $take;
            $remaining -= $take;
            $keepFractionPositions[] = $pos;
        }
        $budgets[$this->finalPos] = 0;
        if ($this->problems !== []) {
            return;
        }

        // The absorber -- the LAST keep_fraction step -- soaks up rounding
        // so the keep_fraction budgets sum to exactly what's available.
        $absorberPos = $keepFractionPositions[count($keepFractionPositions) - 1];
        $budgets[$absorberPos] += $available - array_sum($budgets);
        if ($budgets[$absorberPos] < 0) {
            $this->problems[] = sprintf(
                'Step %d (the absorber) would have to claim %d cells: the earlier keep_fractions already '
                . 'account for more of the book than exists. Raise one of them.',
                $this->steps[$absorberPos]['id'],
                $budgets[$absorberPos],
            );
            return;
        }

        // ---- contexts and demands -------------------------------------
        foreach ($this->steps as $pos => $step) {
            $rulePool = $this->rules[$pos] instanceof DecoyLengthRule ? $fullSurvivors : $prefixPools[$pos];
            $this->contexts[$pos] = $this->bareContext($pos, $budgets[$pos], $rulePool);
            $this->demands[$pos] = $this->rules[$pos]->demand($this->contexts[$pos]);
        }
        $this->answerContext = $this->bareContext(count($this->steps), 0, $fullSurvivors);
        $this->answerDemand = $this->answerRule->demand($this->answerContext);

        // Plant contexts/demands -- Exact, so the pool is unused; distinct
        // stepIndex per plant only to keep their seeds apart.
        $basePos = count($this->steps) + 1;
        foreach ($this->plantRules as $i => $plant) {
            $this->plantContexts[$i] = $this->bareContext($basePos + $i, 0, []);
            $this->plantDemands[$i] = $plant->demand($this->plantContexts[$i]);
        }

        // Pair rules claim 2 cells per scope and cap their pair counts, so
        // actual cells can fall short of the keep_fraction budgets; the
        // absorber (ordinary filler with real fallback) takes the leftover
        // -- exactly v1's absorb-leftover-cells step, now explicit.
        $cells = 1 + count($this->plantRules); // the answer + one cell per plant
        foreach ($this->demands as $d) {
            $cells += $d->count * $d->cellsPerScope;
        }
        $leftover = $size - $cells;
        if ($leftover !== 0) {
            $absorberBudget = $budgets[$absorberPos] + $leftover;
            if ($absorberBudget < 0) {
                $this->problems[] = sprintf(
                    'After pair caps, the absorber (step %d) would need a negative budget (%d).',
                    $this->steps[$absorberPos]['id'],
                    $absorberBudget,
                );
                return;
            }
            $this->contexts[$absorberPos] = $this->bareContext($absorberPos, $absorberBudget, $prefixPools[$absorberPos]);
            $this->demands[$absorberPos] = $this->rules[$absorberPos]->demand($this->contexts[$absorberPos]);
        }

        // ---- supply checks against the resolved demands ----------------
        foreach ($this->demands as $pos => $d) {
            if ($d->count > $d->rawSupplyEstimate) {
                $this->problems[] = sprintf(
                    "Step %d (%s) demands %d x %d-cell scopes but its supply is only ~%d.",
                    $this->steps[$pos]['id'],
                    $this->rules[$pos]->id(),
                    $d->count,
                    $d->cellsPerScope,
                    $d->rawSupplyEstimate,
                );
            }
        }
        $finalCells = 1 + count($this->plantRules);
        foreach ($this->demands as $d) {
            $finalCells += $d->count * $d->cellsPerScope;
        }
        if ($finalCells !== $size) {
            $this->problems[] = sprintf(
                'Demands cover %d cells but the board has %d -- the chain cannot tile the book exactly.',
                $finalCells,
                $size,
            );
        }
    }

    private function bareContext(int $pos, int $budget, array $pool): ChainContext
    {
        return new ChainContext(
            $this->seed + ($pos + 1) * 101,
            $this->board,
            $pos,
            $budget,
            $pool,
            $this->resolver,
        );
    }

    // ------------------------------------------------------------ accessors

    /** @return string[] empty = good to build. */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array[] the numbered config steps, for export/ChainRunner. */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return array<int,Rule> step position => rule. */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return array<int,Demand> step position => resolved demand. */
    public function demands(): array
    {
        return $this->demands;
    }

    public function answer(): string
    {
        return $this->chain['answer'];
    }

    public function finalPos(): int
    {
        return $this->finalPos;
    }

    public function resolver(): StepResolver
    {
        if ($this->resolver === null) {
            throw new \LogicException('ChainPlan::read() has not built the resolver -- check problems().');
        }
        return $this->resolver;
    }

    public function chainConfig(): array
    {
        return $this->chain;
    }

    /** Registers every rule's standing invariants -- call once, before solving. */
    public function registerGlobalConstraints(): void
    {
        foreach ($this->rules as $pos => $rule) {
            foreach ($rule->globalConstraints($this->contexts[$pos]) as $constraint) {
                $this->board->registerPermanentConstraint($constraint);
            }
        }
    }

    /** Expands every demand (the answer's included) into slots on $queue. */
    public function fillQueue(ReservationQueue $queue): void
    {
        foreach ($this->demands as $pos => $demand) {
            for ($i = 0; $i < $demand->count; $i++) {
                $queue->push(new ReservationSlot($this->rules[$pos], $demand, $this->contexts[$pos], $i));
            }
        }
        $queue->push(new ReservationSlot($this->answerRule, $this->answerDemand, $this->answerContext, 0));
        foreach ($this->plantRules as $i => $plant) {
            $queue->push(new ReservationSlot($plant, $this->plantDemands[$i], $this->plantContexts[$i], 0));
        }
    }

    /** @return \Puzzlebook2\Rules\PlantRule[] */
    public function plantRules(): array
    {
        return $this->plantRules;
    }

    /**
     * The step position at which a planted word would be crossed out -- the
     * earliest spelling rule (the final sweep included) whose predicate it
     * fails. null means no spelling rule removes it, so it would wrongly
     * survive; the planner must pick a different word.
     */
    private function plantDeathStep(string $word): ?int
    {
        foreach ($this->steps as $pos => $step) {
            $rule = $this->rules[$pos];
            if ($rule instanceof SpellingRuleBase && $rule->failsWord($word)) {
                return $pos;
            }
        }
        return null;
    }

    /**
     * Every eliminable dictionary word that starts with $letter and fits a
     * grid column -- the candidate pool for an acrostic cell, where only the
     * first letter is fixed. "Eliminable" is tested against THIS book's own
     * spelling rules, so each candidate is a legal dead word here. Seed-
     * shuffled so different cells draw different words.
     *
     * @return string[]
     */
    private function acrosticCandidates(string $letter, string $answer): array
    {
        $out = [];
        foreach ($this->dictionary->all() as $w) {
            if ($w === '' || $w[0] !== $letter) {
                continue;
            }
            $len = strlen($w);
            if ($len < 3 || $len > 6 || $w === $answer) {
                continue;
            }
            foreach ($this->steps as $pos => $step) {
                if ($pos === $this->finalPos) {
                    continue;
                }
                $rule = $this->rules[$pos];
                if ($rule instanceof SpellingRuleBase && $rule->failsWord($w)) {
                    $out[] = $w;
                    break;
                }
            }
        }
        return \Puzzlebook2\Solver\Rng::shuffle($out, $this->seed + 7919);
    }
}
