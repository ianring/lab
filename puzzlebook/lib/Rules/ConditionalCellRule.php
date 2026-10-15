<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;

/**
 * On every page: cross out the word in the TARGET cell, but only if the word
 * in the TRIGGER cell has already been crossed out.
 *
 * Both cells are given as [row, col], and -1 means "the last one", so the
 * top-left corner is [0, 0] and the top-right is [0, -1]. The default
 * configuration is exactly that pair.
 *
 * This is a different SHAPE of rule from everything else in the book. A
 * spelling rule looks at a word's letters; the pair and knight's-move rules
 * look at what's printed nearby. This one looks at whether a neighbour has
 * been crossed out -- the reader's own progress is the input. It gives the
 * book a step that can't be worked out by inspecting the page alone, only by
 * having actually done the earlier rules.
 *
 * CONSTRUCTION. The rule only fires where the trigger is already gone, so it
 * can't just hope some other rule kills the right cells at the right time --
 * it claims the trigger cell itself and shapes that word to fail an earlier
 * spelling rule, guaranteeing it's crossed out before this step runs. Where
 * a corner is already taken (the knight's-move rule may have made it a
 * survivor), the page is skipped: the rule simply doesn't fire there, which
 * is a perfectly legal outcome rather than a failure.
 */
final class ConditionalCellRule implements RuleInterface, ConstructsCells
{
    /**
     * @param array{0:int,1:int} $trigger [row, col]; -1 means the last row/column
     * @param array{0:int,1:int} $target  [row, col]; -1 means the last row/column
     */
    public function __construct(private array $trigger = [0, 0], private array $target = [0, -1])
    {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        // Read from a snapshot so the outcome can't depend on which page is
        // processed first -- the same discipline as the knight's-move rule.
        $keepBefore = $keep;

        for ($page = 0; $page < $grid->pages; $page++) {
            $triggerCell = $this->resolve($grid, $page, $this->trigger);
            $targetCell = $this->resolve($grid, $page, $this->target);
            if ($triggerCell === $targetCell) {
                continue;
            }
            if (!$keepBefore[$triggerCell]) {
                $keep[$targetCell] = false;
            }
        }

        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $grid = $canvas->grid();
        $earlier = $context->earlierSpellingSteps();
        if ($earlier === []) {
            throw new \RuntimeException(
                "The conditional rule at step {$context->stepId()} needs at least one spelling rule "
                . 'before it in the chain, so it has something to kill the trigger cells with.'
            );
        }

        // Use the LAST earlier spelling rule rather than the first. Trigger words have
        // to pass every rule before the one that kills them, so the pool is narrowest
        // for a late rule -- but late rules here are the deliberately greedy ones, with
        // much the deepest supply, which more than makes up for it.
        $killerStepId = array_key_last($earlier);
        $killerRule = $earlier[$killerStepId];
        $killerIndex = count($earlier) - 1;

        $firedOn = 0;

        for ($page = 0; $page < $grid->pages; $page++) {
            $triggerCell = $this->resolve($grid, $page, $this->trigger);
            $targetCell = $this->resolve($grid, $page, $this->target);

            if ($triggerCell === $targetCell) {
                continue;
            }
            if (!$canvas->isFree($triggerCell) || !$canvas->isFree($targetCell)) {
                // A corner already spoken for -- skip this page rather than fight over it.
                continue;
            }

            // The trigger: shaped to be crossed out by an earlier rule, so by the time
            // this step runs it is reliably gone and this rule reliably fires.
            $canvas->claimDoomed(
                $triggerCell,
                $killerStepId,
                new CellPlan(
                    $killerIndex,
                    static fn (string $word): bool => $killerRule->failsWord($word),
                    'trigger' . $context->stepId()
                )
            );

            // The target: nothing special about its letters, it just has to still be
            // standing when this step arrives.
            $canvas->claimDoomed(
                $targetCell,
                $context->stepId(),
                CellPlan::surviving($context->spellingRulesBefore(), 'conditional' . $context->stepId())
            );

            $firedOn++;
        }

        if ($firedOn === 0) {
            throw new ConstructionRetry(
                "step {$context->stepId()} found no page where both its trigger and target cells were free"
            );
        }
    }

    public function constructionPriority(): int
    {
        // Wants two exact cells per page and has no alternatives, so it must choose
        // before the pair rule (which has thousands of options) and before the
        // take-anything spelling rules.
        return 30;
    }

    public function describe(): string
    {
        return 'the ' . $this->cellName($this->target) . ' word on a page, when the '
            . $this->cellName($this->trigger) . ' one is already crossed out';
    }

    /** @param array{0:int,1:int} $cell */
    private function resolve(Grid $grid, int $page, array $cell): int
    {
        $row = $cell[0] < 0 ? $grid->rows + $cell[0] : $cell[0];
        $col = $cell[1] < 0 ? $grid->cols + $cell[1] : $cell[1];
        return $grid->linearIndex($page, $row, $col);
    }

    /** @param array{0:int,1:int} $cell */
    private function cellName(array $cell): string
    {
        $vertical = $cell[0] === 0 ? 'top' : ($cell[0] === -1 ? 'bottom' : 'row ' . ($cell[0] + 1));
        $horizontal = $cell[1] === 0 ? 'left' : ($cell[1] === -1 ? 'right' : 'column ' . ($cell[1] + 1));
        return $vertical . '-' . $horizontal;
    }
}
