<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\CallbackAdjacency;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\StandingPatternConstraint;
use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\AdjacentPairGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;
use Puzzlebook2\Solver\Rng;

/**
 * Cross out every horizontally adjacent pair of STILL-SURVIVING words,
 * same row, where the left word's last letter equals the right word's
 * first letter -- both go.
 *
 * Role 'a' is always the LEFT cell, 'b' the RIGHT: the construction
 * generator's adjacency yields only the right-hand neighbour, so 'a'/'b'
 * order IS left/right order.
 *
 * v1's hand-coded "outer neighbour collision" check (its own pair landing
 * next to an existing literal that accidentally extends the pattern) is
 * subsumed by this rule's globalConstraints() guard, which covers every
 * standing word -- literal or filler -- uniformly.
 */
final class MatchingNeighbourEndsRule implements Rule
{
    use RuleIdentity;

    /** Letters enough words both end with and begin with for a partner to be findable. */
    private const USABLE_JOIN_LETTERS = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'w',
    ];

    private const MAX_PAIRS_PER_PAGE = 1.2;

    public function describe(): string
    {
        return "side-by-side SURVIVING pairs where the left word ends on the right word's first letter";
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $left = $scope->cell('a');
        $right = $scope->cell('b');
        if (!$keep->isKept($left) || !$keep->isKept($right)) {
            return [];
        }
        if (WordTags::lastLetter($board->wordAt($left)) === WordTags::firstLetter($board->wordAt($right))) {
            return [$left, $right];
        }
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        for ($page = 0; $page < $spec->pages; $page++) {
            for ($row = 0; $row < $spec->rows; $row++) {
                for ($col = 0; $col < $spec->cols - 1; $col++) {
                    yield new Scope([
                        'a' => $spec->linearIndex($page, $row, $col),
                        'b' => $spec->linearIndex($page, $row, $col + 1),
                    ]);
                }
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $spec = $ctx->board()->spec();
        $maxPairs = (int) round($spec->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = max(0, min(intdiv($ctx->budget(), 2), $maxPairs));

        $byFirstLetter = [];
        foreach ($ctx->prefixPool() as $w) {
            $byFirstLetter[WordTags::firstLetter($w)][] = $w;
        }
        $lefts = Rng::shuffle($ctx->prefixPool(), $ctx->seed());
        $usableLefts = 0;
        foreach ($ctx->prefixPool() as $w) {
            if (in_array(WordTags::lastLetter($w), self::USABLE_JOIN_LETTERS, true)) {
                $usableLefts++;
            }
        }
        $pairSupply = intdiv($usableLefts, 2);

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new AdjacentPairGenerator(new CallbackAdjacency(
                static function (int $cell, BoardSpec $s): array {
                    [, , $col] = $s->coordsOf($cell);
                    return $col < $s->cols - 1 ? [$cell + 1] : [];
                },
                'right-hand neighbour, same row',
            )),
            cellsPerScope: 2,
            rawSupplyEstimate: $pairSupply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($lefts, $byFirstLetter): ?array {
                $i = 0;
                $spent = [];
                foreach ($lefts as $left) {
                    if ($consumed->isConsumed($left) || isset($spent[$left])) {
                        continue;
                    }
                    $joinLetter = WordTags::lastLetter($left);
                    if (!in_array($joinLetter, self::USABLE_JOIN_LETTERS, true)) {
                        continue;
                    }
                    $right = null;
                    foreach ($byFirstLetter[$joinLetter] ?? [] as $candidate) {
                        if ($candidate !== $left && !$consumed->isConsumed($candidate) && !isset($spent[$candidate])) {
                            $right = $candidate;
                            break;
                        }
                    }
                    if ($right === null) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['a' => $left, 'b' => $right];
                    }
                    $spent[$left] = true;
                    $spent[$right] = true;
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
        yield new StandingPatternConstraint(
            ownerRuleId: $this->id(),
            stepIndex: $ctx->stepIndex(),
            resolver: $ctx->resolver(),
            spec: $ctx->board()->spec(),
            scopesTouching: static function (int $cell, BoardSpec $s): iterable {
                [, , $col] = $s->coordsOf($cell);
                if ($col > 0) {
                    yield new Scope(['a' => $cell - 1, 'b' => $cell]);
                }
                if ($col < $s->cols - 1) {
                    yield new Scope(['a' => $cell, 'b' => $cell + 1]);
                }
            },
            pattern: static fn (array $words): bool => WordTags::lastLetter($words['a']) === WordTags::firstLetter($words['b']),
            label: "no unplanned standing pair may join left-end to right-start",
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
