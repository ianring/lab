<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Construction\NoUnplannedCornerLetter;
use Puzzlebook\Grid;
use Puzzlebook\Rng;

/**
 * Cross out every word sitting in one of a page's four corners (top-left,
 * top-right, bottom-left, bottom-right) if it contains the letter E.
 *
 * Four corners per page is a fixed geometric count -- it scales with the
 * book automatically (120 at 30 pages, 400 at 100), never needs its own
 * density tuning the way a keep_fraction rule does.
 *
 * CONSTRUCTION. Claims every free corner in the book outright, splitting
 * them into two groups: a BUDGETED subset that deliberately contains E (the
 * ones this rule actually eliminates), and everything else, which is
 * required to NOT contain E. The second group isn't just left alone --
 * without that requirement, some unrelated later rule's ordinary filler
 * could land an E-word in a corner this rule meant to leave standing,
 * silently crossing out a word nobody budgeted for. A NoUnplannedCornerLetter
 * invariant (registered here, checked for the rest of construction) is what
 * makes that impossible rather than merely unlikely.
 */
final class CornerContainsLetterRule implements RuleInterface, ConstructsCells
{
    private const LETTER = 'e';

    public function apply(array $words, Grid $grid, array $keep): array
    {
        foreach ($grid->allCorners() as $cell) {
            if ($keep[$cell] && str_contains($words[$cell], self::LETTER)) {
                $keep[$cell] = false;
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $grid = $canvas->grid();
        $corners = $grid->allCorners();
        $freeCorners = array_values(array_filter($corners, fn (int $c): bool => $canvas->isFree($c)));

        $budget = min($context->budget(), count($freeCorners));

        $prefix = $context->spellingRulesBefore();
        $shuffled = Rng::shuffle($freeCorners, $context->seed());

        $doomedPlan = new CellPlan(
            $prefix,
            static fn (string $word): bool => str_contains($word, self::LETTER),
            'cornerE' . $context->stepId(),
            description: "step {$context->stepId()}: {$this->describe()}"
        );

        $exempt = [];
        for ($n = 0; $n < $budget; $n++) {
            $cell = $shuffled[$n];
            $canvas->claimDoomed($cell, $context->stepId(), $doomedPlan);
            $exempt[$cell] = true;
        }

        if ($budget < $context->budget()) {
            // The corners themselves are the hard ceiling (every page has exactly
            // 4, there's no "try a different seed" that conjures more), not a
            // supply problem a retry could fix -- so this is a plain exception,
            // same convention as every other genuine dead end in this codebase.
            throw new \RuntimeException(
                "step {$context->stepId()} wanted {$context->budget()} corners to deliberately contain "
                . "'" . self::LETTER . "', but the book only has " . count($corners) . ' corners total'
            );
        }

        // Every OTHER corner is deliberately left unclaimed, not claimed-and-fixed:
        // its eventual word, and which rule eventually claims the cell, is decided
        // completely normally by the rest of the chain, exactly like any other
        // free cell -- nothing here needs or wants that word's shape to be about
        // the letter E. The invariant below is what keeps the "not E" promise,
        // regardless of who ends up filling it.
        $canvas->addInvariant(new NoUnplannedCornerLetter(self::LETTER, $corners, $exempt));
    }

    public function constructionPriority(): int
    {
        // Low enough to run before AnagramPairOnPageRule/SameEndingVerticalPairRule/
        // MatchingNeighbourEndsRule (45/50/50), so its NoUnplannedCornerLetter
        // invariant already exists by the time any of them checks
        // Canvas::wordSatisfiesInvariants() before committing a literal word.
        return 20;
    }

    public function describe(): string
    {
        return "corner words containing '" . self::LETTER . "'";
    }
}
