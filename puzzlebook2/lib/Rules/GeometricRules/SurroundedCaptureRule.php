<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\KingAdjacency;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\CaptureProtectionConstraint;
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
 * Cross out every surviving word all 8 of whose same-page neighbours are
 * ALREADY crossed out -- a captured stone, as in Go. Page-edge cells (fewer
 * than 8 neighbours) are never captured.
 *
 * EXPERIMENTAL and mostly emergent: claims no cells (demand count 0).
 * What it DOES declare -- learned from v2's first full-scale verified
 * build, where it silently captured 7 of the 11 final survivors, the
 * answer included -- is CaptureProtectionConstraint: words that outlive
 * this step (decoys, the answer) must never end up inside an all-dead
 * ring. Anything else it captures is genuinely emergent, as v1 intended.
 */
final class SurroundedCaptureRule implements Rule
{
    use RuleIdentity;

    public function describe(): string
    {
        return 'surviving words whose 8 neighbours are all crossed out';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $center = $scope->cell('center');
        if (!$keep->isKept($center)) {
            return [];
        }
        foreach ($scope->cellsByRole as $role => $cell) {
            if ($role !== 'center' && $keep->isKept($cell)) {
                return [];
            }
        }
        return [$center];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        $king = new KingAdjacency();
        foreach ($spec->allCells() as $cell) {
            $neighbours = $king->neighboursOf($cell, $spec);
            if (count($neighbours) < 8) {
                continue;
            }
            $roles = ['center' => $cell];
            foreach ($neighbours as $i => $n) {
                $roles['n' . $i] = $n;
            }
            yield new Scope($roles);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $board = $ctx->board();
        return new Demand(
            label: $this->id(),
            count: 0,
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator(),
            cellsPerScope: 1,
            rawSupplyEstimate: PHP_INT_MAX,
            domainFactory: static fn (string $role, int $cell): Domain => new Domain(null, $cell, $board->constraints, $board),
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        yield new CaptureProtectionConstraint(
            $ctx->stepIndex(),
            $ctx->resolver(),
            $ctx->board()->spec(),
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }
}
