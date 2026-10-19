<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Grid;

/**
 * Cross out every surviving word whose all 8 surrounding cells (orthogonal
 * and diagonal, same page -- a king's move, or a captured stone in Go) are
 * ALREADY crossed out. A cell on a page edge, with fewer than 8 neighbours,
 * can never be captured.
 *
 * EXPERIMENTAL AND DELIBERATELY UNDIRECTED. construct() claims nothing --
 * there is no "pick a capture, guarantee its ring is dead" construction step
 * here, unlike every pair rule in this book. Whether anything gets captured,
 * and how much, is purely emergent from whatever the rules before it already
 * eliminated: this rule just checks, at apply() time, whether the pattern
 * happened to occur. That means its yield can't be measured ahead of time
 * the way an ordinary spelling rule's can -- bin/check.php has nothing useful
 * to report about it -- and it needs to sit late in the chain, where enough
 * of the book is already dead for 8-dead-neighbour rings to actually occur.
 *
 * This is the same SHAPE of risk every positional rule here carries (an
 * apply()-time structural scan could, in principle, happen to catch the
 * answer too), just with no construction-time guarantee against it at all.
 * ChainBuilder::verify() is what catches that: an attempt where this rule
 * captured something it shouldn't have fails verification and the whole
 * attempt is retried with a different seed, the same safety net every other
 * rule here already relies on.
 */
final class SurroundedCaptureRule implements RuleInterface, ConstructsCells
{
    public function apply(array $words, Grid $grid, array $keep): array
    {
        // Snapshot: a word captured by this pass was not itself "already dead"
        // a moment ago, so it can't un-surround one of its own neighbours
        // mid-pass -- same reading convention as every other rule here.
        $keepBefore = $keep;
        foreach ($words as $i => $word) {
            if (!$keepBefore[$i]) {
                continue;
            }
            $neighbours = $grid->surroundingNeighbours($i);
            if (count($neighbours) < 8) {
                continue;
            }
            $surrounded = true;
            foreach ($neighbours as $n) {
                if ($keepBefore[$n]) {
                    $surrounded = false;
                    break;
                }
            }
            if ($surrounded) {
                $keep[$i] = false;
            }
        }
        return $keep;
    }

    /** Never claims a cell -- see the class docblock. */
    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
    }

    public function constructionPriority(): int
    {
        // Irrelevant -- this rule never claims a cell, so it never competes for one.
        return 199;
    }

    public function describe(): string
    {
        return "words entirely surrounded by 8 already-crossed-out words (a Go-style capture)";
    }
}
