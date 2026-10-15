<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\ConstructionContext;

/**
 * A rule that knows how to build its own part of the book.
 *
 * This is the other half of the pairing that RuleInterface starts: apply()
 * says how a rule ELIMINATES words when the book is solved, construct() says
 * how its cells are LAID OUT when the book is made. Both live in the same
 * class, so a rule can't have its solving and its building drift apart, and
 * adding a new mechanism means writing one class rather than editing the
 * builder.
 *
 * SpellingRule implements this generically (claim any free cells, fill them
 * with words of the shape that fails me), so a pure spelling check gets
 * construction for free. Positional rules -- ones that care about where
 * their cells sit relative to each other -- implement it themselves.
 */
interface ConstructsCells
{
    /**
     * Claim the cells this rule needs and record what shape their words must be.
     *
     * Throw ConstructionRetry if this particular arrangement can't satisfy the
     * rule; the builder will try another. Throw a plain RuntimeException only for
     * a genuine dead end that retrying can't fix.
     */
    public function construct(Canvas $canvas, ConstructionContext $context): void;

    /**
     * When this rule gets to claim cells, relative to the others. Lower runs first.
     *
     * Order matters because cells are a finite resource and some rules are far
     * fussier than others. A rule that can only work with specific geometry has to
     * choose before a rule that will take any cell at all, or the fussy one finds
     * nothing left. The convention:
     *
     *    0- 19  rules that DEFINE which words survive to the end
     *   20- 69  rules needing particular geometry (adjacent cells, a knight's move)
     *   70-199  rules that will take any free cell
     */
    public function constructionPriority(): int;
}
