<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Marks a rule as a REACTION: a standing instruction, declared first in the
 * book, that does not occupy a position in the sequential solve. Instead it
 * fires the instant its trigger is crossed out -- by any rule, at any point --
 * no matter when that happens.
 *
 * The replay/verify path (ChainRunner) and the preview run every reaction to a
 * fixpoint AFTER each ordinary step (Engine::detonate), so a reaction crosses
 * its footprint exactly when the step that crosses its trigger runs, while the
 * footprint is still standing. Because a reaction only fires on an
 * already-crossed trigger and only clears still-standing victims, re-running it
 * every step is idempotent; the fixpoint loop additionally lets one blast set
 * off another (a chain reaction).
 *
 * A reaction owns no sequential cells (its demand count is 0); the planter plants
 * its triggers and reserves its footprints, and its globalConstraints() forbid
 * any UNPLANNED trigger word so it can only ever fire where planned.
 */
interface Reactive extends Rule
{
    /**
     * The cells this reaction crosses right now: scan the ALREADY-crossed
     * trigger words and return their still-standing footprint cells. Called by
     * Engine::detonate after each step. This is the fast equivalent of
     * apply()/allScopes()/eliminatedCells() for a reaction -- O(cells +
     * triggers x footprint) rather than O(cells x footprint), since it visits
     * only crossed triggers instead of generating a scope for every cell.
     *
     * @return int[] cells to cross out (may be empty)
     */
    public function reactCells(FilledBoard $board, KeepMask $keep): array;
}
