<?php

declare(strict_types=1);

namespace Puzzlebook2\Chain;

use Puzzlebook2\Solver\BasePool;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\Rng;

/**
 * What one step's rule sees when it declares its demand() and
 * globalConstraints(): its position and cell budget, the words that
 * survive every spelling rule BEFORE it (the pool its own words come
 * from), the live Board (for pools/domains), and the StepResolver (for
 * standing-pattern constraints). The trailing parameters default so a
 * bare (seed, board) context still works for solver-level tests that
 * never touch chain semantics.
 */
final class ChainContext
{
    public function __construct(
        private int $seed,
        private Board $board,
        private int $stepIndex = 0,
        private int $budget = 0,
        private array $prefixPool = [],
        private ?StepResolver $resolver = null,
    ) {
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function board(): Board
    {
        return $this->board;
    }

    public function stepIndex(): int
    {
        return $this->stepIndex;
    }

    /** How many cells this step may claim -- from the keep_fraction cascade (ChainPlan). */
    public function budget(): int
    {
        return $this->budget;
    }

    /** @return string[] words surviving every spelling rule before this step. */
    public function prefixPool(): array
    {
        return $this->prefixPool;
    }

    public function resolver(): StepResolver
    {
        if ($this->resolver === null) {
            throw new \LogicException('This ChainContext was built without a StepResolver (bare test context).');
        }
        return $this->resolver;
    }

    /**
     * The standard way a Shaped rule builds its pool: prefix survivors
     * passing $wants, in a seed-shuffled order, floor-shared via one
     * BasePool per demand.
     *
     * @param \Closure(string): bool $wants
     */
    public function makePool(\Closure $wants): BasePool
    {
        $words = [];
        foreach ($this->prefixPool as $w) {
            if ($wants($w)) {
                $words[] = $w;
            }
        }
        return new BasePool(
            Rng::shuffle($words, $this->seed),
            $this->board->consumed,
            $this->board->trail,
        );
    }

    /** How many prefix survivors pass $wants -- the honest rawSupplyEstimate. */
    public function countSupply(\Closure $wants): int
    {
        $n = 0;
        foreach ($this->prefixPool as $w) {
            if ($wants($w)) {
                $n++;
            }
        }
        return $n;
    }
}
