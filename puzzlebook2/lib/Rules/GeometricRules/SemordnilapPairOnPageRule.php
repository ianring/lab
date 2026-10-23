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
 * word is the other spelled backwards (4+ letters) -- STRAW/WARTS, DIAPER/
 * REPAID, LIVED/DEVIL. A "semordnilap" ("palindromes" backwards): a word that
 * reads as a DIFFERENT word reversed.
 *
 * CONSTRUCTION. A direct sibling of AnagramPairOnPageRule -- Exact arity, an
 * actual reversible pair drawn from the prefix survivors, then any two free
 * cells on one page. The accidental-reversal hazard (two unrelated standing
 * words on one page that happen to be reverses) is the globalConstraints()
 * guard.
 *
 * INCOMPATIBLE with AnagramPairOnPageRule: every reversal pair is also an
 * anagram pair (same letters), so the two rules would police each other's
 * plants and must never share a book. Hand-declared below (the dictionary
 * subsumption tool only sees word-by-word spelling rules, not pair rules).
 */
final class SemordnilapPairOnPageRule implements Rule, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    /** @see \Puzzlebook2\Chain\ChainPlan incompatibility check. */
    public const INCOMPATIBLE_WITH = ['AnagramPairOnPageRule'];

    private const MIN_LENGTH = 4;
    private const MAX_PAIRS_PER_PAGE = 1.0;

    public function describe(): string
    {
        return 'pairs of words on the same page where one is the other reversed (' . self::MIN_LENGTH . '+ letters)';
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
        return (strlen($wa) >= self::MIN_LENGTH && $wa !== $wb && $wb === strrev($wa)) ? [$a, $b] : [];
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

        // Every reversible pair among the prefix survivors, each listed once.
        $inPool = [];
        foreach ($ctx->prefixPool() as $w) {
            if (strlen($w) >= self::MIN_LENGTH) {
                $inPool[$w] = true;
            }
        }
        $pairs = [];
        foreach ($inPool as $w => $_) {
            $r = strrev($w);
            if ($r !== $w && $w < $r && isset($inPool[$r])) {
                $pairs[] = [$w, $r];
            }
        }
        $pairs = Rng::shuffle($pairs, $ctx->seed());
        $pairSupply = count($pairs);

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new SamePageFreePairGenerator(),
            cellsPerScope: 2,
            rawSupplyEstimate: $pairSupply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($pairs): ?array {
                $i = 0;
                foreach ($pairs as [$w, $r]) {
                    if ($consumed->isConsumed($w) || $consumed->isConsumed($r)) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['a' => $w, 'b' => $r];
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
                $a = $words['a'];
                return strlen($a) >= self::MIN_LENGTH && $a !== $words['b'] && $words['b'] === strrev($a);
            },
            label: 'no unplanned standing reversal pair may share a page',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside the logic so text and rule cannot drift apart. */
    public function ruleText(): string
    {
        return 'On every page, look for a pair of words, both still standing, where one word spelled backwards '
            . 'is exactly the other word (STRAW and WARTS, or DIAPER and REPAID). Cross out both words of the pair.';
    }
}
