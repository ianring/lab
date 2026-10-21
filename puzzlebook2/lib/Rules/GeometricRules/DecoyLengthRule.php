<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\PageEdges;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\SingleFreeCellGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Holds back $count extra words of exactly $length letters -- they survive
 * every step and the chain's FINAL rule is what clears them, so the book
 * doesn't end on an anticlimax. Never removes anything itself.
 *
 * kind() is 'decoy': ChainPlan maps these cells' death to the final step,
 * and gives this step's context the FULL spelling-survivor pool (its words
 * must survive everything, not just the prefix before its own position).
 */
abstract class DecoyLengthRule implements Rule
{
    use RuleIdentity;

    protected function __construct(private int $length, private int $count)
    {
    }

    public function length(): int
    {
        return $this->length;
    }

    public function describe(): string
    {
        return "{$this->count} extra words of exactly {$this->length} letters, held back for the final step to clear";
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        return [];
    }

    public function demand(ChainContext $ctx): Demand
    {
        $length = $this->length;
        $wants = static fn (string $w): bool => strlen($w) === $length;
        $pool = $ctx->makePool($wants);
        $board = $ctx->board();
        $id = $this->id();
        $spec = $board->spec();
        $interior = PageEdges::interior($spec);

        // Page-edge cells only -- same reasoning as AnswerRule: a decoy
        // outlives every step but the final one, and an edge cell is the
        // one place no capture-style rule can ever reach it. Costs nothing
        // when no capture rule is in the chain; saves the search from
        // proving ring-protection cell by cell when one is.
        return new Demand(
            label: $id,
            count: $this->count,
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator($interior),
            cellsPerScope: 1,
            rawSupplyEstimate: $ctx->countSupply($wants),
            geometricCapacity: $spec->size() - count($interior),
            domainFactory: static function (string $role, int $cell) use ($board, $pool, $id): Domain {
                return new Domain($pool, $cell, $board->constraints, $board, $id);
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
        return 'decoy';
    }

    /** Never shown to the reader (printed: false) -- exists so exports are complete. */
    public function ruleText(): string
    {
        return 'Several words are held back here on purpose, to be cleared by the final step below.';
    }
}
