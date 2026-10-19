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
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;
use Puzzlebook2\Solver\Rng;

/**
 * Cross out both words of any still-surviving pair on the same page that
 * are anagrams of each other (4+ letters) -- LISTEN/SILENT, DUSTY/STUDY.
 *
 * CONSTRUCTION. Exact arity: an actual anagram pair is drawn from the
 * prefix survivors' signature groups, then any two free cells on one page
 * are found for it. The accidental-anagram hazard (two unrelated standing
 * words on one page happening to be anagrams, v1's cross-rule collision
 * incident) is the globalConstraints() guard.
 */
final class AnagramPairOnPageRule implements Rule
{
    use RuleIdentity;

    private const MIN_LENGTH = 4;
    private const MAX_PAIRS_PER_PAGE = 1.2;

    /** @var array<string,string> memoized word => signature (shared; signatures are pure) */
    private static array $sigCache = [];

    public static function signatureOf(string $word): ?string
    {
        if (strlen($word) < self::MIN_LENGTH) {
            return null;
        }
        if (!isset(self::$sigCache[$word])) {
            $letters = str_split($word);
            sort($letters);
            self::$sigCache[$word] = implode('', $letters);
        }
        return self::$sigCache[$word];
    }

    public function describe(): string
    {
        return 'pairs of anagrams on the same page (' . self::MIN_LENGTH . '+ letters)';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        $sa = self::signatureOf($board->wordAt($a));
        $sb = self::signatureOf($board->wordAt($b));
        return ($sa !== null && $sa === $sb) ? [$a, $b] : [];
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
        $maxPairs = (int) round($spec->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = max(0, min(intdiv($ctx->budget(), 2), $maxPairs));

        $groups = [];
        foreach ($ctx->prefixPool() as $w) {
            $sig = self::signatureOf($w);
            if ($sig !== null) {
                $groups[$sig][] = $w;
            }
        }
        $groups = array_filter($groups, static fn (array $ws): bool => count($ws) > 1);
        $groupKeys = Rng::shuffle(array_keys($groups), $ctx->seed());
        $pairSupply = 0;
        foreach ($groups as $ws) {
            $pairSupply += intdiv(count($ws), 2);
        }

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new SamePageFreePairGenerator(),
            cellsPerScope: 2,
            rawSupplyEstimate: $pairSupply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($groupKeys, $groups): ?array {
                $i = 0;
                foreach ($groupKeys as $sig) {
                    $available = [];
                    foreach ($groups[$sig] as $w) {
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
            pattern: static function (array $words): bool {
                $sa = self::signatureOf($words['a']);
                return $sa !== null && $sa === self::signatureOf($words['b']);
            },
            label: 'no unplanned standing anagram pair may share a page',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
