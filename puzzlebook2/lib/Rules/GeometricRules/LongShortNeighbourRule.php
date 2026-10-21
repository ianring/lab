<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\KingAdjacency;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\StandingPatternConstraint;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\AdjacentPairGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Cross out every 9-letter word adjacent (king-move) to a 3-letter word --
 * both go; overlaps among this rule's own placements are allowed and
 * expected (clustering is the point).
 *
 * Shaped, not Exact: no SPECIFIC matching word is needed -- any 9-letter
 * and any 3-letter word qualify -- so both halves are ordinary cells with
 * full dictionary fallback (role 'a' draws from the 9-letter pool, role
 * 'b' from the 3-letter pool; this is why Demand's domainFactory receives
 * the role). The globalConstraints() guard prevents an unplanned standing
 * (9,3) adjacency -- v1's NoLengthAdjacentToOpposingLiteral, generalized
 * to protect every standing word rather than only literals.
 */
final class LongShortNeighbourRule implements Rule, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    private const LONG = 9;
    private const SHORT = 3;
    private const MAX_PAIRS_PER_PAGE = 1.2;

    public function describe(): string
    {
        return self::LONG . '-letter words adjacent (any direction) to a ' . self::SHORT . '-letter word';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        $la = strlen($board->wordAt($a));
        $lb = strlen($board->wordAt($b));
        if (($la === self::LONG && $lb === self::SHORT) || ($la === self::SHORT && $lb === self::LONG)) {
            return [$a, $b];
        }
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        $king = new KingAdjacency();
        foreach ($spec->allCells() as $cell) {
            foreach ($king->neighboursOf($cell, $spec) as $n) {
                if ($n > $cell) {
                    yield new Scope(['a' => $cell, 'b' => $n]);
                }
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $spec = $ctx->board()->spec();
        $maxPairs = (int) round($spec->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = max(0, min(intdiv($ctx->budget(), 2), $maxPairs));

        $isLong = static fn (string $w): bool => strlen($w) === self::LONG;
        $isShort = static fn (string $w): bool => strlen($w) === self::SHORT;
        $longPool = $ctx->makePool($isLong);
        $shortPool = $ctx->makePool($isShort);
        $supply = min($ctx->countSupply($isLong), $ctx->countSupply($isShort));

        $board = $ctx->board();
        $id = $this->id();

        return new Demand(
            label: $id,
            count: $pairsWanted,
            arity: Arity::Shaped,
            generator: new AdjacentPairGenerator(new KingAdjacency()),
            cellsPerScope: 2,
            rawSupplyEstimate: $supply,
            domainFactory: static function (string $role, int $cell) use ($board, $longPool, $shortPool, $id): Domain {
                return new Domain($role === 'a' ? $longPool : $shortPool, $cell, $board->constraints, $board, $id);
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        $king = new KingAdjacency();

        yield new StandingPatternConstraint(
            ownerRuleId: $this->id(),
            stepIndex: $ctx->stepIndex(),
            resolver: $ctx->resolver(),
            spec: $ctx->board()->spec(),
            scopesTouching: static function (int $cell, BoardSpec $s) use ($king): iterable {
                foreach ($king->neighboursOf($cell, $s) as $n) {
                    yield new Scope(['a' => $cell, 'b' => $n]);
                }
            },
            pattern: static function (array $words): bool {
                $la = strlen($words['a']);
                $lb = strlen($words['b']);
                return ($la === self::LONG && $lb === self::SHORT) || ($la === self::SHORT && $lb === self::LONG);
            },
            label: 'no unplanned standing (' . self::LONG . ',' . self::SHORT . ')-letter adjacency',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Look for a 9-letter word that is adjacent (up, down, left, right, or diagonal) to a 3-letter word. Cross out both. If a word has more than one qualifying neighbour, all of them are crossed out, not just one pair.';
    }
}
