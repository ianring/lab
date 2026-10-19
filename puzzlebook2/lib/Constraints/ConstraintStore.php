<?php

declare(strict_types=1);

namespace Puzzlebook2\Constraints;

/**
 * Every active constraint, indexed by which cells it watches, so checking a
 * candidate at a given cell never has to scan constraints that couldn't
 * possibly apply there. Registration and removal are both O(watched
 * cells), which is what lets the solver's undo (Trail) be cheap: a
 * constraint simply stops existing when its ConstraintRegistrationEntry is
 * undone, and every Domain that queries this store live sees that
 * immediately, with nothing else to reconcile.
 *
 * Also tracks which decision level registered each constraint -- used only
 * for conflict DIAGNOSTICS (Conflict::$responsibleLevels, explain/Advisor
 * output); the solver's backtracking itself is chronological and never
 * consults it.
 */
final class ConstraintStore
{
    /** @var array<int, array<int, Constraint>> cell => (spl_object_id => Constraint) */
    private array $byCell = [];

    /** @var array<int, Constraint> all currently active constraints, by spl_object_id */
    private array $all = [];

    /** @var array<int, int> spl_object_id => decision level that registered it */
    private array $levelOf = [];

    public function register(Constraint $c, int $decisionLevel): void
    {
        $id = spl_object_id($c);
        $this->all[$id] = $c;
        $this->levelOf[$id] = $decisionLevel;
        foreach ($c->watchedCells() as $cell) {
            $this->byCell[$cell][$id] = $c;
        }
    }

    public function unregister(Constraint $c): void
    {
        $id = spl_object_id($c);
        unset($this->all[$id], $this->levelOf[$id]);
        foreach ($c->watchedCells() as $cell) {
            unset($this->byCell[$cell][$id]);
            if ($this->byCell[$cell] === []) {
                unset($this->byCell[$cell]);
            }
        }
    }

    public function levelOf(Constraint $c): int
    {
        return $this->levelOf[spl_object_id($c)] ?? 0;
    }

    /** @return Constraint[] */
    public function watching(int $cell): array
    {
        return array_values($this->byCell[$cell] ?? []);
    }

    /** @return Constraint[] every active constraint -- used only for diagnostics/explain, not the hot path. */
    public function all(): array
    {
        return array_values($this->all);
    }
}
