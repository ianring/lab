# Puzzlebook 2.0 — Architecture Plan

This is a green-field redesign, written with full knowledge of how version 1
actually behaved in practice. It is a plan, not code — nothing here has been
built yet. The goal is to settle the hard architectural questions on paper
before writing a single rule, so that the mistakes v1 made slowly and
expensively get caught here, cheaply, instead.

## 0. What v1 actually taught us

Every costly bug in v1 had the same shape: rule A didn't know about rule B's
requirement, so it committed a word that violated it, and the violation was
only discovered later — sometimes much later, after filling thousands of
cells — rather than prevented at the moment it happened. The fixes v1 ended
up with (invariants, `wordSatisfiesInvariants()`, low construction
priorities so one rule's invariant would exist before another rule needed
it) were all converging, piece by piece, on the same destination: a proper
constraint-propagation system. V1 arrived at pieces of that system reactively,
under production pressure, after each piece's absence had already caused a
failure. V2's thesis is to build that system **first**, deliberately, as the
foundation everything else sits on — not as an emergent side effect of
patching bugs.

The specific failure modes worth naming, because each one maps to a specific
decision below:

- **Order dependence.** Whether a collision was caught depended on which rule
  happened to run before which. Fix: constraints are declared before any word
  is assigned, so order stops mattering.
- **No-fallback words discovered late.** A literal, pre-committed word (an
  anagram pair, a matching-ends pair) has nothing to fall back to if it turns
  out to violate something — and v1 often didn't find out until deep into a
  10-minute fill pass. Fix: assign the most-constrained cells first, and
  propagate immediately, so a dead end is caught in microseconds, not minutes.
- **Full-attempt retries for local problems.** v1's `fillWords()` was one
  uncheckpointed pass; any failure anywhere meant redoing everything. Fix:
  checkpointing and backtracking live inside the solver's core loop, at the
  granularity of individual cell assignments.
- **Collision-avoidance logic duplicated per rule.** Three different pair
  rules each hand-wrote their own "check my neighbours" logic, and it was
  easy for a new rule to simply forget to. Fix: a rule declares *what* it
  needs; a single shared solver enforces *all* declarations uniformly. No
  rule's correctness depends on another rule remembering it exists.
- **No confident answer to "is this even possible."** v1 would either succeed,
  or burn hours of random retries before the user gave up and interrupted it.
  Fix: the system is either exhaustively searching toward a provable yes/no,
  or it has already told you no and why — never "trying forever with no
  guarantee."

## 1. High-level shape

Puzzlebook generation is a **constraint satisfaction problem (CSP)**, and v2
treats it as one explicitly, rather than growing into one by accident.

- **Variables**: every cell in the grid. A 100-page, 20×6 book has 12,000
  variables.
- **Domain**: the set of words that could legally go in that cell, right now,
  given everything decided so far. Starts as (approximately) the whole
  dictionary; shrinks monotonically as constraints propagate.
- **Constraints**: declared by rules. Each one names a *scope* (a set of
  cells — one, two, nine, or a dynamically-discovered set) and a predicate
  over the words assigned to that scope.

Two concerns that v1 mixed together inside each rule are split cleanly in v2:

1. **Elimination semantics** — given a *fully solved* board, which cells does
   this rule cross out? This is a pure, position-and-content function, same
   spirit as v1's `apply()`. It is also used by the web previewer / solver
   report, unchanged in spirit from v1.
2. **Construction semantics** — what does this rule need in order for its
   elimination semantics to produce the *intended* result, and what does it
   require of cells it doesn't own? This is the new, carefully-designed half,
   described in detail below.

The engine never does "build greedily, catch problems after." It does
**assign-propagate-backtrack**: every single word placement immediately
narrows every other cell's options that could possibly be affected, and if
that ever drives a cell's domain to empty, the engine backtracks to the
specific decision that caused it — not to "the start of this rule" or "the
start of the attempt," but to the exact assignment, wherever it was, that the
empty domain can be traced to.

This gives two properties v1 never had:

- **Locality of failure.** A bad decision is caught the moment it's made,
  while the search is still sitting right next to it — not three rules and
  4,000 cells later.
- **Completeness.** Because the search is systematic (every live branch is
  tracked, not randomly re-rolled), the engine either finds a valid book or
  *proves none exists under the current rule set*, given enough time. There
  is no "ran for two hours, no idea if it'll ever succeed." It's either still
  productively narrowing the search space, or it's already told you the
  answer.

## 2. Rules: self-contained, declarative, and ignorant of each other

This is the most important interface in the system, because it's the one the
user (Ian) writes against every time he invents a new rule. A rule must be
describable in isolation — "cross out every word where X" — and the
mechanical work of not stepping on other rules must never require knowing
what those other rules are.

### 2.1 The rule interface

```
interface Rule {
    // --- elimination semantics (unchanged in spirit from v1) ---
    function failsOrMatches(Board $solvedBoard, Scope $scope): bool;
    function describe(): string;          // "words with a double E and ..."
    function ruleText(): string;           // reader-facing instruction

    // --- construction semantics (the new part) ---
    function demand(ChainContext $ctx): Demand;
    function constraintsFor(ReservationHandle $reservation): iterable<Constraint>;
}
```

- `failsOrMatches` is a pure function of the final board. For a one-cell
  spelling rule, `$scope` is a single cell. For a pair rule, it's two cells.
  For "surrounded capture," it's a center cell plus its eight neighbours. The
  *shape* of the scope is part of what the rule declares (see 2.2) — nothing
  about the engine assumes "rules only ever care about one cell," the way
  v1's `SpellingRule` base class implicitly did.

- `demand($ctx)` answers "how much do you need, and what counts as
  satisfying you" in the abstract, before any specific cells exist. For most
  rules this is a count (how many cells, derived the same way v1's
  `keep_fraction` cascade worked — that cascade logic is still correct and
  is kept, see §6) plus a *shape generator*: a closure that, given the
  solver's current state, can produce fresh candidate scopes (e.g., "any two
  still-free, vertically-adjacent cells" for a vertical pair rule; "any
  single free cell" for an ordinary spelling rule; "the four corner cells of
  page N" for a corner rule).

- `constraintsFor($reservation)` is the piece that did not exist as a clean,
  first-class concept in v1 (it existed, piecemeal, as `FillInvariant` — this
  generalizes that idea and makes it the *primary* mechanism, not a bolt-on).
  Once a rule reserves a scope (a pair of cells, say), it hands back a list
  of `Constraint` objects that describe what *any other* word, placed
  *anywhere else on the board*, must respect relative to this reservation. A
  vertical-pair rule says: "no word adjacent to either of these two cells,
  other than the two cells themselves, may share their last letter." A
  corner rule says: "no word in any corner cell I didn't personally claim may
  contain 'e'." These constraints get added to the shared pool the instant
  the reservation is made, and from that moment on, *every* rule's candidate
  checks — not just the declaring rule's — are automatically subject to them,
  because the solver checks the full accumulated constraint pool for every
  candidate, unconditionally, as a matter of course. No rule ever needs to
  know `SameEndingVerticalPairRule` exists. It just inherits the restriction
  that rule put in place, the same way every other candidate in the universe
  does.

This directly satisfies the requirement that rules encapsulate their own
cross-rule knowledge: the *content* of "how to avoid colliding with me" is
100% inside the rule that owns the concern. The *mechanism* that makes every
other rule respect it is a single, generic, rule-agnostic solver behavior.
Nothing about this requires a rule to import, reference, or even be aware of
any other rule's class.

### 2.2 Constraint shapes the engine supports natively

To keep the engine generic rather than special-cased per rule family, every
rule-declared constraint is one of a small number of *shapes*, each with a
known, efficient propagation strategy:

| Shape | Example | Propagation |
|---|---|---|
| **Single-cell predicate** | "this word fails `double-E` " | Filter this cell's domain directly. |
| **Paired-cell relation** | "last letter of A = first letter of B" | When A is assigned, filter B's domain to compatible words, and vice versa. |
| **Neighbourhood exclusion** | "no neighbour of this reserved cell may share its last letter, except its designated partner" | When the cell is assigned, filter every geometric neighbour's domain to exclude the forbidden letter. |
| **Group uniqueness** | "no two words on this page may share a signature, except these two" | Maintain a per-page signature index; filtering is a lookup, not a scan. |
| **Global cardinality** | "exactly one cell survives every rule" | Checked incrementally as the "still standing" count changes, not only at the end. |
| **Emergent/derived** | "surrounded capture": no cells reserved in advance, purely a function of what's already dead by the time this rule is evaluated | Not a construction-time constraint at all — flagged as `derived`, evaluated only in elimination semantics, exactly as in v1. |

A rule that needs a genuinely new shape can add one, but the expectation is
that the vast majority of "crazy complex rules" Ian invents will decompose
into a *combination* of these primitives (e.g., "two words, nine cells apart
along a diagonal, where one is the reverse of the other's consonants" is a
paired-cell relation with an unusual geometry generator, not a new shape).
The engine's job is to make combining primitives cheap, not to anticipate
every possible rule in advance.

### 2.3 Reserved vs. free words — made explicit, and driving solver order

v1 discovered, rule by rule, that some cells commit to one specific real word
with zero fallback (an anagram pair's two members; both ends of a
matching-letters pair), while others merely need *some* word of a given
shape (ordinary spelling-rule filler) and can always try another candidate
if the first doesn't pan out. This distinction was never named explicitly in
v1's model — it was implicit in which constructor a rule happened to call
(`CellPlan::literal()` vs. an ordinary predicate-based plan), and discovering
*which rules needed to go first because of it* was done by hand, by trial and
error, across this entire project.

In v2, every `Demand` declares its **arity**: `exact` (this scope must
contain these specific pre-chosen words, no substitution possible) or
`shaped` (any word satisfying the predicate works). This is used directly by
the solver's variable-ordering heuristic (§3.2): cells with `exact` demand,
or with small remaining domains, are assigned first, as a matter of general
policy — not because some rule author remembered to mark its priority low.
A new, complicated rule that happens to commit to specific real words
automatically gets scheduled early, for free, because it's the solver's job
to notice "this has only one possible value" and resolve it first, not the
rule author's job to guess at a priority number.

## 3. The solver

### 3.1 Algorithm

Backtracking search with constraint propagation (forward checking / a
restricted form of arc-consistency), the standard, well-understood approach
to CSPs — not reinvented, just applied properly and from the start instead of
discovered in fragments.

```
function solve(board):
    while not every cell assigned:
        if any cell's domain is empty:
            cell = cause of the empty domain
            backtrack to the decision that narrowed cell's domain to empty
            continue

        cell = pick_most_constrained_unassigned_cell()
        if cell.domain is empty:
            report infeasibility, with the constraint set that proves it
            return FAILURE

        word = pick_candidate(cell.domain)      // see 3.3 on ordering within a domain
        assign(cell, word)
        new_constraints = collect_constraints_declared_by(cell, word)
        propagate(new_constraints)              // narrow every affected domain
        checkpoint()                            // cheap: see 3.4

    return the completed board
```

This differs from v1's "construct, then fill, then verify" in one essential
way: there is no separate fill phase. Construction *is* filling. A cell's
word is chosen as part of the same propagating search as every other cell's,
so there is no window in which one rule's decision is invisible to another
rule's check because "construction" hasn't gotten to "fill" yet.

### 3.2 Variable ordering: most-constrained-first

At every step, the solver picks the unassigned cell with the smallest
remaining domain (classic CSP "minimum remaining values" heuristic), with
`exact`-arity demands always sorted first regardless of raw domain size,
since those are the ones with zero room to improvise. This is precisely the
behavior v1 had to discover and hand-encode per rule (`CornerContainsLetterRule`
needing priority 20, `LongShortNeighbourRule` needing priority 21, and so on,
tuned by trial and error over an entire session). In v2 it is the solver's
default behavior for every rule, including ones that don't exist yet.

### 3.3 Candidate ordering within a domain

Within a cell's remaining domain, candidates are tried in an order that
prefers words *least likely to tighten other cells' domains unnecessarily* —
e.g., preferring a word that doesn't itself introduce a new, rare constraint
(a word that happens to be exactly the "dangerous" length for some unrelated
rule, say) over one that does, when the choice is otherwise arbitrary. This
is a pure performance tactic (it reduces thrashing, not correctness), is
genuinely optional, and should be implemented after the first working
version, informed by real profiling rather than guessed in advance.

### 3.4 Checkpointing and backtracking, at cell granularity

A checkpoint is the minimal state needed to undo the *consequences* of one
assignment: which cells had their domains narrowed as a direct or indirect
result, and what their domains were before. Because propagation is
localized (only cells reachable through some declared constraint are ever
touched), checkpoints are small and cheap relative to the board size — not a
copy of the whole board, and never a redo of the whole fill pass. This is
the direct fix for v1's single most expensive recurring cost: a local
problem discovered after 8,000 cells were already filled meant refilling all
8,000. In v2, undoing one bad assignment costs proportional to how far its
consequences actually propagated, which for most constraints is a handful of
neighbouring cells, not the whole board.

Backtracking is **systematic and chronological**: every decision on the
stack remembers how far through its candidate sequence it has gotten (a
resume index, meaningful because candidate order is a pure function of the
slot's fixed seed and the restored board state), and a conflict always
retries the most recent decision at its *next untried* candidate — never a
reshuffle, never a repeat, never a skip. When a decision's candidates are
exhausted, it pops and the search continues one decision deeper; an empty
stack is therefore a genuine proof of infeasibility.

Conflict-directed backjumping was considered and deliberately **rejected**
(a revision of this document's earlier position, made during
implementation): backjumping is only sound when the recorded conflict set is
*complete*, and failures caused by consumed words or already-claimed cells
are not cheaply attributable here. Jumping on a partial conflict set
silently skips live parts of the search space — it converts "slow but
certain" into "fast but wrong," which is the wrong trade for this project.
Constraint decision levels are still recorded, but only to make conflict
*diagnostics* (the explain/Advisor output) more specific.

### 3.5 Completeness and the "tell me it's impossible" requirement

Because the search space is explored systematically — every assignment that
gets ruled out is ruled out *because* propagation proved it can't lead
anywhere, not because a random seed happened not to try it — the solver has
exactly two possible outcomes for a given rule set and board size:

1. **A complete, valid book.** Every cell assigned, every rule's demand met,
   every constraint satisfied, exactly one survivor.
2. **A proof of infeasibility.** The search has exhausted every live branch
   and found none that work. Critically, the solver reports *why*: the
   minimal set of rules whose combined demands cannot be jointly satisfied
   (a standard CSP concept, "minimal unsatisfiable subset" / conflict
   explanation). "Rule X and rule Y together need more words of shape Z than
   the dictionary has, once rule W's exclusions are accounted for" is an
   answerable, inspectable statement, not a shrug after two hours of silence.

There is no third outcome where the engine just keeps trying forever with no
information about whether success is possible. That is the literal
replacement for v1's `attempt()` loop, and it is the single biggest
difference between the two versions.

Honest caveat: general CSPs are NP-hard, and nothing about using proper CSP
technique changes that fact in the worst case. What changes is that the time
spent is *always* either producing a definite answer or narrowing toward
one — never spent on uninformed, repeated random guessing with no
convergence guarantee. For the specific structure of this problem (a large
majority of cells carry weak, easily-satisfied constraints; a small minority
carry the tight, interacting ones), well-propagated backtracking search is
expected to be fast in practice, and the static feasibility pre-check in §4
is specifically there to catch the common failure cases — demand exceeding
supply — without needing the full solver at all.

## 4. Feasibility analysis before the solver ever runs

v1's `bin/check.php` already did something very close to this — computing,
per rule, the demand (cells needed) and supply (matching words available
given the prefix of earlier rules) and flagging any rule whose demand
exceeded supply before ever attempting a real build. That tool's instinct
was exactly right; v2 keeps it and generalizes it into the mandatory first
phase of every build, not an optional, separately-run diagnostic.

Phase 0, run before the solver starts:

1. For every rule, compute its theoretical demand (from the elimination
   cascade — unchanged math from v1's `keep_fraction` system, see §6) and its
   theoretical maximum supply (words matching its shape, surviving every
   earlier rule's elimination).
2. For every pair of rules with declared cross-cutting constraints (§2.1),
   estimate the *interaction* cost — e.g., "rule A needs N pairs; rule B
   independently forbids a specific property near any cell it reserves; does
   A's remaining supply, after B's exclusions, still clear A's demand?" This
   is a cheap arc-consistency pass over the *demand* numbers, not a full
   word-by-word search — closer to v1's "rule-pair overlap" report than to
   the real solver.
3. Report every violation found, in plain terms, before spending any time on
   actual construction. This is the fast, cheap way to say "no" to most
   impossible rule sets — the full solver (§3) is the fallback for subtler
   cases that this static pass can't rule out in advance, and is expected to
   be needed rarely.

### Beyond pass/fail: recommending a path to buildable

Ian is explicitly not married to any particular rule, rule set, or chain
order — the highest priority is always ending up with a successful book.
That changes what the system owes him on failure. "Infeasible" on its own
is not a complete answer; the complete answer is "infeasible, and here is
the smallest change that would fix it."

This falls directly out of work the engine is already doing for other
reasons, not a separate analysis bolted on afterward:

- **Rebalancing.** The feasibility pass already computes demand and supply
  for every rule. The same numbers expose *utilization* — demand as a
  fraction of supply. A rule sitting at 95% of its ceiling next to another
  sitting at 2% is exactly the pattern that, this session, had to be found
  by hand, repeatedly, by eyeballing `bin/check.php` output (the corner
  rule's density, `WordLength10Rule`'s enormous unused headroom, and so on).
  The system should surface this automatically: "rule X is nearly out of
  room; rule Y has far more than it's using; shifting some of X's load onto
  Y would relieve this" — with concrete suggested parameter changes, not
  just the observation.
- **Reordering.** Chain position still matters in v2 even though
  cell-by-cell construction order no longer needs manual tuning (§3.2
  handles that automatically): it determines the `keep_fraction` cascade
  (what's "left" for a later rule depends on exactly which rules already
  ran) and which words survive long enough to reach a given rule at all.
  When a conflict or shortfall traces back to "rule B's requirement didn't
  exist yet when rule A needed it" — a question the solver's conflict
  explanation (§7) already answers for the construction-time case, and the
  static interaction check already approximates for the chain-order case —
  the natural next step is a concrete suggestion: "move rule B earlier than
  rule A in the chain."
- **Flagging structural incompatibility.** Some conflicts aren't about
  quantity at all — they're two rules whose requirements are simply
  incompatible, no matter how the numbers are tuned (a rule that only ever
  produces words containing a certain letter, next to a rule that forbids
  that letter at some fixed set of cells, is unsatisfiable at *any* density).
  The solver's minimal-conflict explanation (§7) is exactly the right tool
  for recognizing this shape and naming it plainly, rather than leaving Ian
  to rediscover it by noticing a suspiciously repeated failure pattern.

None of this requires the system to be clever in a way it isn't already
being clever elsewhere — it's the feasibility numbers and the conflict
explanations, read as *recommendations* instead of only as *verdicts*. The
practical effect: when Ian proposes a new, half-formed rule idea, the system
should be able to answer not just "will this work" but "here's what would
need to change for it to work" — rebalance these two rules, reorder these
two steps, or this specific pair of rules can't coexist as written.

## 5. Caching and memoization

Precomputation is for speed; it never substitutes for correctness, and
nothing here is allowed to let an invalid book through in the name of being
fast. Every cache below is pure, derived data — safe to discard and rebuild
with no effect on the result, only on how long computing it takes.

- **Word tags**: length, first/last letter, vowel count, consonant-vowel
  pattern, and any other cheap-to-compute, rule-agnostic property — computed
  once per dictionary load, shared by every rule. (Direct carryover of v1's
  `WordTags`.)
- **Rule-specific indexes**: e.g., anagram-signature groups, last-letter
  groups — computed once per (rule class, rule parameters, dictionary
  version) and persisted to disk, exactly like v1's `WordIndexCache`. These
  are expensive to build once (scanning 130,000+ words) and cheap to reuse
  across every attempt and every future book that reuses the same rule.
- **Prefix-survival pools**: for each position in the elimination chain, the
  set of words surviving every rule up to that point. Direct carryover of
  v1's `prefixPools` concept — this is genuinely the right idea, it's just
  that in v2 it feeds domain *initialization* for the CSP solver rather than
  feeding an ungoverned random draw.
- **What's deliberately *not* cached**: the live, per-attempt domain state
  during solving. That's inherently per-build, mutates as propagation runs,
  and is exactly what checkpointing (§3.4) manages — caching it would just be
  reimplementing the solver's own state under a different name.

## 6. What stays, almost unchanged, from v1

Not everything about v1 was wrong — most of the mistakes were about *how*
construction searched for a solution, not about the puzzle model itself.
Kept, with little or no change:

- **The elimination-chain model**: a sequence of rules applied in order,
  each crossing out words that survived everything before it, ending in
  exactly one survivor. This is the actual reader-facing puzzle mechanic and
  has nothing wrong with it.
- **`keep_fraction` cascade math**: computing each rule's cell budget as a
  fraction of what's left, with the final rule absorbing the remainder. This
  arithmetic was always correct; the problem was always downstream, in how
  cells were filled once budgets were known.
- **Rules as inspectable, narratively-described cards**: one rule = one
  class with `describe()` / `ruleText()`, matching the "chain of simple
  patterns" structure of the actual book. Keep this framing; it's good for
  both the reader and for Ian inventing new rules by writing a short,
  self-contained class.
- **Density-relative tuning** (pairs per page scaling with book size rather
  than a fixed count): the *principle* was right — v1's mistake was applying
  it only after the fact, as a correction, rather than designing every
  demand to be naturally proportional from the start. v2's `demand()`
  should default to density-relative unless a rule has a specific reason not
  to.

## 7. Diagnostics and explainability

Every failure — feasibility-phase or solver-phase — must answer, automatically,
without the user needing to ask a follow-up question:

- Which rule(s) are responsible.
- Which cell(s), if relevant.
- What the actual conflicting values were (not a generic category — the
  specific words and cells), the same lesson learned from enhancing v1's
  `verify()` message mid-session, this time built in from day one rather than
  retrofitted after the third unexplainable failure.
- For infeasibility specifically: the minimal set of rules whose constraints
  can't be jointly satisfied, in plain language — "X and Y can't both be
  true because..." — not a stack trace.
- A concrete recommendation, not just the diagnosis — see §4's "recommending
  a path to buildable." Naming the responsible rules is necessary but not
  sufficient; the system should also say what to try changing.

A live progress view during solving (cells assigned, current backtrack
depth, which rule's constraint is most often the cause of a backtrack) is
worth building early, both for Ian's sanity during long builds and because
"which constraint causes the most backtracking" is itself useful signal for
deciding which rule to loosen, mirroring v1's `bin/build.php` heartbeat but
with real structural data behind it instead of a string tally.

## 8. Variable book size

`BoardSpec` (pages, rows, cols — and nothing else hardcoded) is a parameter
to every rule's `demand()` and `constraintsFor()`, threaded through from the
book's config file, exactly as v1's `Grid`/`layout.json` already do. Nothing
in the engine or in any rule may assume 100 pages, 120 words per page, or any
other specific number. The 100×120 case is simply the value most builds will
use, not a constant baked in anywhere. If a future book wants a non-rectangular
page shape, that would require extending `BoardSpec`'s geometry — worth
flagging as a known extension point, not worth building speculatively now.

## 9. Output: unchanged contract with the existing web previewer

V2's build produces exactly the artifacts v1 does, so `web/index.html` needs
zero changes:

- `book.json`: `{grid: {pages, rows, cols}, words: [...]}`, words in flat
  reading order.
- `chain.json`: ordered `steps[]`, each with `rule_text`, `pattern_text` (for
  reveal-style steps), `construction` metadata, `built_at`, and `answer`.
- `pages.txt`: the plain-text proof-reading rendering.

This is a hard compatibility contract, not a nice-to-have: the previewer, and
any tooling built against these files, should not need to know v2 exists.

## 10. Rule-authoring experience: worked examples

To ground the abstraction in §2, three representative v1 rules, re-expressed:

**A pure spelling rule** (`DoubleEFewVowelsRule`):
- `failsOrMatches`: single-cell scope, `contains('ee') && vowelCount <= 3`.
- `demand`: shaped, count from the keep_fraction cascade, shape = "any free
  cell whose word can satisfy the predicate."
- `constraintsFor`: none. A pure spelling rule has full fallback and imposes
  nothing on anyone else — this is the common case, and most rules will have
  an empty `constraintsFor`.

**A pair rule** (`SameEndingVerticalPairRule`):
- `failsOrMatches`: two-cell scope (vertically adjacent), "both end in the
  same letter."
- `demand`: exact arity — reserves two specific real words sharing a last
  letter, shape generator = "any two free, vertically-adjacent cells."
- `constraintsFor`: for the reserved pair (top, bottom), declares a
  neighbourhood-exclusion constraint — the cell above top and the cell below
  bottom may not end in the shared letter, unless that cell is the pair's own
  designated partner. This is the exact fix that had to be hand-discovered
  and retrofitted in v1 after real, expensive failures; in v2 it's just what
  the rule says about itself, up front.

**A geometric rule with no construction-time reservation** (`SurroundedCaptureRule`):
- `failsOrMatches`: nine-cell scope (a cell and its eight neighbours), "all
  eight neighbours are already eliminated."
- `demand`: `derived` — claims nothing at construction time; purely a
  function of what else ends up eliminated.
- `constraintsFor`: none.

A genuinely new, complex rule Ian hasn't invented yet — say, "two words on
different pages, same page-offset position, whose letter-sum differs by a
prime number" — decomposes into: a scope generator that yields pairs of cells
sharing a relative position across two pages, a paired-cell-relation
predicate, and (if the rule commits to specific real words) a
`constraintsFor` declaring whatever neighbourhood exclusions matter for that
specific geometry. Nothing about the engine needs to anticipate this rule in
advance for it to be expressible — that's the test of whether §2's
abstraction is actually general enough, and it's the test every new rule
should be checked against before being accepted into the model.

## 11. Suggested build order

Not a schedule, just the order that de-risks the hardest parts first:

1. `BoardSpec`, the word dictionary loader, and word-tag caching (§5) — no
   solving yet, just the data layer.
2. The constraint/domain model and a minimal solver loop (§3) with *zero*
   rules except the trivial "every cell gets any word of the right length" —
   prove propagation and backtracking work correctly on a toy case before any
   real rule exists.
3. Port the simplest v1 spelling rules (no `constraintsFor` needed) and
   confirm the elimination-chain math (§6) still produces a valid,
   single-survivor book at small scale.
4. Introduce the first rule with real `constraintsFor` (a pair rule) and
   specifically test that an *unrelated* rule's ordinary filler correctly
   gets excluded from violating it — this is the exact class of bug that cost
   the most time in v1, so it's the first thing worth writing a dedicated
   test for.
5. Feasibility analysis (§4) and diagnostics (§7) — build these before
   scaling up, not after the first mysterious failure at 100 pages.
6. Scale testing: small page counts first, 100 pages last, exactly as v1
   eventually learned to do but should do from the start this time.
7. Output compatibility (§9) and a side-by-side check against the existing
   web previewer using a v2-built book.

## 12. Open questions worth deciding before implementation starts

- **Language/runtime**: staying in PHP (consistent with the "only runs on
  Ian's server" constraint) is assumed throughout this document. Worth
  confirming explicitly before writing code, since the solver core is the
  one place where a different runtime's performance characteristics would
  matter most.
- **How far to take candidate-ordering heuristics (§3.3)** before it's
  premature optimization — the recommendation is to build the simple version
  first and only add this if profiling on a real book shows it's worth it.
- **Whether to ever support non-rectangular page geometry** (§8) — no known
  need yet; worth a one-line acknowledgment in `BoardSpec`'s design so it
  isn't foreclosed, but not worth building.
