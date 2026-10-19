<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\VerticalAdjacency;
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
 * Cross out every vertically adjacent pair of STILL-SURVIVING words, same
 * column, where both words end in the same letter -- both go.
 *
 * CONSTRUCTION. Exact arity: a genuine pair of real words sharing a last
 * letter is chosen up front (both surviving the prefix, neither consumed),
 * then geometry is found for it -- v1's hard-won "reserve the words, then
 * find cells" pattern. The accidental-pair hazard (two words that both
 * survive to this step landing vertically adjacent with matching endings,
 * crossing out cells some later step budgeted for -- v1's
 * NoUnplannedVerticalEndingMatch) is this rule's globalConstraints()
 * StandingPatternConstraint.
 */
final class SameEndingVerticalPairRule implements Rule
{
    use RuleIdentity;

    /** Letters common enough as word-ENDINGS that a partner is findable. */
    private const USABLE_ENDING_LETTERS = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'k', 'l', 'm',
        'n', 'o', 'p', 'r', 's', 't', 'w', 'y',
    ];

    /** Pair density stays proportional to book size -- v1's calibration, kept. */
    private const MAX_PAIRS_PER_PAGE = 1.2;

    public function describe(): string
    {
        return 'vertically adjacent SURVIVING pairs ending in the same letter';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $a = $scope->cell('a');
        $b = $scope->cell('b');
        if (!$keep->isKept($a) || !$keep->isKept($b)) {
            return [];
        }
        if (WordTags::lastLetter($board->wordAt($a)) === WordTags::lastLetter($board->wordAt($b))) {
            return [$a, $b];
        }
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        for ($page = 0; $page < $spec->pages; $page++) {
            for ($col = 0; $col < $spec->cols; $col++) {
                for ($row = 0; $row < $spec->rows - 1; $row++) {
                    yield new Scope([
                        'a' => $spec->linearIndex($page, $row, $col),
                        'b' => $spec->linearIndex($page, $row + 1, $col),
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

        $byLastLetter = [];
        foreach ($ctx->prefixPool() as $w) {
            $byLastLetter[WordTags::lastLetter($w)][] = $w;
        }
        $tops = Rng::shuffle($ctx->prefixPool(), $ctx->seed());
        $pairSupply = 0;
        foreach (self::USABLE_ENDING_LETTERS as $letter) {
            $pairSupply += intdiv(count($byLastLetter[$letter] ?? []), 2);
        }

        return new Demand(
            label: $this->id(),
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new AdjacentPairGenerator(new VerticalAdjacency()),
            cellsPerScope: 2,
            rawSupplyEstimate: $pairSupply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($tops, $byLastLetter): ?array {
                $i = 0;
                $takenTops = [];
                foreach ($tops as $top) {
                    if ($consumed->isConsumed($top) || isset($takenTops[$top])) {
                        continue;
                    }
                    $letter = WordTags::lastLetter($top);
                    if (!in_array($letter, self::USABLE_ENDING_LETTERS, true)) {
                        continue;
                    }
                    $bottom = null;
                    foreach ($byLastLetter[$letter] ?? [] as $candidate) {
                        if ($candidate !== $top && !$consumed->isConsumed($candidate)) {
                            $bottom = $candidate;
                            break;
                        }
                    }
                    if ($bottom === null) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['a' => $top, 'b' => $bottom];
                    }
                    // Enumerated-but-skipped draws must not re-offer the same
                    // words, or two draws could alias; mark the top as spent
                    // for this enumeration pass.
                    $takenTops[$top] = true;
                    $takenTops[$bottom] = true;
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
        $spec = $ctx->board()->spec();
        $vertical = new VerticalAdjacency();

        yield new StandingPatternConstraint(
            ownerRuleId: $this->id(),
            stepIndex: $ctx->stepIndex(),
            resolver: $ctx->resolver(),
            spec: $spec,
            scopesTouching: static function (int $cell, BoardSpec $s) use ($vertical): iterable {
                foreach ($vertical->neighboursOf($cell, $s) as $n) {
                    yield new Scope(['a' => $cell, 'b' => $n]);
                }
            },
            pattern: static fn (array $words): bool => WordTags::lastLetter($words['a']) === WordTags::lastLetter($words['b']),
            label: 'no unplanned vertical pair of standing words may share a last letter',
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
