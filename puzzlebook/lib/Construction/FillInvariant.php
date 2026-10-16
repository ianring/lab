<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;

/**
 * A condition that must hold across MANY cells at once, enforced while words
 * are being chosen.
 *
 * Every CellPlan describes one cell in isolation, which is enough for most
 * rules. It is not enough for a rule that fires on a COINCIDENCE between
 * words -- two anagrams on a page, two words sharing a prefix, a word whose
 * reversal is also present. Such a rule removes words it never placed,
 * because 120 words on a page collide by accident all the time: English
 * clusters hard around prefixes like "con" and "pre", so unplanned pairs are
 * the norm rather than the exception. Left alone, those accidents cross out
 * words the build was relying on and the finale breaks.
 *
 * A rule that fires on coincidences therefore registers an invariant saying
 * "no coincidence of this kind except the ones I planted". The builder checks
 * every invariant before accepting a word, and tells each one what it ended
 * up placing, so an invariant can keep whatever running index it needs.
 *
 * This is the same local-versus-global distinction that shaped the finale:
 * CellPlan handles everything local, and this handles the rest. Keeping them
 * separate is what stops the global cases leaking into every rule.
 */
interface FillInvariant
{
    /**
     * May $word go in cell $cell without breaking this invariant?
     *
     * Called for every candidate word, so keep it cheap -- prefer a running index
     * built in record() over rescanning the placed words each time.
     *
     * @param array $placed linear cell index => word, or null where unfilled
     */
    public function allows(string $word, int $cell, array $placed, Grid $grid): bool;

    /** Note that $word has been placed at $cell, so later checks can account for it. */
    public function record(string $word, int $cell, Grid $grid): void;

    /** What this invariant protects, for diagnostics when a build can't satisfy it. */
    public function describe(): string;
}
