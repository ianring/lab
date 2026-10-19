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
use Puzzlebook2\Solver\BasePool;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Test-only rule: claims $count adjacent free-cell pairs (per $adjacency),
 * each cell getting an independently-drawn word from $words, and registers
 * a constraint forbidding any NEIGHBOUR of either cell from carrying a word
 * sharing either word's first letter. Exists to exercise constraintsFor()
 * -> validation-at-commit -> ConstraintStore -> Domain::liveExclusions()
 * wiring, which FixedLengthRule alone never touches.
 *
 * Note the own-cell exemption in constraintsFor(): the partner cell IS a
 * neighbour of each pair cell, so the reservation's own cells must be
 * excluded from the watched set -- otherwise the constraint rejects the
 * pair's own words at validation and the rule can never place itself.
 */
final class NoSharedFirstLetterPairRule implements Rule
{
    /** @param string[] $words */
    public function __construct(
        private string $id,
        private int $count,
        private array $words,
        private Adjacency $adjacency,
        private BoardSpec $spec,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function describe(): string
    {
        return 'adjacent pair, no first-letter clash with a neighbour';
    }

    public function ruleText(): string
    {
        return $this->describe();
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        return $board->wordAt($a)[0] !== $board->wordAt($b)[0] ? [$a, $b] : [];
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
        $pool = new BasePool($this->words, $ctx->board()->consumed, $ctx->board()->trail);
        return new Demand(
            label: $this->id,
            count: $this->count,
            arity: Arity::Shaped,
            generator: new AdjacentPairGenerator($this->adjacency),
            cellsPerScope: 2,
            rawSupplyEstimate: count($this->words),
            domainFactory: function (string $role, int $cell) use ($ctx, $pool): Domain {
                return new Domain($pool, $cell, $ctx->board()->constraints, $ctx->board());
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        $forbidden = [];
        foreach ($reservation->words as $w) {
            $forbidden[$w[0]] = true;
        }

        $own = array_flip($reservation->scope->cells());
        $watched = [];
        foreach ($reservation->scope->cells() as $cell) {
            foreach ($this->adjacency->neighboursOf($cell, $this->spec) as $n) {
                if (!isset($own[$n])) {
                    $watched[$n] = true;
                }
            }
        }

        yield new NoSharedFirstLetterConstraint(array_keys($watched), array_keys($forbidden));
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
