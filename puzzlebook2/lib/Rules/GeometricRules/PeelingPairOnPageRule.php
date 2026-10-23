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
 * Cross out both words of any still-surviving pair on the same page where one
 * word is the other with BOTH its first and last letters removed (PLANET/LANE,
 * SHARES/HARE).
 *
 * The accessible, pair-form of the "peelable" idea -- the reader spots two REAL
 * words related that way, never has to judge a non-word. A sibling of
 * AnagramPairOnPageRule. INCOMPATIBLE with BeheadingPairOnPageRule: the two
 * edge-stripping relations are too alike to share a book (Ian's call).
 */
final class PeelingPairOnPageRule implements Rule, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    public const INCOMPATIBLE_WITH = ['BeheadingPairOnPageRule'];

    /** The shorter word must be at least this long, so the longer is 5+. */
    private const MIN_SHORT = 3;

    public function describe(): string
    {
        return 'pairs on one page where one word is the other with its first and last letters removed';
    }

    private static function peels(string $long, string $short): bool
    {
        return strlen($short) >= self::MIN_SHORT && strlen($long) === strlen($short) + 2 && substr($long, 1, -1) === $short;
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        $wa = $board->wordAt($a);
        $wb = $board->wordAt($b);
        return (self::peels($wa, $wb) || self::peels($wb, $wa)) ? [$a, $b] : [];
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
        $pairsWanted = max(0, min(intdiv($ctx->budget(), 2), $spec->pages));

        $inPool = [];
        foreach ($ctx->prefixPool() as $w) {
            $inPool[$w] = true;
        }
        $pairs = [];
        foreach ($inPool as $w => $_) {
            $short = substr($w, 1, -1);
            if (self::peels($w, $short) && isset($inPool[$short])) {
                $pairs[] = [$w, $short];
            }
        }
        $pairs = Rng::shuffle($pairs, $ctx->seed());

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new SamePageFreePairGenerator(),
            cellsPerScope: 2,
            rawSupplyEstimate: count($pairs),
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($pairs): ?array {
                $i = 0;
                foreach ($pairs as [$long, $short]) {
                    if ($consumed->isConsumed($long) || $consumed->isConsumed($short)) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['a' => $long, 'b' => $short];
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
                return self::peels($words['a'], $words['b']) || self::peels($words['b'], $words['a']);
            },
            label: 'no unplanned standing peeling pair may share a page',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside the logic so text and rule cannot drift apart. */
    public function ruleText(): string
    {
        return 'On every page, find a pair of words, both still standing, where one word is exactly the other '
            . 'with BOTH its first and last letters taken off (PLANET and LANE, or SHARES and HARE). Cross out both.';
    }
}
