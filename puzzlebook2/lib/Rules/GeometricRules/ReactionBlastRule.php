<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\StandingPatternConstraint;
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
 * Triggered blast family: a STANDING rule, declared first in the book -- "the
 * instant a word containing <needle> gets crossed out, by any rule and no
 * matter when, it detonates, also crossing out a fixed geometric FOOTPRINT
 * around it on the same page." The player reads it up front and watches for it
 * throughout; it is not a step performed at one point in the sequence. Same
 * shape as BoomReactionRule, generalised to any (needle, footprint).
 *
 * Each subclass is a needle plus a footprint, and the needle PUNS the shape:
 * POP is the four orthogonal neighbours, POW the diagonal four, BANG a big
 * orthogonal cross, BEAM the whole row, RING the radius-2 shell, DIAG the full
 * diagonal rays. Pure geometry + a substring test -- hand-checkable with no
 * knowledge of meaning.
 *
 * CONSTRUCTION. Like BoomReactionRule this owns no demand cells (count 0): the
 * series planter plants the trigger words and reserves their blast footprints,
 * and globalConstraints() forbids any UNPLANNED standing needle word so no
 * stray trigger blasts an unowned cell. Until that planter support exists a
 * blast rule is inert in a composed book (nothing plants a trigger); its
 * elimination logic is exercised directly by the smoke tests.
 */
abstract class ReactionBlastRule implements \Puzzlebook2\Rules\Reactive, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    /** The substring that marks a trigger word (lowercase). */
    abstract protected function needle(): string;

    /** The trigger needle, for the series planter (which plants the triggers). */
    public function triggerNeedle(): string
    {
        return $this->needle();
    }

    /**
     * The blast footprint as [dr, dc] offsets, for the planter to reserve.
     * @return array<int,array{0:int,1:int}>
     */
    public function footprintOffsets(): array
    {
        return $this->blastOffsets();
    }

    /**
     * Footprint as [dr, dc] offsets from the trigger cell, within the same page.
     * Off-page offsets are silently clipped, so a rule may over-generate (e.g.
     * a full-row or full-diagonal reach) and let the page bounds trim it.
     *
     * @return array<int,array{0:int,1:int}>
     */
    abstract protected function blastOffsets(): array;

    public function reactCells(FilledBoard $board, KeepMask $keep): array
    {
        $spec = $board->spec();
        $needle = $this->needle();
        $offsets = $this->blastOffsets();
        $kill = [];
        foreach ($spec->allCells() as $cell) {
            // Only an already-crossed trigger detonates; skip everything else.
            if ($keep->isKept($cell) || !WordTags::contains($board->wordAt($cell), $needle)) {
                continue;
            }
            [$p, $r, $c] = $spec->coordsOf($cell);
            foreach ($offsets as [$dr, $dc]) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                if ($rr < 0 || $rr >= $spec->rows || $cc < 0 || $cc >= $spec->cols) {
                    continue;
                }
                $victim = $spec->linearIndex($p, $rr, $cc);
                if ($keep->isKept($victim)) {
                    $kill[$victim] = true;
                }
            }
        }
        return array_keys($kill);
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $center = $scope->cell('center');
        $victim = $scope->cell('victim');
        // A triggered rule SLEEPS until its trigger word is crossed out -- by any
        // rule, at any point -- then detonates. So it fires only once the trigger
        // cell is struck; the word is read from the immutable board, so the
        // trigger stays recognisable after it is gone.
        if ($keep->isKept($center) || !WordTags::contains($board->wordAt($center), $this->needle())) {
            return [];
        }
        return $keep->isKept($victim) ? [$victim] : [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        foreach ($spec->allCells() as $cell) {
            [$p, $r, $c] = $spec->coordsOf($cell);
            foreach ($this->blastOffsets() as [$dr, $dc]) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                if ($rr < 0 || $rr >= $spec->rows || $cc < 0 || $cc >= $spec->cols) {
                    continue;
                }
                yield new Scope(['center' => $cell, 'victim' => $spec->linearIndex($p, $rr, $cc)]);
            }
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        // Owns nothing to tile: the planter plants triggers and reserves their
        // footprints. Count 0 => no reservation slots (mirrors BoomReactionRule).
        return new Demand(
            label: $this->id(),
            count: 0,
            arity: Arity::Shaped,
            generator: new SingleFreeCellGenerator([]),
            cellsPerScope: 1,
            rawSupplyEstimate: 0,
            domainFactory: static fn (string $role, int $cell): Domain
                => throw new \LogicException('ReactionBlastRule owns no cells.'),
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        // No standing-pattern guard (as BoomReactionRule also had none): the
        // series builds a reaction book from a dictionary with this needle
        // removed, so the ONLY trigger words are the ones the planter plants.
        // A board-wide "no unplanned needle word" constraint would instead REJECT
        // those planted triggers -- they are owned by a PlantRule, not by this
        // rule, so the constraint would see them as unplanned and refuse them.
        return [];
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    public function describe(): string
    {
        return "'" . $this->needle() . "' trigger words and the words they blast";
    }
}
