<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

enum SolveOutcome
{
    /** A complete, valid board was produced. */
    case Success;
    /**
     * The systematic search exhausted every alternative back to the empty
     * board -- a PROOF of impossibility, with one documented caveat: Shaped
     * cells draw words under the deterministic first-valid-word policy
     * (filler words are treated as fungible), so strictly this proves
     * "infeasible under that word-choice policy." Scope/geometry
     * alternatives ARE enumerated exhaustively.
     */
    case Infeasible;
    /**
     * The step budget ran out before the search finished either way. Not a
     * proof of anything -- it means the search space is too large for the
     * budget (or, if it happens on a chain that used to build quickly, a
     * regression worth investigating). The Advisor layer is the right
     * consumer of this outcome: a chain that drives the solver here usually
     * has a structural tension worth rebalancing, not a budget problem.
     */
    case Inconclusive;
}

/** The outcome of one Solver::solve() run -- a filled board, or an honest account of why not. */
final class SolveResult
{
    private function __construct(
        public readonly SolveOutcome $outcome,
        public readonly ?FilledBoard $board,
        public readonly ?string $reason,
        public readonly int $stepsTaken,
        public readonly int $backtracks,
        /** @var array<string,int> rule id => dead ends hit, the Advisor's forensic input */
        public readonly array $conflictTally = [],
    ) {
    }

    /** @param array<string,int> $conflictTally */
    public static function success(FilledBoard $board, int $stepsTaken, int $backtracks, array $conflictTally = []): self
    {
        return new self(SolveOutcome::Success, $board, null, $stepsTaken, $backtracks, $conflictTally);
    }

    /** @param array<string,int> $conflictTally */
    public static function infeasible(string $reason, int $stepsTaken, int $backtracks, array $conflictTally = []): self
    {
        return new self(SolveOutcome::Infeasible, null, $reason, $stepsTaken, $backtracks, $conflictTally);
    }

    /** @param array<string,int> $conflictTally */
    public static function inconclusive(string $reason, int $stepsTaken, int $backtracks, array $conflictTally = []): self
    {
        return new self(SolveOutcome::Inconclusive, null, $reason, $stepsTaken, $backtracks, $conflictTally);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === SolveOutcome::Success;
    }
}
