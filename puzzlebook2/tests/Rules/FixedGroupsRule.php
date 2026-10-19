<?php

declare(strict_types=1);

namespace Puzzlebook2Tests\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\FixedGroupGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\BasePool;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Test-only rule: claims $count groups out of a fixed candidate list (each
 * group a role=>cell map known in advance, e.g. "cells 0 and 3 together"),
 * with each role's word drawn independently from $words. Used to force
 * deterministic cell-level collisions with another rule, exercising real
 * decision-stack backtracking. All groups must be the same size.
 */
final class FixedGroupsRule implements Rule
{
    /**
     * @param array<int,array<string,int>> $groups
     * @param string[] $words
     */
    public function __construct(
        private string $id,
        private array $groups,
        private int $count,
        private array $words,
    ) {
        if ($groups === []) {
            throw new \InvalidArgumentException("FixedGroupsRule '{$id}' needs at least one group.");
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function describe(): string
    {
        return "fixed groups ({$this->id})";
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
        foreach ($this->groups as $cellsByRole) {
            yield new Scope($cellsByRole);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $pool = new BasePool($this->words, $ctx->board()->consumed, $ctx->board()->trail);
        return new Demand(
            label: $this->id,
            count: $this->count,
            arity: Arity::Shaped,
            generator: new FixedGroupGenerator($this->groups),
            cellsPerScope: count($this->groups[array_key_first($this->groups)]),
            rawSupplyEstimate: count($this->words),
            domainFactory: function (string $role, int $cell) use ($ctx, $pool): Domain {
                return new Domain($pool, $cell, $ctx->board()->constraints, $ctx->board());
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
