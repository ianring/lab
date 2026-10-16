<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;

/**
 * The book-in-progress that rules write their construction decisions into:
 * which cell each rule is going to eliminate, and what shape that cell's
 * word must be. No actual words yet -- this is purely the plan.
 *
 * Rules interact with the book ONLY through this object. That's what keeps a
 * rule's construction logic inside the rule class: it asks the canvas what's
 * still free, claims what it needs, and says what shape of word belongs
 * there. It never touches the builder's internals, and the builder never
 * needs to know what the rule was doing.
 *
 * Two kinds of claim:
 *
 *   claimDoomed()   -- a cell eliminated BEFORE the reveal is read. Ordinary
 *                      filler. The reveal never counts it.
 *   claimSurvivor() -- a cell still standing WHEN the reveal is read, which
 *                      the reveal therefore counts. Scarce and load-bearing,
 *                      so claiming one is checked against the cells the
 *                      builder has forbidden (the answer's neighbourhood).
 */
final class Canvas
{
    private Grid $grid;
    private int $size;

    /** @var array<int,CellPlan|null> */
    private array $plans;

    /** @var array<int,int|null> cell => id of the step that eliminates it (null = nothing does) */
    private array $killedBy;

    /** @var array<int,bool> cells still standing when the reveal is read */
    private array $survivors = [];

    /** @var array<int,bool> cells that must never become survivors */
    private array $survivorForbidden = [];

    /** @var array<int,bool> survivor cells whose word is incidental and may be swapped out */
    private array $replaceable = [];

    /** @var FillInvariant[] conditions spanning many cells, enforced while words are chosen */
    private array $invariants = [];

    public function __construct(Grid $grid)
    {
        $this->grid = $grid;
        $this->size = $grid->size();
        $this->plans = array_fill(0, $this->size, null);
        $this->killedBy = array_fill(0, $this->size, null);
    }

    public function grid(): Grid
    {
        return $this->grid;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** True if nothing has claimed this cell yet. */
    public function isFree(int $cell): bool
    {
        return $this->plans[$cell] === null;
    }

    /** @return int[] every unclaimed cell, in reading order. */
    public function freeCells(): array
    {
        $free = [];
        for ($i = 0; $i < $this->size; $i++) {
            if ($this->plans[$i] === null) {
                $free[] = $i;
            }
        }
        return $free;
    }

    /** @return int[] every unclaimed cell, in a deterministic shuffled order. */
    public function freeCellsShuffled(int $seed): array
    {
        return Rng::shuffle($this->freeCells(), $seed);
    }

    /**
     * Side-by-side cell pairs where BOTH halves are still free -- for rules that
     * need two adjacent cells they can control together.
     *
     * Only non-overlapping pairs are offered (columns 0-1, 2-3, 4-5 of a row), so
     * claiming one can never strand the other half of another pair.
     *
     * @return array<array{0:int,1:int}>
     */
    public function freeHorizontalPairs(int $seed): array
    {
        $pairs = [];
        for ($page = 0; $page < $this->grid->pages; $page++) {
            for ($row = 0; $row < $this->grid->rows; $row++) {
                for ($col = 0; $col + 1 < $this->grid->cols; $col += 2) {
                    $left = $this->grid->linearIndex($page, $row, $col);
                    $right = $this->grid->linearIndex($page, $row, $col + 1);
                    if ($this->plans[$left] === null && $this->plans[$right] === null) {
                        $pairs[] = [$left, $right];
                    }
                }
            }
        }
        return Rng::shuffle($pairs, $seed);
    }

    // ------------------------------------------------------------ survivor bookkeeping

    /** Mark a cell as one that must never be left standing when the reveal is read. */
    public function forbidSurvivor(int $cell): void
    {
        $this->survivorForbidden[$cell] = true;
    }

    public function mayBeSurvivor(int $cell): bool
    {
        return !isset($this->survivorForbidden[$cell]);
    }

    public function survivorCount(): int
    {
        return count($this->survivors);
    }

    /** @return int[] the cells still standing when the reveal is read, in reading order. */
    public function survivors(): array
    {
        $cells = array_keys($this->survivors);
        sort($cells);
        return $cells;
    }

    public function isSurvivor(int $cell): bool
    {
        return isset($this->survivors[$cell]);
    }

    // ------------------------------------------------------------------- claiming

    /** Claim a cell that is eliminated before the reveal is read. */
    public function claimDoomed(int $cell, int $killedByStepId, CellPlan $plan): void
    {
        $this->assertFree($cell, $killedByStepId);
        $this->plans[$cell] = $plan;
        $this->killedBy[$cell] = $killedByStepId;
    }

    /**
     * Claim a cell that is still standing when the reveal is read.
     *
     * $killedByStepId is the step that eventually removes it, or null for the one
     * word that is never removed at all.
     *
     * $replaceable says whether this cell's actual word is incidental. A cell the
     * rule needs a specific shape in -- a launch pad the rule fires from -- is NOT
     * replaceable, and swapping its word would break the rule. A cell that merely
     * happens to be standing here IS replaceable, and the reveal is free to put one
     * of its words there. Rules declare this; nothing else has to understand why.
     */
    public function claimSurvivor(int $cell, ?int $killedByStepId, CellPlan $plan, bool $replaceable = false): void
    {
        $this->assertFree($cell, $killedByStepId);
        if (!$this->mayBeSurvivor($cell)) {
            throw new ConstructionRetry(
                "cell {$cell} can't be left standing -- it's inside a zone the build reserved"
            );
        }
        $this->plans[$cell] = $plan;
        $this->killedBy[$cell] = $killedByStepId;
        $this->survivors[$cell] = true;
        if ($replaceable) {
            $this->replaceable[$cell] = true;
        }
    }

    /** True if this survivor's word is incidental and the reveal may swap it out. */
    public function isReplaceable(int $cell): bool
    {
        return isset($this->replaceable[$cell]);
    }

    /**
     * Swap in a different plan for a cell that's already claimed, keeping its
     * survivor status and which step kills it. Used to drop the revealed words into
     * the survivor slots the reveal will read.
     */
    public function rewritePlan(int $cell, CellPlan $plan): void
    {
        if ($this->plans[$cell] === null) {
            throw new \LogicException("Cell {$cell} has no plan to rewrite.");
        }
        $this->plans[$cell] = $plan;
    }

    public function planFor(int $cell): ?CellPlan
    {
        return $this->plans[$cell];
    }

    /**
     * Register a condition spanning many cells, for a rule that fires on coincidences
     * between words rather than on any one word's own letters. See FillInvariant.
     */
    public function addInvariant(FillInvariant $invariant): void
    {
        $this->invariants[] = $invariant;
    }

    /** @return FillInvariant[] */
    public function invariants(): array
    {
        return $this->invariants;
    }

    /**
     * How many cells each step actually ends up eliminating, keyed by step id.
     *
     * Worth having separately from the budgets: a rule whose reach is derived from its
     * own geometry has no budget at all, and a rule that claims cells in groups may not
     * spend its budget exactly. This is what really happened.
     *
     * @return array<int,int> step id => cells claimed
     */
    public function claimsByStep(): array
    {
        $counts = [];
        foreach ($this->killedBy as $stepId) {
            if ($stepId === null) {
                continue;
            }
            $counts[$stepId] = ($counts[$stepId] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Every distinct kind of word this layout asks for, with how many cells want it
     * and which step asked -- grouped by the plans' labels.
     *
     * This is what makes bin/check.php able to report on EVERY rule rather than only
     * the spelling ones: instead of keeping its own idea of what each rule needs, it
     * reads the demands the rules actually declared while laying out their cells. A
     * rule with a word-shape requirement can't be left out of the check, because the
     * requirement is right here in the plan it wrote.
     *
     * @return array<string, array{cells: int, plan: CellPlan, steps: int[]}>
     */
    public function demandsByLabel(): array
    {
        $demands = [];
        foreach ($this->plans as $cell => $plan) {
            if ($plan === null) {
                continue;
            }
            $label = $plan->label();
            if (!isset($demands[$label])) {
                $demands[$label] = ['cells' => 0, 'plan' => $plan, 'steps' => []];
            }
            $demands[$label]['cells']++;
            $step = $this->killedBy[$cell];
            if ($step !== null && !in_array($step, $demands[$label]['steps'], true)) {
                $demands[$label]['steps'][] = $step;
            }
        }
        foreach ($demands as $label => $_) {
            sort($demands[$label]['steps']);
        }
        return $demands;
    }

    public function killedByFor(int $cell): ?int
    {
        return $this->killedBy[$cell];
    }

    private function assertFree(int $cell, ?int $stepId): void
    {
        if ($cell < 0 || $cell >= $this->size) {
            throw new \LogicException("Cell {$cell} is outside the grid.");
        }
        if ($this->plans[$cell] !== null) {
            throw new ConstructionRetry(
                "step " . ($stepId ?? '?') . " tried to claim cell {$cell}, which is already taken"
            );
        }
    }
}
