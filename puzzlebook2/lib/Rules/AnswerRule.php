<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\PageEdges;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Generators\FixedCellGenerator;
use Puzzlebook2\Rules\Generators\SingleFreeCellGenerator;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;

/**
 * The one cell holding the book's answer -- the word that survives every
 * step. Internal: never appears in config/chain.json; ChainPlan creates it
 * from the chain's 'answer' field. Exact arity (THE answer, no fallback),
 * so it schedules first and gets its pick of cells, and every geometric
 * rule's standing guard protects it automatically (its death index is
 * infinity -- it is standing at every step).
 */
final class AnswerRule implements Rule
{
    use RuleIdentity;

    /**
     * $fixedCell pins the answer to one exact cell (a page-edge cell the
     * series planner chose, so the book's answer PAGE is known before the
     * fill -- the Mystery chain needs that). null keeps the original
     * behaviour: any free edge cell, chosen by the solver.
     */
    public function __construct(private string $answer, private ?int $fixedCell = null)
    {
    }

    public function describe(): string
    {
        return "the answer word ('{$this->answer}'), surviving everything";
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        return [];
    }

    public function demand(ChainContext $ctx): Demand
    {
        $answer = $this->answer;
        // Page-edge cells only: an edge cell has fewer than 8 neighbours,
        // so no capture-style rule can EVER eliminate it -- a word that
        // must outlive the whole chain has no business sitting anywhere
        // structurally capturable. (Learned the expensive way: with the
        // answer interior, every cell of its ring but one must stay
        // standing, and the search thrashes proving it.)
        $generator = $this->fixedCell !== null
            ? new FixedCellGenerator($this->fixedCell)
            : new SingleFreeCellGenerator(PageEdges::interior($ctx->board()->spec()));
        return new Demand(
            label: $this->id(),
            count: 1,
            arity: Arity::Exact,
            generator: $generator,
            cellsPerScope: 1,
            rawSupplyEstimate: 1,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($answer): ?array {
                if ($draw > 0 || $consumed->isConsumed($answer)) {
                    return null;
                }
                return ['cell' => $answer];
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        return [];
    }

    public function kind(): string
    {
        return 'answer';
    }

    /** Never printed -- the answer's existence is the book's secret; this text exists only for exports and diagnostics. */
    public function ruleText(): string
    {
        return 'One word in this book survives every rule. It is placed here, and it is the answer.';
    }
}
