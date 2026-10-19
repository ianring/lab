<?php

declare(strict_types=1);

namespace Puzzlebook2Tests\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\SingleFreeCellGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\BasePool;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Test-only rule: claims exactly $count still-free cells, each with a word
 * of exactly $length letters drawn from $words. The simplest possible
 * Shaped rule -- no constraintsFor() -- used to exercise ordinary
 * BasePool-backed domain assignment end to end.
 */
final class FixedLengthRule implements Rule
{
    /** @param string[] $words */
    public function __construct(
        private string $id,
        private int $length,
        private int $count,
        private array $words,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function describe(): string
    {
        return "words exactly {$this->length} letters long";
    }

    public function ruleText(): string
    {
        return $this->describe();
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $cell = $scope->cell('cell');
        return $keep->isKept($cell) && strlen($board->wordAt($cell)) === $this->length ? [$cell] : [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        foreach ($spec->allCells() as $cell) {
            yield new Scope(['cell' => $cell]);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $pool = new BasePool($this->words, $ctx->board()->consumed, $ctx->board()->trail);
        return new Demand(
            label: $this->id,
            count: $this->count,
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator(),
            cellsPerScope: 1,
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
