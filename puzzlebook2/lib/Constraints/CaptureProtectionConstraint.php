<?php

declare(strict_types=1);

namespace Puzzlebook2\Constraints;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\KingAdjacency;
use Puzzlebook2\Chain\StepResolver;
use Puzzlebook2\Solver\BoardView;

/**
 * SurroundedCaptureRule's standing invariant, and a real discovery of v2's
 * first full-scale verified build: when the capture step sits after the
 * absorber, the ONLY words still standing when it runs are the decoys and
 * the answer -- and any of them with 8 dead neighbours (which, at ~11
 * survivors in thousands of cells, is nearly all interior ones) gets
 * captured, killing the words the final step depends on. v1 shipped the
 * rule as "genuinely unknown until a real build runs"; this is what runs
 * discovered.
 *
 * The invariant: no cell whose word outlives the capture step (death
 * index > k) may end up surrounded by 8 cells that are all dead before k.
 * Checked from both directions, so whichever cell completes the bad ring
 * is the one rejected: a candidate CENTRE that would sit inside an
 * already-dead ring (case A), or a candidate RING member that would
 * complete the ring around an already-placed surviving centre (case B).
 * Page-edge cells (fewer than 8 neighbours) are never capturable.
 */
final class CaptureProtectionConstraint implements Constraint
{
    private KingAdjacency $king;

    /** @var array<int,true> cells with a full 8-cell ring -- the only possible capture centres */
    private array $interior;

    public function __construct(
        private int $stepIndex,
        private StepResolver $resolver,
        private BoardSpec $spec,
    ) {
        $this->king = new KingAdjacency();
        $this->interior = array_fill_keys(\Puzzlebook2\Board\PageEdges::interior($spec), true);
    }

    public function violatedBy(int $cell, string $candidate, BoardView $view, ?string $claimantRuleId = null): bool
    {
        $k = $this->stepIndex;
        $claimantDeath = $claimantRuleId === null ? PHP_INT_MAX : $this->resolver->deathIndexOf($claimantRuleId);

        // Case A: this word would SURVIVE the capture step -- it must not
        // land inside an already-all-dead ring.
        if ($claimantDeath > $k && isset($this->interior[$cell])) {
            if ($this->allDeadBefore($this->king->neighboursOf($cell, $this->spec), $k, $view)) {
                return true;
            }
        }

        // Case B: this word dies before the capture step -- it must not
        // complete a dead ring around a surviving centre.
        if ($claimantDeath < $k) {
            foreach ($this->king->neighboursOf($cell, $this->spec) as $center) {
                if (!isset($this->interior[$center])) {
                    continue;
                }
                $centerOwner = $view->ownerOf($center);
                if ($centerOwner === null || $this->resolver->deathIndexOf($centerOwner) <= $k) {
                    continue;
                }
                $ring = $this->king->neighboursOf($center, $this->spec);
                $othersDead = true;
                foreach ($ring as $r) {
                    if ($r === $cell) {
                        continue;
                    }
                    $owner = $view->ownerOf($r);
                    if ($owner === null || $this->resolver->deathIndexOf($owner) >= $k) {
                        $othersDead = false;
                        break;
                    }
                }
                if ($othersDead) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param int[] $cells */
    private function allDeadBefore(array $cells, int $k, BoardView $view): bool
    {
        foreach ($cells as $c) {
            $owner = $view->ownerOf($c);
            if ($owner === null || $this->resolver->deathIndexOf($owner) >= $k) {
                return false;
            }
        }
        return true;
    }

    public function watchedCells(): array
    {
        return $this->spec->allCells();
    }

    public function describe(): string
    {
        return 'no word surviving the capture step may end up surrounded by 8 earlier-dead cells';
    }
}
