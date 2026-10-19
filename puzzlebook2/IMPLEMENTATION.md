# Puzzlebook 2.0 — Implementation Plan

This document takes `ARCHITECTURE.md`'s decisions as fixed and works out how
to actually build them: concrete classes, method signatures, data
structures, and the step-by-step process of a build. Code blocks below are
PHP-flavored pseudocode — precise enough to implement directly from, not yet
real source files. Nothing in this document has been built.

Every section traces back to a specific commitment in `ARCHITECTURE.md`;
where this document makes an implementation choice `ARCHITECTURE.md` left
open, that's called out explicitly rather than silently decided.

## 1. Directory layout

```
puzzlebook2/
  bin/
    build.php            # feasibility -> solve -> verify -> write outputs
    check.php            # feasibility analysis only, no solving
    explain.php          # re-run with full conflict tracing, for debugging
  config/
    layout.json          # BoardSpec
    chain.json           # ordered rule list + per-rule parameters
  lib/
    Board/
      BoardSpec.php
      Adjacency.php
    Dictionary/
      Dictionary.php
      WordTags.php
      PrefixPools.php
      RuleIndexCache.php
    Rules/
      Rule.php                        # interface
      Demand.php
      Scope.php
      ScopeGenerator.php              # interface
      Generators/
        SingleFreeCellGenerator.php
        AdjacentPairGenerator.php
        FixedGroupGenerator.php
        ... (one per geometry family actually needed)
      SpellingRules/                  # concrete rules, ported from v1
      GeometricRules/
    Constraints/
      Constraint.php                  # interface
      ConstraintStore.php
      SingleCellPredicate.php
      PairedCellRelation.php
      NeighbourhoodExclusion.php
      GroupUniqueness.php
      GlobalCardinality.php
    Solver/
      Board.php
      Domain.php
      BasePool.php
      Trail.php
      TrailEntry.php
      ReservationQueue.php
      Solver.php
      Conflict.php                    # diagnostic record of a dead end
      Decision.php                    # stack entry: slot + trail mark + resume index
      SlotCommit.php
      SolveResult.php
      ConsumedWords.php
      PoolFloorEntry.php
      Rng.php
    Chain/
      ChainPlan.php                   # keep_fraction cascade, ported from v1
      ChainContext.php
    Feasibility/
      FeasibilityAnalyzer.php
      FeasibilityReport.php
    Output/
      BookWriter.php
      ChainRunner.php                 # elimination verification + eliminated/ reports
  web/                                # copied from v1 unchanged
  data/
    wordlist.txt
    cache/                            # RuleIndexCache + PrefixPools, persisted
  tests/
    Fixtures/
      TinyBoard.php                   # small BoardSpec + synthetic word list
    Solver/
    Rules/
```

## 2. Data layer

### 2.1 `BoardSpec`

```php
final class BoardSpec {
    public function __construct(
        public readonly int $pages,
        public readonly int $rows,
        public readonly int $cols,
    ) {}

    public function size(): int;
    public function linearIndex(int $page, int $row, int $col): int;
    public function coordsOf(int $cell): array;   // [page, row, col]
}
```

Direct carryover of v1's `Grid`, minus the knight/king-move-specific methods
— those move to `Adjacency` (below), because v1's `Grid` accreted one method
per geometry a rule happened to need (`knightNeighbours()`,
`surroundingNeighbours()`, `allCorners()`), which is exactly the kind of
rule-specific knowledge that should not live on the shared spec object.

### 2.2 `Adjacency` — pluggable neighbour patterns

```php
interface Adjacency {
    public function neighboursOf(int $cell, BoardSpec $spec): array;  // int[]
}

final class VerticalAdjacency implements Adjacency { /* cell ± cols, same page */ }
final class HorizontalAdjacency implements Adjacency { /* cell ± 1, same row */ }
final class KingAdjacency implements Adjacency { /* 8 neighbours, same page */ }
final class KnightAdjacency implements Adjacency { /* 8 knight-move cells, same page */ }
final class CallbackAdjacency implements Adjacency {
    public function __construct(private \Closure $fn) {}
    // for a geometry nobody's invented yet -- a rule can supply one inline
    // without the engine needing a named class for every possible pattern.
}
```

This directly answers the "crazy complex geometric rule" requirement: a new
geometric relationship is a new `Adjacency` implementation (or an inline
closure via `CallbackAdjacency`), never a change to `BoardSpec` itself.
`BoardSpec` only ever answers "where is this cell," never "what counts as
near it" — that question belongs entirely to whichever rule cares about it.

### 2.3 `Dictionary`, `WordTags`, caching

`WordTags` is a near-verbatim port of v1's version: pure, static,
rule-agnostic functions over a single word's spelling (`isVowel`,
`vowelCount`, `firstLetter`, `lastLetter`, `consonantVowelPattern`, etc.). No
changes needed — this was never the problem.

```php
final class Dictionary {
    public function __construct(array $words) {}
    public function wordTags(string $word): WordTagSet;  // cached per word, computed once at load
    public function all(): array;                        // the raw word list
    public function hash(): string;                       // for cache-keying downstream indexes
}
```

`PrefixPools` replaces v1's ad hoc `$prefixPools` array built once inside
`ChainBuilder::build()`. In v2 it's a first-class, independently testable
object, because it's exactly the thing that initializes every cell's domain
before the solver starts:

```php
final class PrefixPools {
    public function __construct(private Dictionary $dict, private array $chainRules) {}
    // words surviving every elimination rule BEFORE position $n in the chain
    public function poolAt(int $n): array;   // cached per n, computed once
}
```

`RuleIndexCache` generalizes v1's `WordIndexCache` — persisted, keyed by
`(ruleClass, ruleParams, dictionaryHash)`:

```php
final class RuleIndexCache {
    public static function remember(string $key, \Closure $build): array;
    // reads from data/cache/ if present and the dictionary hash matches;
    // otherwise builds via $build() and writes it.
}
```

A rule that needs a custom index (anagram-signature groups, last-letter
groups, whatever a future rule invents) builds it through this cache exactly
as v1's rules already do — this pattern was never the source of any bug in
v1 and is kept as-is.

## 3. Rules

### 3.1 The `Rule` interface

```php
interface Rule {
    public function id(): string;
    public function describe(): string;     // "words with a double E and ..."
    public function ruleText(): string;      // reader-facing instruction

    // Elimination semantics -- pure function of a SOLVED board.
    // $scope is the specific cells this rule's pattern is being checked
    // against (one cell for a spelling rule, two for a pair, etc.)
    public function eliminates(FilledBoard $board, Scope $scope): bool;

    // Construction semantics.
    public function demand(ChainContext $ctx): Demand;
    public function constraintsFor(Reservation $reservation): iterable; // Constraint[]
}
```

`eliminates()` is checked by `ChainRunner` (§8) over every scope the rule's
geometry could possibly apply to on the *final, solved* board — for a
spelling rule that's every single cell; for a pair rule, every adjacent pair
in the relevant direction; for `SurroundedCaptureRule`, every interior cell
and its 8 neighbours. This is unchanged in spirit from v1's `apply()`, and
is **not** where construction-time guarantees live — those are `demand()`
and `constraintsFor()`'s job, entirely.

### 3.2 `Demand`

```php
final class Demand {
    public function __construct(
        public readonly string $label,
        public readonly int $count,               // how many scopes this rule needs
        public readonly Arity $arity,             // Exact or Shaped (see 3.3)
        public readonly ScopeGenerator $generator,
        public readonly int $cellsPerScope,       // 1 for a single cell, 2 for a pair, ...
        public readonly int $rawSupplyEstimate = PHP_INT_MAX, // MRV key + Phase-0 supply check
        public readonly ?\Closure $wordChooser = null,   // Exact only; see below
        public readonly ?\Closure $domainFactory = null, // Shaped only: (role, cell) -> Domain
    ) {}
}

enum Arity { case Exact; case Shaped; }
```

(As built, this diverged from the first draft in three ways. There is no
`$predicate` closure — a Shaped demand's shape lives entirely inside the
`BasePool` its `$domainFactory` wraps, so a separate predicate would be a
second copy of the same knowledge. `$cellsPerScope` was added so
`FeasibilityAnalyzer` can check the chain's cell budget — sum of
`count × cellsPerScope` must equal the board size — before solving. And the
`wordChooser` signature is
`(ConsumedWords $consumed, int $seed, int $draw): ?array<string,string>`:
it must enumerate *distinct* choices deterministically for a given seed as
`$draw` counts up from 0, returning `null` when exhausted — that `null` is
how the solver's systematic enumeration of an Exact slot's (draw × scope)
candidate space terminates.)

- **`Shaped`**: the solver finds cells via `$generator` and fills them with
  *any* word (drawn from the domain, which already encodes `$predicate`) —
  full fallback, exactly v1's ordinary `SpellingRule` cells and
  `LongShortNeighbourRule`'s cells.
- **`Exact`**: the rule supplies `$wordChooser`, which picks the *specific*
  real words up front (an anagram pair, a matching-ends pair) before any
  cell geometry is decided — mirroring v1's "reserve the words, then find
  geometry" pattern, which was always the right idea for rules that need a
  genuine partner word, not a shape. `Exact` reservations have zero
  fallback once committed, which is exactly why the solver schedules them
  first (§5.2) rather than relying on a hand-tuned priority number.

### 3.3 `ScopeGenerator` and `Scope`

```php
final class Scope {
    /** @param array<string,int> $cellsByRole e.g. ['top' => 500, 'bottom' => 506] */
    public function __construct(public readonly array $cellsByRole) {}
    public function cells(): array { return array_values($this->cellsByRole); }
}

interface ScopeGenerator {
    // Yields candidate scopes given the board's current state -- cells
    // already taken are never offered. Order is the generator's own
    // business (shuffled, geometry-first, whatever it needs); the solver
    // just consumes whatever comes out.
    public function candidates(BoardView $view): iterable; // Scope[]
}
```

Concrete generators, each a small, focused class:

```php
final class SingleFreeCellGenerator implements ScopeGenerator { /* any one free cell */ }

final class AdjacentPairGenerator implements ScopeGenerator {
    public function __construct(private Adjacency $adjacency) {}
    // yields ['a' => cell, 'b' => neighbour] for every still-free adjacent pair
}

final class FixedGroupGenerator implements ScopeGenerator {
    public function __construct(private array $groups) {}
    // for geometry known in advance and independent of fill order --
    // e.g. the corner rule's four corners per page
}
```

A brand-new geometric rule supplies its own `ScopeGenerator` implementation
(or an inline closure wrapped in a small adapter) — nothing about the solver
needs to know the shape in advance. This is the direct mechanism behind
"the system should be flexible enough that I could invent crazy complex
rules."

### 3.4 `constraintsFor()` — the self-contained cross-rule knowledge

```php
public function constraintsFor(Reservation $reservation): iterable
{
    // Example: SameEndingVerticalPairRule
    [$top, $bottom] = $reservation->scope->cells();
    yield new NeighbourhoodExclusion(
        anchorCells: [$top, $bottom],
        adjacency: new VerticalAdjacency(),
        forbid: fn (string $word) => WordTags::lastLetter($word) === $this->sharedLetter,
        exemptCells: [$top, $bottom],   // each other -- the wanted match
        reason: "would create an unplanned same-ending vertical match",
    );
}
```

This is called exactly once, immediately after a reservation commits (§5),
and the resulting constraints are added to the shared `ConstraintStore`
*before* the solver makes its next decision. Every subsequent candidate —
regardless of which rule is choosing it — is checked against the full,
accumulated store. No rule ever imports or references another rule's class.
This is the direct, literal implementation of the "rules are self-contained"
requirement: the knowledge of how to avoid colliding with *this* rule lives
in exactly one place, written by whoever invented the rule, and nowhere
else.

## 4. Constraints

```php
interface Constraint {
    // Given a candidate word for $cell, does it violate this constraint?
    public function violatedBy(int $cell, string $candidate, BoardView $view): bool;
    public function describe(): string;
}
```

Concrete shapes (from `ARCHITECTURE.md` §2.2), each a small, independently
testable class:

```php
final class SingleCellPredicate implements Constraint {
    public function __construct(private \Closure $predicate) {}
}

final class NeighbourhoodExclusion implements Constraint {
    public function __construct(
        private array $anchorCells,
        private Adjacency $adjacency,
        private \Closure $forbid,       // (string $word) -> bool
        private array $exemptCells,
        private string $reason,
    ) {}
    public function violatedBy(int $cell, string $candidate, BoardView $view): bool
    {
        if (in_array($cell, $this->exemptCells, true)) return false;
        foreach ($this->anchorCells as $anchor) {
            if (!in_array($cell, $this->adjacency->neighboursOf($anchor, $view->spec()), true)) continue;
            $anchorWord = $view->wordAt($anchor);
            if ($anchorWord !== null && ($this->forbid)($candidate)) return true;
        }
        return false;
    }
}

final class GroupUniqueness implements Constraint {
    // e.g. anagram-signature uniqueness per page -- maintains its own
    // per-group index rather than scanning, same idea as v1's
    // UniquePageSignature, generalized.
    public function __construct(
        private \Closure $signatureOf,
        private \Closure $groupKeyOf,    // e.g. "which page is this cell on"
        private array $exemptCells,
    ) {}
}

final class GlobalCardinality implements Constraint {
    // "exactly one cell may ever remain un-eliminated" -- checked
    // incrementally as assignments accumulate, not only at the very end.
}
```

`ConstraintStore` indexes constraints by which cells they could possibly
affect, so propagation (§5.4) never has to scan every constraint for every
candidate:

```php
final class ConstraintStore {
    public function register(Constraint $c, array $watchedCells): void;
    public function constraintsWatching(int $cell): array;  // Constraint[]
}
```

## 5. The solver

### 5.1 `Domain` — lazy, not materialized

A domain is **never** a fully-enumerated PHP array of candidate words — at
12,000 cells against a 133,000-word dictionary, materializing even a
fraction of that per cell is not viable. A domain is a *virtual* view: a
shared, cached base pool (keyed by prefix level + rule shape — this is
exactly v1's `prefixPools` idea, reused) plus a short, cell-specific list of
exclusions accumulated through propagation.

```php
final class Domain {
    public function __construct(
        private BasePool $basePool,          // shared across every cell with the same (prefix, shape)
        private array $exclusions = [],        // accumulated predicates
    ) {}

    public function isAssigned(): bool;
    public function assignedWord(): ?string;

    // Cheap existence probe: resumes from the base pool's shared scan
    // cursor (same trick as v1's Dictionary::fillerFromPool()) and stops
    // at the first candidate surviving every exclusion -- or confirms
    // none exists, which is the actual domain-empty detection.
    public function hasCandidate(): bool;
    public function firstCandidate(): ?string;

    // Approximate, NOT exact -- a cheap heuristic for ordering (5.2), not
    // a promise to enumerate the whole domain.
    public function estimatedSize(): int;

    public function addExclusion(\Closure $pred): void;
}
```

The one place this needs care: a cell with many exclusions accumulated (a
cell adjacent to several reservations) does a slightly more expensive scan
than one with none — but by construction, such cells are rare (most cells
are adjacent to nothing special) and are exactly the ones MRV ordering (5.2)
will have already prioritized early, when the board is emptiest and the
scan is cheapest. This is the resolution to the "how is domain-emptiness
checked without enumerating 133,000 words per cell" question that
`ARCHITECTURE.md` left implicit.

### 5.2 Reservation queue and most-constrained-first ordering

Every `Demand` expands into individual **reservation slots** — `count` of
them, each representing one scope still to be filled. All slots from every
rule go into one global queue, not processed rule-by-rule:

```php
final class ReservationQueue {
    public function push(ReservationSlot $slot): void;
    public function popMostConstrained(Board $board): ReservationSlot;
    // Ordering: Exact-arity slots always before Shaped; within each,
    // smaller estimated remaining scope-count first. Recomputed lazily --
    // this does not need to be a perfect priority queue, an approximate
    // one that's cheap to maintain is enough, since the exact order among
    // similarly-unconstrained ordinary filler slots genuinely doesn't matter.
}
```

This single structure is the direct replacement for v1's hand-tuned
`constructionPriority()` integers (`CornerContainsLetterRule` needing 20,
`LongShortNeighbourRule` needing 21, and so on, discovered by trial and
error over an entire session). In v2, "which slot gets resolved first" is a
computed property of how constrained it actually is *right now*, not a
number a rule author has to guess and periodically re-tune as new rules get
added.

### 5.3 Decision levels and the trail

```php
final class Trail {
    private array $entries = [];      // TrailEntry[]
    public function mark(): int { return count($this->entries); }
    public function push(TrailEntry $e): void { $this->entries[] = $e; }
    public function undoTo(int $mark, Board $board): void {
        while (count($this->entries) > $mark) {
            array_pop($this->entries)->undo($board);
        }
    }
}

interface TrailEntry {
    public function undo(): void;   // each entry holds direct refs to what it reverses
}

final class ReservationEntry implements TrailEntry { /* undoes one Board::claimCell() */ }
final class ConstraintRegistrationEntry implements TrailEntry { /* undoes a ConstraintStore::register() */ }
final class PoolFloorEntry implements TrailEntry { /* rewinds a BasePool floor advance */ }
```

Every mutation the solver makes — claiming a cell, registering a constraint
from `constraintsFor()`, advancing a pool's consumed-word floor — is pushed
onto the trail. A checkpoint is just "remember the trail's current length";
undoing one is popping and reversing entries back to that length. This is
the standard SAT/CSP trail-based undo technique, and it's what makes
backtracking cost *proportional to what actually changed* rather than "redo
everything after this point" — the direct fix for v1's single most
expensive recurring problem (a full refill to recover from one bad cell).

(Two entry types from the first draft do not exist as built. There is no
`ExclusionEntry` because domains never cache exclusions — `Domain` queries
`ConstraintStore` live, so registering/unregistering a constraint IS the
narrowing/un-narrowing, with no second bookkeeping structure to keep in
sync. And `PoolFloorEntry` was added for a reason the draft missed:
`BasePool`'s floor skips globally consumed words, but backtracking
*releases* words, so an unrewound floor would permanently strand them —
shrinking supply with every backtrack and tainting any later infeasibility
verdict. Entries also carry no decision level: chronological backtracking
never consults one.)

### 5.4 The core loop (as built)

```php
final class Solver {
    public function solve(Board $board, ReservationQueue $queue): SolveResult
    {
        $stack = [];   // Decision[]: {slot, trail mark, resumeIndex}

        while (!$board->isComplete()) {
            $slot = $queue->pop();                       // most-constrained pending slot
            $mark = $board->trail->mark();
            $outcome = $this->attemptFrom($slot, $board, 0);   // try candidates from index 0

            if ($outcome instanceof SlotCommit) {
                $stack[] = new Decision($slot, $mark, $outcome->candidateIndex + 1);
                continue;
            }

            // Conflict: the slot stays pending; backtrack chronologically.
            $queue->push($slot);
            while ($stack !== []) {
                $top = end($stack);
                $board->trail->undoTo($top->mark);       // erase the decision completely
                $retry = $this->attemptFrom($top->slot, $board, $top->resumeIndex);
                if ($retry instanceof SlotCommit) {      // its NEXT untried candidate worked
                    $top->resumeIndex = $retry->candidateIndex + 1;
                    continue 2;
                }
                array_pop($stack);                        // this decision is exhausted
                $queue->push($top->slot);                 // its slot becomes pending again
            }
            return SolveResult::infeasible(...);          // stack empty: a PROOF
        }

        return SolveResult::success(...);
    }
}
```

`attemptFrom(slot, board, startIndex)` enumerates the slot's candidates —
Shaped: the generator's scopes; Exact: (word draw × scope) pairs under one
flat index — skipping the first `startIndex`, and commits the first that
passes every check: domains non-empty for each cell, candidate words clean
against every registered constraint, and (last) the reservation's own
`constraintsFor()` output validated against every *already-assigned* cell it
watches before being registered. That last gate is the v2 answer to v1's
worst bug class (an invariant registered onto a board that already violated
it); it lives at the only point it can be checked exactly once and always.

A failed candidate undoes just its own claims (a trail mark per candidate)
and moves on; exhausting the whole enumeration is what produces a conflict.
There is no separate `propagate()` phase, by design rather than omission: a
free cell has no Domain object until the moment a reservation claims it
(which pool it draws from depends on which slot gets there first), so there
is nothing to forward-check into. Emptiness is discovered at the moment a
cell draws — still immediately for the decision that matters.

### 5.5 Why systematic-chronological, not backjumping (a revision)

The first draft of this section specified conflict-directed backjumping.
Implementation proved that wrong, and the reasoning is worth keeping:
backjumping is only *sound* when the conflict set — every reason each
candidate failed — is complete. Here it is not: a constraint rejection is
cheaply attributable to the decision that registered the constraint, but a
candidate can also fail because a word was consumed or a cell claimed by an
arbitrary earlier decision, and attributing those costs more than it saves.
Jumping on a partial conflict set silently skips live parts of the search
space: the solver would return "infeasible" for boards it never fully
searched. Slow-but-certain beats fast-but-wrong for this project's stated
priorities, so backtracking is chronological, and its systematic resume
indices are what guarantee no candidate is ever retried at the same node or
skipped. Constraint decision levels are still recorded —
`Conflict::$responsibleLevels` — but strictly as diagnostics for
explain/Advisor output. Full clause learning remains a legitimate future
enhancement, with the same completeness caveat to respect.

### 5.6 Completeness and termination

Each loop iteration either pushes a decision, advances some decision's
resume index, or pops an exhausted decision — the search tree is finite
(generators yield finitely many scopes; `wordChooser` must return null
eventually), so the solver terminates with success or an empty stack. The
empty stack is a genuine infeasibility proof, resting on two properties:
candidate sequences are reproducible (stateless seeded generators + exact
state restoration via the trail), and candidate availability is *monotone*
(claims and constraints only ever shrink what is possible, so a slot with
zero candidates at some node has zero at every deeper node). One caveat is
documented rather than hidden: Shaped cells draw words under a
deterministic first-valid-word policy — filler words are treated as
fungible — so the proof is "infeasible under that word-choice policy."
Scope and geometry alternatives are enumerated exhaustively. A step budget
(scaled to slot count) bounds pathological instances; exceeding it returns
`Inconclusive`, explicitly *not* a verdict.

## 6. Feasibility analysis

```php
final class FeasibilityAnalyzer {
    public function analyze(ChainPlan $plan, Dictionary $dict, BoardSpec $spec): FeasibilityReport
    {
        $report = new FeasibilityReport();
        foreach ($plan->rules() as $rule) {
            $demand = $rule->demand($plan->contextFor($rule));
            $supply = $this->estimateSupply($demand, $dict);
            if ($supply < $demand->count) {
                $report->addShortfall($rule->id(), $demand->count, $supply);
            }
        }
        foreach ($plan->rulesWithConstraints() as [$a, $b]) {
            $report->addInteractionWarning($this->estimateInteraction($a, $b, $dict));
        }
        return $report;
    }
}
```

This is a direct generalization of v1's `bin/check.php`, which already did
exactly this for demand/supply and even had a "rule-pair overlap" report
that was most of the way to estimating interactions. The difference in v2:
this isn't an optional diagnostic script run by hand — `bin/build.php`
refuses to start solving if `FeasibilityReport::$feasible` is false, and
reports the shortfall in plain language instead. This is the fast, cheap
answer to "is this possible" for the large majority of infeasible rule
sets (supply shortfalls); the full solver (§5) is the fallback for the
subtler cases this static pass can't rule out by counting alone.

### 6.1 The `Advisor`: turning a shortfall into a concrete suggestion

```php
final class Advisor {
    public function recommend(FeasibilityReport $report, ?Conflict $solverConflict, ChainPlan $plan): array
    {
        $recs = [];
        $recs = [...$recs, ...$this->rebalance($report, $plan)];
        $recs = [...$recs, ...$this->reorder($report, $solverConflict, $plan)];
        $recs = [...$recs, ...$this->flagIncompatibilities($report, $solverConflict, $plan)];
        return $recs;   // Recommendation[], sorted by estimated impact
    }
}

final class Recommendation {
    public function __construct(
        public readonly RecommendationKind $kind,   // Rebalance | Reorder | Incompatible
        public readonly array $affectedRuleIds,
        public readonly string $explanation,          // plain language
        public readonly ?array $suggestedParams = null,  // e.g. ['DoubleEFewVowelsRule' => ['keep_fraction' => 0.85]]
    ) {}
}
```

Each recommendation kind is a small, separately-testable method, reusing
data the system is already computing for other reasons rather than running
a new analysis:

- **`rebalance()`**: for every rule, `utilization = demand / supply`
  (already computed by `FeasibilityAnalyzer`). Flag rules above a high
  threshold (tight) and below a low threshold (slack). For each tight rule,
  find compatible slack rules — "compatible" meaning no declared
  `constraintsFor()` conflict between them — and propose a concrete
  `keep_fraction` adjustment sized to bring the tight rule back under
  threshold. This is a direct mechanization of the exact process used by
  hand, repeatedly, across the v1 session: find the rule with 300x unused
  supply, find the rule that's nearly out of room, shift load from one to
  the other.
- **`reorder()`**: consumes the solver's `Conflict` (§5.5) when a real solve
  was attempted and failed — the conflict already names which decision
  (which rule's reservation) narrowed a domain to empty, and at what chain
  position the responsible rule sits relative to the rule that needed the
  room. If swapping their chain order would mean the narrowing rule's
  `constraintsFor()` applies to a *smaller* remaining pool (because the
  other rule would then run first and already have reduced demand pressure
  before this rule's reservation), suggest the swap, with the resulting
  change to the `keep_fraction` cascade spelled out.
- **`flagIncompatibilities()`**: when a `Conflict`'s minimal responsible set
  is exactly two rules whose combination is unsatisfiable *regardless* of
  `keep_fraction` tuning — e.g. one rule's predicate guarantees a property
  (every candidate contains 'e') that another rule's constraint forbids at
  some fixed, non-empty set of cells, unconditionally — rebalancing can
  never fix this, and the recommendation says so plainly, naming the two
  rules and the exact incompatible properties, with a suggested remedy
  (narrow one rule's domain generator to exclude the conflicting cells, or
  retire one of the two rules).

`FeasibilityReport` is extended to carry per-rule utilization directly
(`utilizationOf(string $ruleId): float`), since that's the one number both
`rebalance()` and the plain-language report in §9 need.

## 7. Chain plan (kept, almost verbatim)

```php
final class ChainPlan {
    public function __construct(array $ruleConfigs) {}
    public function budgetsFor(int $available, int $bookSize): array;  // same cascade math as v1
    public function rules(): array;
    public function contextFor(Rule $rule): ChainContext;
}
```

The `keep_fraction` cascade — each rule's budget is a fraction of what's
left, with the final rule absorbing the remainder — was never wrong in v1.
It's ported with no material change; `ChainContext` is what exposes
`survivorsSoFar()`, the prefix pool, and the rule's own budget to
`demand()`.

## 8. Verification and output

### 8.1 `ChainRunner` — elimination verification pass

Once the solver produces a fully-assigned board, run every rule's
`eliminates()` in chain order over the real, final words — exactly v1's
`Solver::run()` / `verify()`. This is deliberately **not** trusted to be
redundant with the solver's guarantees: it's the independent, from-scratch
confirmation that the board the solver built actually narrows to exactly one
survivor when read the way a human reader would read it. If this ever
disagrees with what the solver believed it had built, that's a modeling
bug in some rule's `constraintsFor()`, and this is what catches it before
the book ships, not after.

### 8.2 `BookWriter` — unchanged output contract

```php
final class BookWriter {
    public function write(FilledBoard $board, ChainPlan $plan, string $outDir): void;
    // produces book.json, chain.json, pages.txt -- identical schema to v1,
    // so web/index.html requires no changes at all.
}
```

`book.json`: `{grid: {pages, rows, cols}, words: [...]}`, flat reading order.
`chain.json`: ordered `steps[]` with `rule_text`, `pattern_text`,
`construction` metadata, `built_at`, `answer`. This is treated as a hard
contract (ARCHITECTURE.md §9), not a target to hit approximately.

## 9. CLI entry points

- **`bin/check.php`**: load config, run `FeasibilityAnalyzer` only, print
  the report, exit. No solving. Seconds, not minutes, for the common case.
  If infeasible (or even just tight), also runs `Advisor::recommend()` and
  prints the suggestions — "X is at 95% of capacity, Y is at 2%, consider
  shifting load" — so the common case never needs a full solve just to
  learn what to adjust.
- **`bin/build.php`**: `check` first (abort with the report *and
  recommendations* if infeasible), then `Solver::solve()`, then
  `ChainRunner` verification, then `BookWriter`. Prints live progress
  (cells assigned, current backtrack depth, which constraint is causing the
  most backtracks) throughout — this is the direct, structurally-grounded
  replacement for v1's retry-reason tally, since v2 actually knows *which*
  constraint is responsible for each backtrack rather than inferring it
  from a repeated string. If the solver itself proves infeasibility (a
  subtler case the static check couldn't rule out), the resulting
  `Conflict` is fed back into `Advisor::recommend()` too, so a solver-level
  failure gets the same "here's what to change" treatment as a
  feasibility-level one, not just a conflict dump.
- **`bin/explain.php`**: re-runs with verbose conflict tracing turned on —
  every backtrack prints its full `Conflict` (which decision, which
  constraint, which cells) and the `Advisor` recommendation it implies, for
  debugging a new rule or a surprising infeasibility report.

## 10. Testing strategy

### 10.1 `TinyBoard` fixture

A small (e.g. 2 pages, 4×4) `BoardSpec` with a hand-picked, tiny synthetic
word list, specifically chosen to make certain interactions deterministic
and inspectable. Every rule and every solver behavior gets tested against
this first, at a scale where a human can read the entire board and verify
it by eye — long before any test runs at 100-page scale.

### 10.2 Per-rule tests

For every rule: does `demand()` produce a sane count given a synthetic
`ChainContext`? Does `eliminates()` correctly match the cases it should and
reject the cases it shouldn't, on hand-constructed boards? Does
`constraintsFor()` produce a constraint that, when checked against a
hand-picked violating word, actually returns true?

### 10.3 Interaction tests — the class that would have caught every v1 bug

This is the test category `ARCHITECTURE.md` §7's build order calls out as
the first thing worth writing once a rule has real `constraintsFor()`:

> Introduce the first rule with real `constraintsFor()` and specifically
> test that an *unrelated* rule's ordinary filler correctly gets excluded
> from violating it.

Concretely: place rule A's reservation on `TinyBoard`, then assert that rule
B's domain generator, run immediately afterward, never offers a candidate
that would violate A's registered constraint. Every bug chased across this
entire v1 session — the corner/E conflict, the length-9-vs-3 orphaning, the
vertical-ending orphaning, the corner-clustering bias — is exactly this
shape: two specific rules, one constraint, one violation that should have
been structurally impossible. A generic test harness that takes *any two
rules* and asserts "neither can produce a board state where one's
constraint is violated by the other's candidate" turns that whole category
of expensive, late-discovered production bug into a fast, deterministic,
pre-merge test failure.

## 11. Worked example: `SameEndingVerticalPairRule`, end to end

```php
final class SameEndingVerticalPairRule implements Rule
{
    public function id(): string { return 'same-ending-vertical-pair'; }
    public function describe(): string { return 'vertically adjacent pairs ending in the same letter'; }
    public function ruleText(): string { return 'Going down each column, find every pair of vertically adjacent words that are both still standing, where both words end in the same letter. Cross out both words of the pair.'; }

    public function eliminates(FilledBoard $board, Scope $scope): bool
    {
        [$top, $bottom] = $scope->cells();
        return WordTags::lastLetter($board->wordAt($top))
            === WordTags::lastLetter($board->wordAt($bottom));
    }

    public function demand(ChainContext $ctx): Demand
    {
        $pairsWanted = min(intdiv($ctx->budget(), 2), $ctx->densityCap('pairs_per_page', 1.2));
        return new Demand(
            label: 'vertical-ending-pair',
            count: $pairsWanted,
            arity: Arity::Exact,
            generator: new AdjacentPairGenerator(new VerticalAdjacency()),
            predicate: fn (array $words) => WordTags::lastLetter($words['top']) === WordTags::lastLetter($words['bottom']),
            wordChooser: function (ChainContext $ctx) {
                // pick two real, unreserved survivors sharing a last letter --
                // ported directly from v1's lastLetterGroups() index, via RuleIndexCache
                [$top, $bottom] = $this->pickRealPair($ctx);
                return ['top' => $top, 'bottom' => $bottom];
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        [$top, $bottom] = $reservation->scope->cells();
        $letter = WordTags::lastLetter($reservation->wordAt($top));
        yield new NeighbourhoodExclusion(
            anchorCells: [$top, $bottom],
            adjacency: new VerticalAdjacency(),
            forbid: fn (string $word) => WordTags::lastLetter($word) === $letter,
            exemptCells: [$top, $bottom],
            reason: 'would create an unplanned same-ending vertical match',
        );
    }
}
```

Compare this to what v1 actually needed: a hand-written
`hasOuterNeighbourCollision()` construction-time check (protecting only
against *already-placed* literals), a separately-invented
`NoUnplannedVerticalEndingMatch` `FillInvariant` (added only after a
production failure revealed the gap), a `$partnerOf` exemption map threaded
through by hand, and a manually-tuned low `constructionPriority()` so the
invariant would exist before other rules needed it. In v2, all of that
collapses into the one `constraintsFor()` method above, checked
automatically and uniformly for every other rule, with no priority number to
guess and no separate invariant class to remember to register.

## 12. Worked example 2: a rule that doesn't exist yet

To stress-test whether §3's abstraction is actually general enough — not
just convenient for the rules already known — consider a rule invented on
the spot: *"two words on the same row, three columns apart, whose Scrabble
scores differ by exactly a prime number."*

- **Geometry**: `AdjacentPairGenerator` doesn't fit (not adjacent); this
  needs a small new generator, `FixedOffsetPairGenerator(offset: 3, axis:
  horizontal)` — one new, focused class, not a change to the engine.
- **Predicate**: `WordTags::isPrime(abs(scrabbleScore($a) - scrabbleScore($b)))`
  — both helpers already exist in v1's `WordTags` and port unchanged.
- **Arity**: `Shaped` is viable here (no specific real-word pairing is
  required, just a numeric relation) — meaning, notably, this rule would
  have full dictionary fallback and impose *no* `constraintsFor()` at all,
  the same way `LongShortNeighbourRule` turned out to in v1. The engine
  doesn't need to be told this in advance; it falls out of the rule author
  choosing `Shaped` because nothing about the rule actually requires a
  specific partner word.
- **Result**: a new rule, fully expressible, with zero changes to the
  solver, the constraint model, or any existing rule. This is the concrete
  test of the "invent crazy complex rules and the engine either builds the
  book or tells me it's impossible" requirement.

## 13. Implementation order

More granular than `ARCHITECTURE.md` §11, file by file:

1. `BoardSpec`, `Adjacency` implementations, `Dictionary` + `WordTags`
   (ported) — no solving, just the data layer. Unit-testable immediately.
2. `Trail`, `TrailEntry` implementations, `Domain`, `BasePool` — the
   undo/domain machinery, tested against `TinyBoard` with zero rules:
   assign a cell by hand, confirm propagation and undo both work correctly
   on a toy constraint.
3. `Constraint` interface + `SingleCellPredicate`, `NeighbourhoodExclusion`
   — the two shapes needed by the simplest real rules. `GroupUniqueness`
   and `GlobalCardinality` follow once a rule actually needs them.
4. `Rule` interface, `Demand`, `Scope`, `ScopeGenerator` +
   `SingleFreeCellGenerator`, `AdjacentPairGenerator`.
5. `Solver` core loop (§5.4, systematic chronological backtracking — §5.5
   explains why not backjumping) against `TinyBoard`, with 2-3 trivial
   ported spelling rules (no `constraintsFor()` needed) — confirm a
   complete, correct board comes out the other end.
6. Port one real pair rule (§11) and write the interaction test (§10.3)
   against a deliberately unrelated ordinary rule on `TinyBoard` — this is
   the single most important test in the whole project, because it's the
   one that would have caught every expensive bug from v1's build.
7. `ChainPlan` (ported cascade math) + `FeasibilityAnalyzer` (§6) — wire up
   `bin/check.php`.
8. `ChainRunner` + `BookWriter` (§8) — wire up `bin/build.php` end to end at
   `TinyBoard` scale.
9. Port the remaining v1 rules, one at a time, each gated by its own unit
   test and at least one interaction test against whichever existing rules
   share a constraint shape.
10. Scale up: small page counts first, 100 pages last. Compare output
    against the existing `web/index.html` previewer using a real v2-built
    book before calling this done.

## 14. Implementation-level risks worth flagging now

- **Domain existence-checking cost.** §5.1's lazy scan is cheap when
  exclusions are few, but a cell adjacent to many reservations could, in a
  pathological rule set, require a longer scan. Worth profiling once real
  rules are ported, not worth pre-optimizing before there's a real rule set
  to measure against.
- **Chronological backtracking depth.** §5.5 explains why backjumping was
  rejected (unsound on incomplete conflict sets). The cost is that a deep
  conflict can force the search back through unrelated decisions. MRV
  ordering keeps the constrained decisions early (so conflicts tend to be
  shallow), and the step budget turns pathology into an honest
  `Inconclusive` — but if profiling at full book scale shows thrashing,
  clause learning with COMPLETE attribution is the documented next step,
  not a surprise.
- **`ScopeGenerator` exhaustion vs. genuine conflict.** §5.4 treats "no
  candidate scope available" as an immediate conflict. For the vast
  majority of geometric generators this is correct (cells are never freed
  once assigned, so "none available now" means "none ever," given current
  assignments). If a future rule's generator is somehow order-sensitive in
  a way that makes this distinction matter, that's worth a dedicated test
  before trusting the default behavior for it.
