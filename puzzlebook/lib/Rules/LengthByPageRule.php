<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Construction\LengthByPageInvariant;

/**
 * On each page, cross out every word of one particular length -- and the
 * length changes from page to page.
 *
 * "Words whose length matches the page number" was the idea; taken literally
 * it only does anything on pages 3 to 10, since no word is longer than ten
 * letters or shorter than three, leaving ninety pages untouched. Cycling
 * instead -- length = (page number mod span) + shortest -- gives every page
 * its own target length and uses the whole range.
 *
 * The cycling also fixes something that sank the prime-position rule: a rule
 * that depends only on position stamps the same pattern on all hundred
 * pages, so the reader learns it once and then does no thinking. Here the
 * target changes under them, and they have to read the words anyway.
 *
 * Note the reader needs the page number and a bit of arithmetic, so the
 * printed instruction has to state the cycle plainly.
 */
final class LengthByPageRule implements RuleInterface, ConstructsCells
{
    private const SHORTEST = 3;
    private const SPAN = 8;

    /** The length this rule removes on a given 0-based page. */
    public function lengthForPage(int $page): int
    {
        return ($page % self::SPAN) + self::SHORTEST;
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $cellsPerPage = $grid->rows * $grid->cols;

        foreach ($words as $i => $word) {
            if (!$keep[$i]) {
                continue;
            }
            $page = intdiv($i, $cellsPerPage);
            if (strlen($word) === $this->lengthForPage($page)) {
                $keep[$i] = false;
            }
        }

        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $budget = $context->budget();
        if ($budget <= 0) {
            return;
        }

        $grid = $canvas->grid();
        $cellsPerPage = $grid->rows * $grid->cols;
        $prefix = $context->spellingRulesBefore();
        $placed = 0;

        // One plan per page, since each page wants a different length. That's a
        // hundred plans rather than one, which is still nothing -- the thing to
        // avoid is a plan per CELL.
        $plans = [];
        for ($page = 0; $page < $grid->pages; $page++) {
            $wanted = $this->lengthForPage($page);
            $plans[$page] = new CellPlan(
                $prefix,
                static fn (string $word): bool => strlen($word) === $wanted,
                'bypage' . $context->stepId() . '-' . $wanted
            );
        }

        $claimed = [];
        foreach ($canvas->freeCellsShuffled($context->seed()) as $cell) {
            if ($placed >= $budget) {
                break;
            }
            $canvas->claimDoomed($cell, $context->stepId(), $plans[intdiv($cell, $cellsPerPage)]);
            $claimed[$cell] = true;
            $placed++;
        }

        if ($placed < $budget) {
            throw new ConstructionRetry(
                "step {$context->stepId()} needed {$budget} cells but only {$placed} were free"
            );
        }

        // Every OTHER cell must avoid its page's length, or it would be crossed out
        // too. Expressed as an invariant because it constrains cells this rule never
        // claims -- including the answer and the revealed words. The cells it DID
        // claim are exempt: matching is the whole point of those.
        $canvas->addInvariant(new LengthByPageInvariant($this, $cellsPerPage, $claimed));
    }

    public function constructionPriority(): int
    {
        // Takes any free cell, so it goes with the flexible rules.
        return 90;
    }

    public function describe(): string
    {
        $last = self::SHORTEST + self::SPAN - 1;
        return "words whose length matches the page's own target, cycling " . self::SHORTEST . " to {$last}";
    }
}
