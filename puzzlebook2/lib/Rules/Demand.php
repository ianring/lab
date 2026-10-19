<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

use Puzzlebook2\Solver\ConsumedWords;

/**
 * What a rule needs, in the abstract, before any specific cell exists.
 *
 * $domainFactory builds the per-cell Domain for a Shaped reservation's cell
 * (role => BasePool-backed Domain) -- ignored for Exact demands, where
 * $wordChooser supplies the words directly instead.
 *
 * Note on scope: this v1-port covers every rule actually being ported with
 * Shaped scopes whose cells are domain-INDEPENDENT of each other (no real
 * cross-cell relation needed within the same reservation -- true of every
 * ported rule; LongShortNeighbourRule's two cells need only independent
 * lengths, nothing relating one to the other). A Shaped pair with a genuine
 * intra-scope relation (cell B's valid words depend on cell A's chosen
 * word) would need per-role sequential assignment with a constraint fired
 * between them -- not built here because nothing currently needs it, and
 * noted rather than silently unsupported.
 */
final class Demand
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
        public readonly Arity $arity,
        public readonly ScopeGenerator $generator,
        /**
         * How many cells one scope of this demand covers (1 for a single
         * cell, 2 for a pair, ...). Lets FeasibilityAnalyzer check the cell
         * budget -- sum(count x cellsPerScope) over the chain must equal the
         * board size -- before the solver ever runs.
         */
        public readonly int $cellsPerScope,
        /**
         * A cheap, rule-computed approximation of how much real supply
         * backs this demand -- e.g. the size of the underlying survivor
         * pool before any cell-specific exclusion. Used for MRV ordering
         * among Shaped slots (smaller = more constrained = resolved
         * earlier) and for FeasibilityAnalyzer's supply check; deliberately
         * NOT required to be exact, since exactness would mean
         * materializing the very thing Domain is designed to avoid
         * materializing.
         */
        public readonly int $rawSupplyEstimate = PHP_INT_MAX,
        /**
         * How many SCOPES this demand's geometry could ever offer on an
         * empty board -- 4 corners x pages for the corner rule, effectively
         * unbounded (the default) for "any free cell" filler. The binding
         * constraint on a demand is min(word supply, geometric capacity):
         * the corner rule's 84,000 usable words are irrelevant when only
         * 240 cells can ever host them -- which is exactly the case that
         * sent the first full-scale build into a 14-million-step dead end
         * when ordering looked at word supply alone.
         */
        public readonly int $geometricCapacity = PHP_INT_MAX,
        /**
         * Exact demands only. Signature:
         *   (ConsumedWords $consumed, int $seed, int $draw): ?array<string,string>  // role => word
         * Must enumerate DISTINCT choices deterministically for a given
         * seed as $draw counts 0, 1, 2, ..., returning null once choices
         * are exhausted (required -- the solver's systematic enumeration
         * ends on it). Never return a word in $consumed, and never the same
         * word for two roles.
         */
        public readonly ?\Closure $wordChooser = null,
        /** @var null|\Closure(string $role, int $cell): \Puzzlebook2\Solver\Domain */
        public readonly ?\Closure $domainFactory = null,
    ) {
        if ($cellsPerScope < 1) {
            throw new \InvalidArgumentException("Demand '{$label}' has cellsPerScope {$cellsPerScope}; must be at least 1.");
        }
        if ($arity === Arity::Exact && $wordChooser === null) {
            throw new \InvalidArgumentException("Demand '{$label}' is Exact but has no wordChooser.");
        }
        if ($arity === Arity::Shaped && $domainFactory === null) {
            throw new \InvalidArgumentException("Demand '{$label}' is Shaped but has no domainFactory.");
        }
    }
}
