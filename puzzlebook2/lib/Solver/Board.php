<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Constraints\Constraint;
use Puzzlebook2\Constraints\ConstraintStore;

/**
 * The mutable heart of the solver: every cell's word (once assigned), every
 * claimed cell's domain, the global consumed-words set, the constraint
 * store, and the undo trail. A cell's domain and word are created together
 * exactly once, at the moment some reservation claims it (claimCell()) --
 * before that, a cell is simply "free," with no domain object at all.
 */
final class Board implements BoardView
{
    /** @var array<int,?string> */
    private array $words;

    /** @var array<int,Domain> only for claimed cells */
    private array $domains = [];

    /** @var array<int,string> cell => owning rule id, for diagnostics */
    private array $ownerOf = [];

    /** @var array<int,int> cell => decision level that claimed it, for conflict diagnostics */
    private array $claimLevel = [];

    public readonly ConsumedWords $consumed;
    public readonly ConstraintStore $constraints;
    public readonly Trail $trail;

    private int $decisionLevel = 0;
    private int $assignedCount = 0;

    public function __construct(private BoardSpec $spec)
    {
        $this->words = array_fill(0, $spec->size(), null);
        $this->consumed = new ConsumedWords();
        $this->constraints = new ConstraintStore();
        $this->trail = new Trail();
    }

    public function spec(): BoardSpec
    {
        return $this->spec;
    }

    public function wordAt(int $cell): ?string
    {
        return $this->words[$cell];
    }

    public function isAssigned(int $cell): bool
    {
        return $this->words[$cell] !== null;
    }

    public function isFree(int $cell): bool
    {
        return $this->words[$cell] === null;
    }

    public function domainAt(int $cell): ?Domain
    {
        return $this->domains[$cell] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->assignedCount === $this->spec->size();
    }

    public function assignedCount(): int
    {
        return $this->assignedCount;
    }

    /** A fresh decision level for one reservation commit (possibly multiple cells, committed atomically). */
    public function nextDecisionLevel(): int
    {
        return ++$this->decisionLevel;
    }

    /** Claims $cell with $word under $domain, in one atomic, undoable step. */
    public function claimCell(int $cell, string $word, Domain $domain, string $ownerRuleId, int $decisionLevel): void
    {
        $this->domains[$cell] = $domain;
        $this->words[$cell] = $word;
        $this->ownerOf[$cell] = $ownerRuleId;
        $this->claimLevel[$cell] = $decisionLevel;
        $this->consumed->consume($word);
        $this->assignedCount++;
        $this->trail->push(new ReservationEntry($this, $cell, $word));
    }

    /** Called only by ReservationEntry::undo(). */
    public function releaseCell(int $cell, string $word): void
    {
        unset($this->domains[$cell], $this->ownerOf[$cell], $this->claimLevel[$cell]);
        $this->words[$cell] = null;
        $this->consumed->release($word);
        $this->assignedCount--;
    }

    public function registerConstraint(Constraint $c, int $decisionLevel): void
    {
        $this->constraints->register($c, $decisionLevel);
        $this->trail->push(new ConstraintRegistrationEntry($this->constraints, $c));
    }

    /**
     * A rule's standing invariant (Rule::globalConstraints()), registered
     * once before the solve starts and never undone -- so no trail entry,
     * and decision level 0 (nothing backtracks past the root).
     */
    public function registerPermanentConstraint(Constraint $c): void
    {
        $this->constraints->register($c, 0);
    }

    public function ownerOf(int $cell): ?string
    {
        return $this->ownerOf[$cell] ?? null;
    }

    /** The decision level that claimed $cell, or null if free -- conflict diagnostics only. */
    public function claimLevelOf(int $cell): ?int
    {
        return $this->claimLevel[$cell] ?? null;
    }

    /** @return array<int,string> every cell's word, once isComplete() is true. */
    public function finalWords(): array
    {
        return $this->words;
    }
}
