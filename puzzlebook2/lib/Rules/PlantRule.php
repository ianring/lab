<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Generators\FixedCellGenerator;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;

/**
 * A PLANT: a chosen cell that must hold one of a chosen set of words. The
 * generalization of AnswerRule -- same Exact-arity, schedule-first placement
 * -- but where the answer survives everything (death index infinity, chosen
 * free edge cell), a plant sits at a FIXED cell and is itself eliminated by
 * the ordinary chain (a dead word, still readable).
 *
 * Two shapes, both just a candidate list:
 *   - a MYSTERY plant passes exactly one word (its identity matters -- the
 *     same word is read in one book and found in the next);
 *   - an ACROSTIC plant passes EVERY eliminable word starting with the letter
 *     that cell must contribute, so the first letter is fixed but the rest of
 *     the word is free (full dictionary fallback -- Ian's optimization, which
 *     avoids the placement-sensitive backtracking a single fixed word risks).
 *
 * The candidate list is tried in order with fallback; the solver keeps the
 * first that fits. A plant never eliminates anything and is never a chain
 * step; ChainPlan injects it like the answer and records its death step in
 * the StepResolver so the geometric rules' standing logic stays correct.
 *
 * The id must be unique per plant (ChainPlan builds it from the cell), because
 * each cell records its owning rule's id and the death map is keyed by it.
 */
final class PlantRule implements Rule
{
    /** @param string[] $candidates tried in order; must be non-empty */
    public function __construct(
        private string $id,
        private int $cell,
        private array $candidates,
        private string $role = 'plant',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function describe(): string
    {
        $what = count($this->candidates) === 1
            ? "'{$this->candidates[0]}'"
            : count($this->candidates) . " candidates (e.g. '{$this->candidates[0]}')";
        return "a planted word ({$what}) at cell {$this->cell}";
    }

    /** Never printed -- a plant has no reader-facing instruction of its own. */
    public function ruleText(): string
    {
        return 'A word placed here as part of the series.';
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
        $candidates = $this->candidates;
        return new Demand(
            label: $this->id,
            count: 1,
            arity: Arity::Exact,
            generator: new FixedCellGenerator($this->cell),
            cellsPerScope: 1,
            rawSupplyEstimate: count($candidates),
            geometricCapacity: 1,
            // Enumerate distinct unconsumed candidates in order: draw k is the
            // (k+1)th candidate still free. A single-word (Mystery) plant has
            // one; an acrostic plant has many and falls back through them.
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($candidates): ?array {
                $seen = 0;
                foreach ($candidates as $word) {
                    if ($consumed->isConsumed($word)) {
                        continue;
                    }
                    if ($seen === $draw) {
                        return ['cell' => $word];
                    }
                    $seen++;
                }
                return null;
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
        return 'plant';
    }

    /** @return string[] */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function cell(): int
    {
        return $this->cell;
    }

    public function role(): string
    {
        return $this->role;
    }
}
