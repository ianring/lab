<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;

/**
 * Holds back $count extra words of exactly $length letters -- survives
 * everything else, shaped so the chain's final step is what clears them --
 * so the book doesn't end on an anticlimax where nothing is left standing
 * for the final rule to visibly act on.
 *
 * This rule never removes anything itself; apply() is a no-op. It exists
 * purely to shape and reserve cells at construction time. The actual
 * crossing-out happens later, when the final step's own rule runs.
 *
 * REPLACES the knight's-move finale for Book 1. That rule also reserved a
 * batch of survivor cells for the final step to clear, but it got there by
 * solving a global covering problem (every survivor must be a knight's move
 * from some key, keys must avoid each other's victims, the answer's
 * neighbourhood is off limits) -- expensive to satisfy and the dominant
 * source of retries once the reveal was ALSO competing for the same survivor
 * slots. This rule claims a flat number of free cells with no geometric
 * relationship between them at all: the same cell-selection shape every
 * ordinary SpellingRule already uses, just surviving instead of doomed.
 * KnightAdjacentSurvivingLengthRule is kept, unused, for a future book.
 *
 * Shared base for a family of cards, one per length -- DecoyLength3Rule
 * through DecoyLength10Rule, matching WordLengthRule's length family (the
 * decoy length always matches whichever WordLengthNRule is the book's final
 * step). $count is baked to 10 in every one of them; a book that wants a
 * different count is another concrete subclass.
 */
abstract class DecoyLengthRule implements RuleInterface, ConstructsCells
{
    protected function __construct(private int $length, private int $count)
    {
    }

    /** Never removes anything -- the final step is what clears these words. */
    public function apply(array $words, Grid $grid, array $keep): array
    {
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $finalRule = $context->finalRule();
        $finalStepId = $context->finalStepId();
        if ($finalRule === null || $finalStepId === null) {
            throw new \RuntimeException(
                "Step {$context->stepId()} holds words back for the final step to clear, so the chain needs "
                . "one. Add a step marked 'final' => true."
            );
        }

        $survivesAll = $context->spellingRulesTotal();
        $length = $this->length;
        $shape = static fn (string $word): bool => strlen($word) === $length && $finalRule->failsWord($word);
        $shortClass = substr(strrchr(static::class, '\\'), 1);
        $plan = new CellPlan(
            $survivesAll,
            $shape,
            'decoy' . $context->stepId(),
            description: "step {$context->stepId()} [{$shortClass}]: {$this->describe()}",
            fixedLength: $length
        );

        $claimed = 0;
        foreach ($canvas->freeCellsShuffled($context->seed()) as $candidate) {
            if ($claimed >= $this->count) {
                break;
            }
            if (!$canvas->isFree($candidate) || !$canvas->mayBeSurvivor($candidate)) {
                continue;
            }
            $canvas->claimSurvivor($candidate, $finalStepId, $plan, true);
            $claimed++;
        }

        if ($claimed < $this->count) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$this->count} cells to hold back but the grid had room "
                . "for only {$claimed}"
            );
        }
    }

    public function constructionPriority(): int
    {
        // Reserves its cells before the keep_fraction budgets divide up what's left,
        // same tier as any other rule that defines which words survive to the end.
        return 10;
    }

    public function describe(): string
    {
        return "{$this->count} extra words of exactly {$this->length} letters, held back for the final "
            . 'step to clear';
    }
}
