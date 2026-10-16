<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;

/**
 * PARKED -- works, but don't put it in a chain in this form.
 *
 * The flaw is that a rule depending on position ALONE stamps an identical
 * stencil on every page: the same 24 cells, 100 times over. A reader works
 * the pattern out once and then performs 2,400 mechanical crossings-out,
 * which is the dullest work in the book and a fifth of the whole thing.
 *
 * Compare EveryNthSurvivorRule, which counts across the whole book rather
 * than per page: its pattern drifts against the page grid and never repeats,
 * so the reader has to keep actually counting. That's the property this rule
 * lacks. Any better formulation needs one of:
 *
 *   - to count across the book, not within a page (so it can't be memorised);
 *   - to vary with the page number (so no two pages are alike);
 *   - to combine position with something about the word itself (so the reader
 *     has to read, not just count rows).
 *
 * Kept because the construction side is the interesting part and is worth
 * reusing: a rule that crosses out cells unconditionally has to claim them
 * before every other rule, since nothing placed there can survive.
 *
 * ---
 *
 * Cross out every word whose row AND column numbers are both prime, counting
 * from 1. On a 20 x 6 page that's rows 2, 3, 5, 7, 11, 13, 17, 19 crossed
 * with columns 2, 3 and 5 -- 24 cells per page.
 *
 * Nothing to do with letters at all: it depends only on where a word sits.
 * That makes it the easiest rule in the book to apply (count rows and
 * columns, no reading required) and a heavy one, since its footprint is
 * fixed by the page shape rather than by what the dictionary happens to
 * contain. Its reach is therefore 'derived' -- a keep_fraction would be
 * meaningless.
 *
 * CONSTRUCTION, and why it goes first. This rule crosses out its cells
 * unconditionally: there's no word that could sit at a prime position and
 * survive it. So no word that has to live can ever be placed there, which
 * means these cells must be claimed before any other rule gets to choose --
 * hence the very low priority number. If the answer has already been put on
 * a prime position, this throws ConstructionRetry and the builder tries
 * again with the answer somewhere else.
 */
final class PrimePositionRule implements RuleInterface, ConstructsCells
{
    public function apply(array $words, Grid $grid, array $keep): array
    {
        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                if (!self::isPrime($row + 1)) {
                    continue;
                }
                for ($col = 0; $col < $grid->cols; $col++) {
                    if (!self::isPrime($col + 1)) {
                        continue;
                    }
                    $keep[$grid->linearIndex($page, $row, $col)] = false;
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $grid = $canvas->grid();
        $plan = CellPlan::surviving($context->spellingRulesBefore(), 'prime' . $context->stepId());
        $claimed = 0;

        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                if (!self::isPrime($row + 1)) {
                    continue;
                }
                for ($col = 0; $col < $grid->cols; $col++) {
                    if (!self::isPrime($col + 1)) {
                        continue;
                    }
                    $cell = $grid->linearIndex($page, $row, $col);
                    if (!$canvas->isFree($cell)) {
                        // Only the answer is placed before this rule runs, so this means
                        // the answer landed on a prime position -- where this rule would
                        // cross it out. Another arrangement will put it elsewhere.
                        throw new ConstructionRetry(
                            'the answer landed on a prime row and column, where step '
                            . $context->stepId() . ' would cross it out'
                        );
                    }
                    $canvas->claimDoomed($cell, $context->stepId(), $plan);
                    $claimed++;
                }
            }
        }

        if ($claimed === 0) {
            throw new \RuntimeException(
                "Step {$context->stepId()} found no cell whose row and column are both prime. A page of "
                . "{$grid->rows} rows by {$grid->cols} columns is too small for this rule."
            );
        }
    }

    public function constructionPriority(): int
    {
        // Must claim before anything else: these cells can hold no surviving word, so
        // every other rule has to choose from what's left. Lower than the
        // survivor-defining rules, which is unusual and deliberate.
        return 5;
    }

    public function describe(): string
    {
        return 'words whose row and column are both prime numbers';
    }

    private static function isPrime(int $n): bool
    {
        return \Puzzlebook\WordTags::isPrime($n);
    }
}
