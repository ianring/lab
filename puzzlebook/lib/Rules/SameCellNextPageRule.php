<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;
use Puzzlebook\WordTags;

/**
 * NOT IN THE CHAIN. Removed because it didn't earn its place: at J-in-second-
 * position it has 41 candidate words in a 133,000-word list, of which ~29
 * survive the earlier rules, so it removed about 24 cells out of 12,000 --
 * a paragraph of instructions and a page-turn for almost no progress.
 *
 * It also sat awkwardly with the book's two reading conventions at once: the
 * trigger must still be standing (so you can read its letters), AND the step
 * works from a snapshot (so you find all the triggers before crossing any
 * out). Each is reasonable alone; together they read as "go back and find
 * the words you've already crossed out", which is precisely the confusion
 * the conventions exist to prevent.
 *
 * The cross-page MECHANISM is still interesting and the code is sound, so
 * it's kept. If it comes back, give it enough supply to be worth the
 * instructions -- letters ['j','q','x','z'] has 1,618 candidates, position 1
 * with J has 1,027 -- and re-read the two conventions against the wording
 * before committing to it.
 *
 * ---
 *
 * Find every word with one of $letters at position $position (1-indexed),
 * and cross out the word sitting at the SAME row and column $pageOffset
 * pages later. With $removeTrigger on, the triggering word goes too.
 *
 * A new axis for the book: every other rule works within a page, this one
 * reaches across pages. The reader has to hold a position in mind and turn
 * the page to act on it, which is a different physical motion from anything
 * else here.
 *
 * TUNING. The footprint is set by how many words can trigger it, and that
 * varies enormously with the parameters. "J at position 2" is very rare
 * (the order of a hundred words in a 133,000-word list), making this a
 * variety rule; "a vowel at position 2" would match a large share of the
 * dictionary and make it a heavy one. bin/check.php reports the real supply,
 * so $letters and $position are the knobs to turn if the footprint isn't
 * what you wanted.
 *
 * The trigger must still be STANDING to fire. The reader has to read its
 * second letter, and a word crossed out with a marker can't be read at all --
 * no rule in this book asks anyone to read an eliminated word.
 *
 * WHY $removeTrigger DEFAULTS TO TRUE. Every word in the book has to be
 * crossed out by some rule, or it survives to the end and breaks the finale.
 * If this rule spares its own triggers, they have to die elsewhere -- which
 * means finding words that have J at position 2 AND independently fail some
 * other rule, an intersection of two narrow conditions that the dictionary
 * barely supplies. Removing the trigger here keeps the rule self-contained
 * and constructible. Set it false only if you've checked there are enough
 * words in that intersection.
 */
/** No card yet -- subclass with real letters/position/pageOffset/removeTrigger values to add one. */
abstract class SameCellNextPageRule implements RuleInterface, ConstructsCells
{
    /** @param string[] $letters */
    protected function __construct(
        private array $letters,
        private int $position,
        private int $pageOffset,
        private bool $removeTrigger
    ) {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        // Only a word still standing can trigger this: the reader has to be able to
        // READ its second letter, and a crossed-out word may be under a marker. Read
        // from a snapshot so removing a trigger can't stop a later one from firing.
        $keepBefore = $keep;

        foreach ($words as $i => $word) {
            if (!$keepBefore[$i] || !$this->isTrigger($word)) {
                continue;
            }

            [$page, $row, $col] = $grid->coordsOf($i);
            $targetPage = $page + $this->pageOffset;
            if ($targetPage >= $grid->pages) {
                continue;
            }

            $keep[$grid->linearIndex($targetPage, $row, $col)] = false;
            if ($this->removeTrigger) {
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
        $prefix = $context->spellingRulesBefore();
        $letters = $this->letters;
        $position = $this->position;

        $triggerShape = static function (string $word) use ($letters, $position): bool {
            $letter = WordTags::letterAt($word, $position);
            return $letter !== null && in_array($letter, $letters, true);
        };

        // With remove_trigger off, this rule doesn't cross out its own triggers, so
        // they need to fail some other rule or they'd survive to the end. Narrow the
        // shape to the intersection -- and expect the supply to be thin, which is why
        // remove_trigger defaults to true (see the class comment).
        if (!$this->removeTrigger) {
            $earlier = $context->earlierSpellingSteps();
            if ($earlier === []) {
                throw new \RuntimeException(
                    "Step {$context->stepId()} has remove_trigger off, so its trigger words need an "
                    . 'earlier spelling rule to remove them, and there is none before it in the chain.'
                );
            }
            $killer = $earlier[array_key_last($earlier)];
            $plainShape = $triggerShape;
            $triggerShape = static fn (string $word): bool => $plainShape($word) && $killer->failsWord($word);
        }

        // Each trigger accounts for two cells when it removes itself, one otherwise.
        $cellsPerTrigger = $this->removeTrigger ? 2 : 1;
        $wanted = intdiv($budget, $cellsPerTrigger);
        if ($wanted <= 0) {
            return;
        }

        // Only cells on a page that HAS a page $pageOffset later can host a trigger.
        $hostable = [];
        $lastHostPage = $grid->pages - 1 - $this->pageOffset;
        for ($page = 0; $page <= $lastHostPage; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                for ($col = 0; $col < $grid->cols; $col++) {
                    $hostable[] = [
                        $grid->linearIndex($page, $row, $col),
                        $grid->linearIndex($page + $this->pageOffset, $row, $col),
                    ];
                }
            }
        }
        if ($hostable === []) {
            throw new \RuntimeException(
                "Step {$context->stepId()} reaches {$this->pageOffset} page(s) ahead, but the book only has "
                . "{$grid->pages} pages, so there is nowhere for it to act."
            );
        }
        $hostable = Rng::shuffle($hostable, $context->seed());

        // One plan of each kind for the whole rule -- see CellPlan.
        $triggerPlan = new CellPlan($prefix, $triggerShape, 'trigger' . $context->stepId());
        $targetPlan = CellPlan::surviving($prefix, 'nextpage' . $context->stepId());

        $placed = 0;
        foreach ($hostable as [$triggerCell, $targetCell]) {
            if ($placed >= $wanted) {
                break;
            }
            if (!$canvas->isFree($triggerCell) || !$canvas->isFree($targetCell)) {
                continue;
            }

            $canvas->claimDoomed($triggerCell, $context->stepId(), $triggerPlan);
            $canvas->claimDoomed($targetCell, $context->stepId(), $targetPlan);

            $placed++;
        }

        if ($placed < $wanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} could only place {$placed} of {$wanted} cross-page triggers -- "
                . 'not enough pages had both the trigger cell and the matching cell a page later still free'
            );
        }
    }

    public function constructionPriority(): int
    {
        // Needs a specific pair of cells on different pages, so it chooses before the
        // pair rule and the take-anything spelling rules, but after the rules that
        // decide which words survive.
        return 40;
    }

    public function describe(): string
    {
        $where = $this->pageOffset === 1 ? 'the next page' : "{$this->pageOffset} pages later";
        return 'the same cell on ' . $where . ', triggered by '
            . strtoupper(implode('/', $this->letters)) . " at letter {$this->position}"
            . ($this->removeTrigger ? ' (and the trigger itself)' : '');
    }

    private function isTrigger(string $word): bool
    {
        $letter = WordTags::letterAt($word, $this->position);
        return $letter !== null && in_array($letter, $this->letters, true);
    }
}
