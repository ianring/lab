<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Rule;

/**
 * One still-unresolved unit of a rule's demand -- a Demand with count=50
 * expands into 50 of these, all pushed into one global ReservationQueue,
 * never processed "rule by rule."
 *
 * A slot's seed is FIXED for the whole solve. That is a requirement, not a
 * simplification: the solver resumes a retried decision at its next
 * candidate index, which is only meaningful if the slot's candidate
 * sequence is reproducible -- same seed, same (restored) board state, same
 * order. Variation between attempts comes from the resume index advancing,
 * never from reshuffling; reshuffling is what made retry haphazard in v1.
 */
final class ReservationSlot
{
    public function __construct(
        public readonly Rule $rule,
        public readonly Demand $demand,
        public readonly ChainContext $context,
        public readonly int $index,
    ) {
    }

    public function arity(): Arity
    {
        return $this->demand->arity;
    }

    /** Fixed, deterministic, decorrelated across slots and across rules sharing an index. */
    public function seed(): int
    {
        return $this->context->seed() + $this->index * 9173 + (int) crc32($this->rule->id());
    }

    /**
     * MRV ordering key, ascending (smaller = resolved first), in three
     * tiers -- each one a fact about the demand, never a hand-tuned number:
     *
     *  1. Exact slots (-1): committed words with no fallback resolve while
     *     the board is emptiest.
     *  2. GEOMETRY-RESTRICTED Shaped slots (a finite geometricCapacity:
     *     corners, page edges), ordered by capacity-vs-count headroom. The
     *     reason this is a tier and not just a ratio: an unrestricted
     *     filler slot can land anywhere, so letting it schedule before a
     *     restricted slot lets it squat on cells the restricted slot has
     *     no alternative to -- which is exactly how the first full-scale
     *     builds dead-ended twice (filler ate the corners; then filler ate
     *     the page edges the decoys needed). Nobody with somewhere else to
     *     go may take cells from someone with nowhere else to go.
     *  3. Unrestricted Shaped slots, by word-supply headroom (scarcest
     *     catch first; the absorber's huge pool naturally goes last).
     *
     * Ratios are scaled x16 so near values still order deterministically.
     */
    public function priorityKey(): int
    {
        if ($this->arity() === Arity::Exact) {
            return -1;
        }
        if ($this->demand->count === 0) {
            return PHP_INT_MAX;
        }
        $binding = min($this->demand->rawSupplyEstimate, $this->demand->geometricCapacity);
        $ratio = $binding === PHP_INT_MAX
            ? 900_000_000
            : (int) min(900_000_000, intdiv($binding * 16, $this->demand->count));
        $restricted = $this->demand->geometricCapacity !== PHP_INT_MAX;
        return $restricted ? $ratio : 1_000_000_000 + $ratio;
    }
}
