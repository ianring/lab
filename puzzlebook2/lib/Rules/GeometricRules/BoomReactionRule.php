<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Dictionary\WordTags;
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
 * The trigger-reaction mechanic: the moment a word containing BOOM gets crossed
 * out (by whatever rule), it explodes, crossing out the eight words around it on
 * the same page -- up, down, left, right, and the four diagonals. A 3x3 blast.
 *
 * READING-TIME ONLY. It owns no cells of its own (demand count 0): its victims
 * are placed and crossed by other things. It reacts to the step-entry snapshot,
 * so it must sit AFTER whatever crosses the trigger (we place it last, past the
 * final sweep -- every BOOM word contains "oo" and so is already crossed by the
 * VowelRun absorber). It fires only on crossed trigger cells, so a trigger that
 * survived (were it ever the answer) never goes off.
 *
 * SAFETY (how it mixes with coupled-pair rules without stranding a pair): the
 * builder reserves each bomb's 3x3 block as owned cells and forbids stray BOOM
 * words elsewhere, so no pair word -- indeed nothing unplanned -- is ever inside
 * a blast. The guarantee is spatial (cell ownership), not timing. See the Path 1
 * / Path 2 note in SERIES_IMPLEMENTATION.md.
 */
final class BoomReactionRule implements Rule
{
    use RuleIdentity;

    private const NEEDLE = 'boom';

    public function describe(): string
    {
        return 'the 3x3 explosion a crossed-out BOOM word sets off';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $center = $scope->cell('center');
        $victim = $scope->cell('victim');
        // The bomb goes off only once its OWN word has already been crossed out,
        // and only clears neighbours that are still standing.
        if ($keep->isKept($center) || !$keep->isKept($victim)) {
            return [];
        }
        return WordTags::contains($board->wordAt($center), self::NEEDLE) ? [$victim] : [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        $size = $spec->pages * $spec->rows * $spec->cols;
        for ($cell = 0; $cell < $size; $cell++) {
            [$p, $r, $c] = $spec->coordsOf($cell);
            for ($dr = -1; $dr <= 1; $dr++) {
                for ($dc = -1; $dc <= 1; $dc++) {
                    if ($dr === 0 && $dc === 0) {
                        continue;
                    }
                    $rr = $r + $dr;
                    $cc = $c + $dc;
                    if ($rr < 0 || $rr >= $spec->rows || $cc < 0 || $cc >= $spec->cols) {
                        continue;
                    }
                    yield new Scope(['center' => $cell, 'victim' => $spec->linearIndex($p, $rr, $cc)]);
                }
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        // Claims nothing to tile: victims are placed by plants/other rules; this
        // rule only reacts at reading time. Count 0 => no reservation slots.
        return new Demand(
            label: $this->id(),
            count: 0,
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator([]),
            cellsPerScope: 1,
            rawSupplyEstimate: 0,
            // Required for a Shaped demand, but never invoked: count 0 => no slots.
            domainFactory: static fn (string $role, int $cell): Domain
                => throw new \LogicException('BoomReactionRule owns no cells.'),
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

    /** The printed instruction -- the ONE authoritative copy, kept beside the logic so text and rule cannot drift apart. */
    public function ruleText(): string
    {
        return 'Watch for any word that has the letters BOOM in it. The instant such a word gets crossed out '
            . 'by any rule, it goes off like a bomb: also cross out the eight words immediately around it on '
            . 'the same page -- up, down, left, right, and the four diagonals. (A word near an edge simply has '
            . 'fewer neighbours to take out.) Keep an eye out, because a blast can clear words no other rule '
            . 'would ever reach.';
    }
}
