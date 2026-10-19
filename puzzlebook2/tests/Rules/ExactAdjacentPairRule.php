<?php

declare(strict_types=1);

namespace Puzzlebook2Tests\Rules;

use Puzzlebook2\Board\Adjacency;
use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\AdjacentPairGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;
use Puzzlebook2\Solver\Rng;

/**
 * Test-only EXACT rule: specific word pairs (role 'a' + role 'b', e.g.
 * anagram halves) chosen up front by the wordChooser, then placed on any
 * $adjacency-related pair of free cells. Exercises the solver's Exact path
 * end to end: seeded draw enumeration, pinned domains, constraint checks
 * against candidate words before any claim.
 */
final class ExactAdjacentPairRule implements Rule
{
    /** @param array<int,array{a:string,b:string}> $pairs */
    public function __construct(
        private string $id,
        private array $pairs,
        private int $count,
        private Adjacency $adjacency,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function describe(): string
    {
        return 'pre-chosen word pair on adjacent cells';
    }

    public function ruleText(): string
    {
        return $this->describe();
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        return $scope->cells();
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        foreach ($spec->allCells() as $cell) {
            foreach ($this->adjacency->neighboursOf($cell, $spec) as $n) {
                if ($n > $cell) {
                    yield new Scope(['a' => $cell, 'b' => $n]);
                }
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $pairs = $this->pairs;
        return new Demand(
            label: $this->id,
            count: $this->count,
            arity: Arity::Exact,
            generator: new AdjacentPairGenerator($this->adjacency),
            cellsPerScope: 2,
            rawSupplyEstimate: count($pairs),
            wordChooser: function (ConsumedWords $consumed, int $seed, int $draw) use ($pairs): ?array {
                $i = 0;
                foreach (Rng::shuffle($pairs, $seed) as $pair) {
                    if ($consumed->isConsumed($pair['a']) || $consumed->isConsumed($pair['b'])) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return $pair;
                    }
                }
                return null;
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        return [];
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
