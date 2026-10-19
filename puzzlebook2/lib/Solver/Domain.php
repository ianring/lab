<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Constraints\ConstraintStore;

/**
 * A cell's remaining candidate set -- never materialized as an actual list
 * of words (at 12,000 cells against a 133,000-word dictionary that's not
 * viable). Either pinned to a single, pre-chosen word (Exact-arity
 * reservations -- an anagram pair's two halves, chosen together before any
 * geometry is decided) or backed by a shared BasePool.
 *
 * Deliberately does NOT cache a local list of exclusions: it asks the
 * shared ConstraintStore, live, every time. That means registering or
 * unregistering a constraint (ConstraintRegistrationEntry, the only trail
 * entry needed for this) automatically and immediately changes what every
 * watching domain sees, with no separate bookkeeping step that could fall
 * out of sync -- there is nothing to "push" to a domain when a new
 * constraint appears, and nothing to "pop" when it's undone.
 *
 * hasCandidate()/firstCandidate() are PROBES with no side effects: finding
 * a candidate does not consume it. Only Board::assign() actually commits
 * one, via ConsumedWords -- this split is what makes checking "would this
 * still leave something valid" safe to do speculatively during propagation.
 */
final class Domain
{
    private ?string $pinned = null;

    public function __construct(
        private ?BasePool $basePool,
        private int $cell,
        private ConstraintStore $constraints,
        private BoardView $view,
        /** Which rule this cell is being drawn FOR -- handed to each
         *  constraint so claimant-sensitive ones (StandingPatternConstraint)
         *  know when the candidate word would die. */
        private ?string $ownerRuleId = null,
    ) {
    }

    public static function pinnedTo(string $word, int $cell, ConstraintStore $constraints, BoardView $view): self
    {
        $d = new self(null, $cell, $constraints, $view);
        $d->pinned = $word;
        return $d;
    }

    public function isPinned(): bool
    {
        return $this->pinned !== null;
    }

    public function pinnedWord(): ?string
    {
        return $this->pinned;
    }

    public function hasCandidate(): bool
    {
        return $this->firstCandidate() !== null;
    }

    public function firstCandidate(): ?string
    {
        if ($this->pinned !== null) {
            return $this->pinned;
        }
        return $this->basePool?->firstMatch($this->liveExclusions());
    }

    /** Approximate, for MRV ordering -- a pinned cell is maximally constrained. */
    public function estimatedSize(): int
    {
        if ($this->pinned !== null) {
            return 0;
        }
        return $this->basePool?->estimatedSize() ?? 0;
    }

    /** @return \Closure[] */
    private function liveExclusions(): array
    {
        $exclusions = [];
        foreach ($this->constraints->watching($this->cell) as $constraint) {
            $exclusions[] = fn (string $word): bool => $constraint->violatedBy($this->cell, $word, $this->view, $this->ownerRuleId);
        }
        return $exclusions;
    }
}
