<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\StandingPatternConstraint;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\SamePageFreePairGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;
use Puzzlebook2\Solver\Rng;

/**
 * Archetype for "cross out both words of any still-standing pair on the same
 * page that are RELATED" -- anagrams, reversals, a word and its beheading, etc.
 * The whole family differed only in two things, so a concrete rule supplies only
 * those: matches() (does a standing pair qualify) and candidateGroups() (how the
 * plantable pairs are drawn from the prefix survivors). Everything else -- the
 * same-page pair geometry, the Exact-arity demand that draws two unconsumed
 * words from one group, the pair-count cap, and the StandingPatternConstraint
 * that forbids an UNPLANNED matching pair from sharing a page -- lives here once.
 *
 * candidateGroups() returns GROUPS, not fixed pairs, so a group of three mutual
 * anagrams still yields a pair after one of its words is consumed; a rule whose
 * pairs are inherently 1:1 (reversals, beheadings) just returns 2-element groups.
 */
abstract class PairOnPageRule implements \Puzzlebook2\Rules\Rule, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    /** Does this still-standing pair qualify? Symmetric; includes any length test. */
    abstract protected function matches(string $a, string $b): bool;

    /**
     * Groups of mutually-pairable prefix survivors, shuffled -- any two words in
     * a group form a legal pair. The demand draws two unconsumed words from one
     * group per pair. Most rules return 2-element groups; anagrams return whole
     * signature groups.
     *
     * @param string[] $prefixPool
     * @return array<int, string[]>
     */
    abstract protected function candidateGroups(array $prefixPool, int $seed): array;

    /** Pairs this rule may plant per page (cap); the cascade budget still bounds it too. */
    protected function maxPairsPerPage(): float
    {
        return 1.0;
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        return $this->matches($board->wordAt($a), $board->wordAt($b)) ? [$a, $b] : [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        $perPage = $spec->rows * $spec->cols;
        for ($page = 0; $page < $spec->pages; $page++) {
            $start = $page * $perPage;
            for ($i = 0; $i < $perPage; $i++) {
                for ($j = $i + 1; $j < $perPage; $j++) {
                    yield new Scope(['a' => $start + $i, 'b' => $start + $j]);
                }
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $spec = $ctx->board()->spec();
        $maxPairs = (int) round($spec->pages * $this->maxPairsPerPage());
        $pairsWanted = max(0, min(intdiv($ctx->budget(), 2), $maxPairs));

        $groups = $this->candidateGroups($ctx->prefixPool(), $ctx->seed());
        $supply = 0;
        foreach ($groups as $g) {
            $supply += intdiv(count($g), 2);
        }

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new SamePageFreePairGenerator(),
            cellsPerScope: 2,
            rawSupplyEstimate: $supply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($groups): ?array {
                $i = 0;
                foreach ($groups as $group) {
                    $available = [];
                    foreach ($group as $w) {
                        if (!$consumed->isConsumed($w)) {
                            $available[] = $w;
                            if (count($available) === 2) {
                                break;
                            }
                        }
                    }
                    if (count($available) < 2) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['a' => $available[0], 'b' => $available[1]];
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
        $matches = $this->matches(...);
        yield new StandingPatternConstraint(
            ownerRuleId: $this->id(),
            stepIndex: $ctx->stepIndex(),
            resolver: $ctx->resolver(),
            spec: $ctx->board()->spec(),
            scopesTouching: static function (int $cell, BoardSpec $s): iterable {
                $perPage = $s->rows * $s->cols;
                $start = intdiv($cell, $perPage) * $perPage;
                for ($c = $start; $c < $start + $perPage; $c++) {
                    if ($c !== $cell) {
                        yield new Scope(['a' => $cell, 'b' => $c]);
                    }
                }
            },
            pattern: static fn (array $words): bool => $matches($words['a'], $words['b']),
            label: $this->describe() . ' -- no unplanned standing pair may share a page',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
