<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Generators\SingleFreeCellGenerator;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * A rule that is a pure function of a word's own spelling -- never its
 * position, never its neighbours. failsWord() is both halves at once: what
 * eliminatedCells() checks on the finished book, and exactly the shape
 * demand() uses to build words that fail this rule on purpose. One method,
 * read once, used both ways -- the two can never quietly disagree about
 * what the rule means. (Ported from v1's SpellingRule, which earned that
 * design.)
 *
 * A new pure spelling check needs nothing but failsWord() and describe().
 */
abstract class SpellingRuleBase implements Rule
{
    /** True if $word fails this rule and should be crossed out. */
    abstract public function failsWord(string $word): bool;

    abstract public function describe(): string;

    /**
     * Cells this rule's filler should never be offered -- for a rule whose
     * EVERY match would violate some standing invariant there (the only v1
     * case: DoubleEFewVowelsRule's matches all contain E, which no
     * unplanned corner may hold), so the search there is unwinnable by
     * construction, not merely unlucky. Default: none.
     *
     * @return int[]
     */
    protected function avoidedCells(BoardSpec $spec): array
    {
        return [];
    }

    public function id(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    public function ruleText(): string
    {
        return 'Cross out ' . $this->describe() . '.';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $cell = $scope->cell('cell');
        if ($keep->isKept($cell) && $this->failsWord($board->wordAt($cell))) {
            return [$cell];
        }
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        foreach ($spec->allCells() as $cell) {
            yield new Scope(['cell' => $cell]);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $pool = $ctx->makePool(fn (string $w): bool => $this->failsWord($w));
        $supply = $ctx->countSupply(fn (string $w): bool => $this->failsWord($w));
        $board = $ctx->board();
        $id = $this->id();

        return new Demand(
            label: $id,
            count: $ctx->budget(),
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator($this->avoidedCells($board->spec())),
            cellsPerScope: 1,
            rawSupplyEstimate: $supply,
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
        return 'eliminate';
    }
}
