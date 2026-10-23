# Building the whole series — implementation plan

*Architect's plan for turning the one working book into all 21, with the
Mystery chain, the acrostics, and the Capstone. Written against the engine as
it stands on 2026-10-21. Companion to SERIES-MAP.md (the plot) and VENTURE.md
(the decisions). This document is the "how we build it" layer.*

---

## 1. The goal, and the cheap first iteration

We have one verified book: `config/chain.json` (answer `dragon`, a tuned chain
of elimination rules), built by `bin/build.php` and proven by `ChainRunner`.
The series needs twenty more like it, plus the Capstone, wired together by the
Mystery chain and the address acrostics.

Ian's first-iteration strategy — and it's the right one — is: **use book 1's
rules for every book.** Twenty structurally identical books, each with its own
grid (different random seed → different fill → different surviving answer).
They're "very similar" by design; variety in the rules themselves is a later
pass. This gets us a complete, coherent, buildable series fast, and everything
downstream (plants, acrostics, Capstone, validation, print) can be built and
tested against it.

Two consequences fall out of that strategy:

**The 20-rule invariant.** The Capstone's chain is the diagonal: *Rule 1 from
Book 1, Rule 2 from Book 2, … Rule 20 from Book 20.* For that to be a complete
chain there must be a Rule 20 in every book — so **every book has exactly 20
printed rules.** This is new. The current book has 27 *printed* steps (ids
1–26 plus a final sweep) plus one hidden decoy. Reshaping that into exactly 20
printed rules that still land on a single survivor is a required first task
(§4). Ian's instinct here is correct and load-bearing: 20 is not cosmetic, it's
what makes the diagonal well-formed.

**The Capstone "just works" — in iteration 1 only.** If all twenty books share
one rule set, then "Rule N from Book N" is the *same* rule for every N, so the
Capstone's diagonal is simply that one canonical 20-rule chain applied to the
Capstone's own grid. It builds exactly like a 21st ordinary book. That
convenience is specific to iteration 1. The moment books get *different* rules,
the Capstone's chain becomes a genuine mixture and has to be re-tuned and
re-verified on its own (the `Advisor` re-balances `keep_fraction`s for the
mixed composition). The architecture keeps the Capstone as a first-class book
either way; only its chain's *provenance* changes.

---

## 2. What exists today (and what we build on)

The per-book pipeline is solid and we reuse all of it:

- **`config/chain.json`** — the ordered steps: each an eliminate rule with a
  `keep_fraction` cell budget; one `final` sweep; optional non-printed
  `derived` decoy; and the planted `answer`.
- **`ChainPlan::load(config, board, dictionary, seed)`** — reads the chain,
  computes per-rule cell budgets (the cascade math), resolves every `Demand`,
  checks supply ≥ demand and that the demands tile the board exactly, and
  validates that the answer survives every rule. `problems()` reports anything
  wrong *before* solving.
- **`Solver` + `ReservationQueue`** — the CSP fill. Each rule's demand expands
  into slots; the solver assigns words by systematic chronological
  backtracking. `ConsumedWords` enforces that **no word repeats inside a book**
  — the property that later makes every FIND unambiguous.
- **`AnswerRule`** — the template for everything we're about to add: a rule
  that places *one specific word* via a `wordChooser`, on a cell chosen by a
  generator (`SingleFreeCellGenerator` over page-edge cells so no capture rule
  can ever eliminate it), with death index infinity so it survives.
- **`ChainRunner::run(board, plan)`** — replays the chain exactly as a reader
  would and confirms one survivor equal to the answer. The build's proof.
- **`BookWriter`**, **`Advisor`/`AutoTuner`**, **`bin/check.php`** — output,
  feasibility advice, and demand/supply measurement.
- **`lib/Fingerprint.php`** — the shared `md5 + salt → Crockford base32`
  fingerprint (`Fingerprint::of()`), already used by the website hasher.
- **Print** — `print/lib/BookPdf.php` (the real renderer, one book) and
  `print/lib/MockSeriesPdf.php` (the 21-book scaffold that already prototypes
  every front/back-matter page, the per-book Mystery copy, and the Capstone
  address/claim pages).

### The one hard gap

`ChainPlan::read()` rejects `kind: reveal` outright — *"v2 does not support
reveals yet."* There is **no way to pin a chosen word at a chosen cell.** The
Mystery chain and the acrostics are nothing but pinned words at chosen cells.
So the central new capability to build is a **plant** mechanism (§5), and the
central new *coordination* problem is deciding, across all 21 books at once,
which words go in which cells (§6).

---

## 3. The shape of the series build

One new entry point, `bin/build-series.php`, orchestrates everything. The flow,
top to bottom:

```
config/series.json ─▶ SeriesSpec
                          │
                          ▼
                    SeriesPlanner  ──────────────┐  (decides every planted
                          │                       │   word and cell, across
                          │ PlantSet per book     │   all 21 books, up front)
                          ▼                       │
        for each book N (1..21):                  │
          ChainPlan::load(chain, board, seed,     │
                          plantSet[N])  ◀──────────┘
          Solver.solve → FilledBoard
          ChainRunner.run → verify one survivor
          BookWriter.write → build/series/bookNN/{book,chain}.json
                          │
                          ▼
                    SeriesValidator   (the RevealGraph: every plant consistent,
                          │            every FIND unique, every acrostic spells
                          │            its target, every book verified)
                          ▼
                    SeriesWriter  ─▶ build/series/manifest.json + COMMITMENT hash
                          │
                          ▼
            print/render-series.php  ─▶ FINISHED/book01..21.pdf
```

The key architectural decision is that **`SeriesPlanner` runs first and decides
every planted cell before any grid is solved.** That is what makes the build a
single pass instead of a circular two-pass (§6 explains why the obvious
"build, read back, re-plant" approach eats its own tail).

---

## 4. Reshaping the chain to exactly 20 rules

This is mostly curation, but it needs a clear target so it isn't fiddled with
forever.

- **Define one canonical chain** of exactly 20 printed eliminate rules whose
  last rule leaves exactly one survivor. Non-printed engine steps (a `derived`
  decoy) may still exist to help the fill land cleanly, but they are *not* part
  of the diagonal and don't count toward the 20 — the diagonal borrows the 20
  **printed** rules only.
- **Curate down from today's 27 printed rules.** Keep the scarce,
  quickly-checkable, well-separated ones; drop overlaps. `bin/check.php` already
  measures each rule's real supply against its demand, so the selection is
  evidence-driven, not taste alone. The reshaped chain must pass
  `ChainPlan::problems() === []` and build at full 100-page scale.
- **Store it as the series chain.** `config/chain.json` becomes the canonical
  20-rule chain; `config/series.json` references it. In iteration 1 all books
  point at this one file.
- **Record the rule order as the diagonal manifest.** The Capstone prints "Rule
  N = Book N's Rule N"; since Rule N is identical across books in iteration 1,
  the manifest is honest and the Capstone's effective chain is this canonical
  chain.

Deliverable: a 20-printed-rule `config/chain.json` that `bin/build.php` builds
and verifies at 100 pages. Nothing below depends on *which* 20 rules win, only
that there are exactly 20 and the chain is sound.

---

## 5. The plant mechanism (filling the "reveal" gap)

A **plant** is a chosen word pinned at a chosen `(page, row, column)`. The
answer is just the special plant that never dies; Mystery words and acrostic
letters are plants that *do* die (crossed out, still readable — exactly the
"dead words stay readable" rule the whole quest rests on).

### New classes

- **`lib/Rules/Generators/FixedCellGenerator`** — yields one exact cell index
  (vs `SingleFreeCellGenerator`, which picks any free cell). This is the only
  genuinely new primitive; everything else is composition.
- **`lib/Rules/PlantRule`** — generalization of `AnswerRule`. Constructed from a
  list of `(cell, word)` pairs plus a death policy. Its `demand()` emits one
  `Arity::Exact` scope per plant, each with a `FixedCellGenerator(cell)` and a
  `wordChooser` returning that word. Exact arity means plants schedule first and
  own their cells before any filler competes for them.
- **`lib/Series/Plant`** — a value object: `{page,row,column}`, `word`, and a
  `role` (`answer | mystery_home | mystery_find | acrostic | box_find`). Roles
  are for the validator and the writer, not the solver.
- **`lib/Series/PlantSet`** — all plants for one book, with lookup by cell and
  by role.

### How plants fit the existing demand math

Plants consume specific cells, so they must be accounted for or the "demands
tile the board exactly" check breaks. Three facts make this clean:

1. Each plant claims exactly one cell via an `Arity::Exact` demand — identical
   bookkeeping to how `AnswerRule` already claims the answer's single cell.
2. The **absorber** (the last `keep_fraction` step) already soaks any
   difference between budgeted and actually-claimed cells. K plants simply
   reduce the free pool by K and the absorber shrinks by K — the machinery in
   `ChainPlan` lines ~193–237 needs no new concept, just plant demands added to
   the sum.
3. A dying plant must actually be eliminated by the chain or it becomes a
   second survivor. `SeriesPlanner` guarantees this at planning time
   (§6): every non-answer plant word is checked to **fail at least one
   non-final rule**, and its death step is the earliest rule it fails — fed to
   `StepResolver` so the standing-guard logic (which cells must be up when a
   geometric rule runs) stays correct.

### `ChainPlan` change

`ChainPlan::load()` gains an optional `?PlantSet $plants` argument. When
present it (a) drops the `reveal`-not-supported guard, (b) adds each plant as an
exact demand, (c) uses the planner-supplied death step per plant, and (d) lets
the answer be a fixed-cell plant instead of a free pick (needed so a book's
answer *page* is known before the fill — see §6). `bin/build.php` keeps calling
it with no plant set, so single-book builds are untouched.

The verification side needs nothing new: `ChainRunner` already proves one
survivor; with plants present it also implicitly proves every plant was
eliminated (any surviving plant shows up as a second survivor and fails the
build loudly).

---

## 6. The cross-book coordinator — `SeriesPlanner`

This is the heart of the series, and the part most worth getting right. It
turns the abstract design in SERIES-MAP.md into a concrete `PlantSet` per book.

### The dependency it has to untangle

Recall the locked Mystery rule (FIND, no arithmetic): for book N ≥ 2,

```
Mystery[N] lives in book N-1, at:
    page   = the page book N's ANSWER survives on
    row,col= where book N-1's MYSTERY is found in book N
```

So book N's Mystery word physically sits in **book N-1**, at a cell whose *page*
comes from book N's answer and whose *row/column* come from where book N-1's
Mystery was planted in book N. Laid out as plants, book N carries:

- `Mystery[N-1]` at a chosen `findPos[N] = (row, col)` — so book N's own FIND
  works.
- `Mystery[N+1]` at `(Pg[N+1], findPos[N+1])` — because book N is "the book
  before" book N+1, so N+1's Mystery is read here.
- `Answer[N]` at its cell `(Pg[N], …)`.
- any acrostic words for book N.

The naive build order is circular: the cell for `Mystery[N]` needs `Pg[N]` (book
N's answer page), but the answer page is whatever the fill happens to produce,
and the fill depends on the plants, which include `Mystery[N+1]` whose cell
needs `Pg[N+1]`… Building to discover pages, then re-planting, then re-building
shifts the pages again. It never settles.

### The fix: decide every fixed cell before solving

`SeriesPlanner` removes the circularity by **choosing the answer cells itself**
rather than discovering them. Because the answer is now a fixed-cell plant
(§5), `Pg[N]` is known up front. With answer pages fixed and find-positions
freely chosen, every plant cell in the whole series is determined before a
single grid is solved. Concretely, the planner:

1. **Picks each book's answer.** Draw 21 distinct words from the canonical
   chain's "full survivor" set (words that pass every non-final rule — the exact
   set `ChainPlan` already computes for its answer check). Assign each a seed and
   a fixed answer cell on a chosen page edge (page edges keep capture rules off
   it, reusing `PageEdges::interior`). This fixes `A[N]` and `Pg[N]`.
2. **Picks each Mystery word.** Choose `M[1..21]`, each a plausible,
   type-cast, eliminable word (fails ≥1 non-final rule), distinct where it must
   be. Book 1's Mystery is special: it is the top-left word of its answer's
   page, so `M[1]`'s home cell is `(Pg[1], row 0, col 0)` and the planner plants
   `M[1]` there and picks a word that satisfies the acrostic/uniqueness needs.
3. **Chooses find-positions.** For each N ≥ 2, pick `findPos[N] = (row,col)`
   where `M[N-1]` is planted in book N.
4. **Derives Mystery home cells.** `M[N]`'s home is `(Pg[N], findPos[N])` in
   book N-1. Emit the plants: `M[N-1]` into book N at `findPos[N]`; `M[N]` into
   book N-1 at its home cell.
5. **Plans the acrostics** (via `AcrosticPlanner`, below).
6. **Plans the Capstone extras.** `M[21]` lives in book 20; `M[20]` is planted
   in the Capstone at `findPos[21]`. The PO-box number is a FIND: `M[21]` gets an
   **extra** plant in a named book on the page equal to the rented box number
   (`role: box_find`), so "find the enclosed word; its page is the box" resolves
   to that number. The planner records the box number in the manifest.
7. **Validates every choice before emitting** (fail fast, with a readable
   message naming the book and cell):
   - every Mystery/acrostic word fails ≥1 non-final rule (it will be
     eliminated), is not equal to any answer, and fits its column width at the
     grid font (reuse `BookPdf::columnLimitMm`);
   - every FIND target word is unique within the book it's searched in (so the
     FIND is unambiguous) — trivially true given `ConsumedWords`, but asserted;
   - every answer survives the canonical chain (`ChainPlan`'s own check);
   - no two plants in one book claim the same cell.

The output is `PlantSet[1..21]`, handed to each book's `ChainPlan::load`. One
solve per book, no second pass.

### `AcrosticPlanner`

Given a target string, a book, a page, and a geometry, produce a run of plants
whose initials spell the target.

- **Addressee** — Book 1, page 12, a *wrapping diagonal*: one word per row,
  column stepping +1 each row mod 5, spelling `TO THE KEEPER OF THE ELIMINOID`
  (25 letters, 25 rows). For each row r it needs a word at `(p12, r, (startCol +
  r) mod 5)` starting with letter `L[r]`.
- **Town / region / country / postal** — short acrostics in other books,
  each a column or diagonal on a named page; the Capstone will name the exact
  location of each.

For each required (cell, initial) the planner queries the dictionary for words
that start with that letter, fail ≥1 non-final rule (eliminable), fit the
column, and are "cast to type" (unremarkable — a length/commonness filter; the
`WordTags`/`RuleIndexCache` indexes make this a fast lookup, not a scan). It
reserves distinct words so the book's uniqueness invariant holds. Acrostics are
**self-contained per book** — they depend on nothing from other books — so the
planner can place them independently of the Mystery chain.

**Acrostics are a FIRST-LETTER constraint, not a full-word plant (Ian, 2026-10-21).**
A Mystery word is pinned exactly, because its identity matters — the same word
is read in one book and found in the next. An acrostic only needs its *initial*
fixed; the rest of the word can be anything the chain would normally put there.
So an acrostic cell is an ordinary grid cell, filled and eliminated by the chain
as usual, carrying one extra per-cell constraint: "the word here starts with H."
That is strictly better than planting a whole chosen word: the solver keeps full
dictionary fallback (any eliminable H-word fits), which is exactly what spares it
the placement-sensitive backtracking a fixed word can trigger (see Phase 2).
Implementation: a cell-scoped constraint ("first letter = L") rather than an
exact `PlantRule`. The constrained placement still fixes the cell (so the
acrostic geometry is exact) but enumerates eliminable L-words with a known early
death, instead of a single word — the free-word, fixed-letter sibling of the
Mystery plant.

---

## 7. The Capstone

Modeled as book 21 throughout, with two extras:

- **Its chain is the diagonal.** `lib/Series/CapstoneChain` assembles the chain
  from "Rule N of book N." In iteration 1 this is literally the canonical chain
  (all books identical), so it loads and verifies like any book. The class
  exists now so that when books later diverge, assembling and **re-tuning** the
  mixed chain (via `Advisor`) is a named, testable step rather than a surprise.
- **It prints the address and claim pages.** Already prototyped in
  `MockSeriesPdf` (addressee / town / postal from acrostics; PO box from the
  `box_find` plant; enclose the Capstone Mystery; the possession probe; first
  postmark wins). The real renderer inherits those pages.

The Capstone's own Mystery is produced by the same `SeriesPlanner` step as every
other book — it needs the Capstone's answer and book 20's Mystery, so holding it
proves all 21 were solved. Nothing special in the planner; it's book 21 of the
loop.

---

## 8. Validation and the commitment — `SeriesValidator`

Per D9, the whole series is built, frozen, and hashed before Book 1 ships. The
validator is the gate (the "RevealGraph" idea made concrete): it loads every
built `book.json` and the `PlantSet`s and proves, against the *actual rendered
grids* (not the planner's intentions):

- **Every book verifies** — one survivor, equal to its planted answer
  (re-run `ChainRunner`).
- **Every Mystery resolves end to end** — walk the FIND for each N using the
  real grids: find `M[N-1]` in book N, read `(Pg[N], row, col)` in book N-1,
  confirm the word there is `M[N]`. This is the reader's exact procedure,
  executed by machine, for all 21 links including the Capstone.
- **Every acrostic spells its target** — read the initials off the real grid
  at the stated geometry and compare.
- **The address assembles** — addressee + town + region + postal from their
  acrostics, PO box from the `box_find` page, all consistent with the manifest.
- **No orphans** — every plant is reachable by some printed instruction (law 6),
  and nothing hidden lacks a revealer (law 2).

Only when all of that passes does `SeriesWriter` emit `build/series/manifest.json`
and a **commitment hash** over the frozen artifacts — the fingerprint Ian posts
publicly before Book 1 goes on sale. A companion `bin/check-series.php` runs the
same validation without writing, for use during iteration.

---

## 9. Print integration

The scaffold (`MockSeriesPdf`) already has the right *pages*; the real renderer
needs the real *data*:

- **Make `BookPdf` series-aware.** Fold in what the scaffold prototyped: a book
  number, the per-book Mystery copy (Book 1 root / Books 2–20 standard / Capstone
  all-21), and the Capstone-only address and claim pages. The cleanest form is a
  renderer that takes `book.json` + the book's `PlantSet`/manifest row + a book
  number, so it draws the real grid and the real quest copy from one source.
- **Print real fingerprints.** Replace the scaffold's sample with
  `Fingerprint::of($answer)` and `Fingerprint::of($mystery)` beside the two
  boxes — the shared class guarantees they match the website hasher.
- **`print/render-series.php`** switches from the mock to reading
  `build/series/bookNN/book.json`, still writing `FINISHED/bookNN.pdf`.

`MockSeriesPdf` stays as the design sandbox until the real renderer reaches
parity, then retires.

---

## 10. New files at a glance

```
config/series.json                     series manifest: books, chain ref,
                                        seeds, acrostic targets + locations
lib/Series/SeriesSpec.php              parsed series.json
lib/Series/SeriesPlanner.php           the coordinator (§6)
lib/Series/PlantSet.php, Plant.php     a book's plants
lib/Series/AcrosticPlanner.php         initials→plants over a geometry
lib/Series/CapstoneChain.php           the diagonal chain assembler
lib/Series/SeriesValidator.php         the RevealGraph gate (§8)
lib/Output/SeriesWriter.php            manifest + commitment hash
lib/Rules/PlantRule.php                pin words at cells (generalizes AnswerRule)
lib/Rules/Generators/FixedCellGenerator.php   one exact cell
bin/build-series.php                   orchestrator
bin/check-series.php                   validate without writing
  (edit) lib/Chain/ChainPlan.php       optional PlantSet; drop reveal guard
  (edit) print/lib/BookPdf.php         series-aware + real fingerprints
```

---

## 11. Phased delivery

Built so each phase is runnable and reviewable on its own — matching Ian's
expectation of many incremental rounds.

**Phase 1 — Twenty-one similar books.** Reshape the chain to 20 rules (§4). Add
`config/series.json` and a minimal `bin/build-series.php` that builds 21 books
from the one chain with 21 seeds (no cross-book plants yet; answers found
naturally). Capstone as the 21st book. Point `render-series.php` at the real
output. *Exit:* 21 real PDFs, each a sound standalone puzzle.

**Phase 2 — The plant primitive.** Build `FixedCellGenerator`, `PlantRule`,
`Plant`/`PlantSet`; extend `ChainPlan`. Prove it by planting a couple of known
words in one book and confirming they appear and are eliminated while the answer
still wins. *Exit:* a book builds with arbitrary words pinned at arbitrary cells.

**Phase 3 — The Mystery chain.** `SeriesPlanner` chooses answers/pages/seeds and
the Mystery words and find-positions, emits `PlantSet`s, and the series builds
with the chain wired. Add the FIND walk to validation. *Exit:* `SeriesValidator`
walks all 21 Mystery links on the real grids and they resolve.

**Phase 4 — Acrostics and the address.** `AcrosticPlanner`; the addressee
diagonal in Book 1 and the place acrostics; the Capstone `box_find` plant and
address/claim pages with real data. *Exit:* the full address assembles from the
built grids.

**Phase 5 — Freeze and commit.** `SeriesWriter` manifest + commitment hash;
`bin/check-series.php`; real fingerprints in print. *Exit:* one command builds,
verifies, and hashes the entire series; the hash is postable.

Rule *variety* across books (so they're not all the same puzzle) is deliberately
**after** Phase 5: it's a content pass that re-tunes per-book chains and the
Capstone diagonal, and it only becomes safe once the validator can catch every
way a divergent chain might break a FIND or strand a plant.

---

## 12. Risks and open questions

- **Plant eliminability under 20 fixed rules.** Every planted word must fail at
  least one of the 20 rules or it survives and breaks the book. With 20 broad
  rules almost every ordinary word qualifies, but the planner must *check*, and
  for acrostics (words forced to start with a given letter) the eligible set
  shrinks — `AcrosticPlanner` needs a real fallback search, not first-match.
- **Answer-cell vs plant-cell collisions.** Fixing answer pages and planting
  Mysteries on specific pages could, rarely, demand two plants in one cell or
  crowd a page; the planner validates and can nudge a find-position or reseed.
- **Capstone diagonal after rule divergence.** Fine in iteration 1; a genuine
  tuning problem later. `CapstoneChain` + `Advisor` is where that lives, and
  Phase-5 validation is the safety net before it's attempted.
- **PO-box derivation is still `[prov]`.** The `box_find` plant assumes "the page
  the enclosed word sits on = the box number." If Ian finalizes a different
  rule, it's localized to one planner step and the Capstone's printed copy.
- **Open: do answers need to be distinct across books?** The FIND chain doesn't
  require it, but distinct answers make the books feel distinct and avoid odd
  coincidences. Current plan draws distinct answers; cheap to relax.
- **Open: how many books carry place-acrostics, and which pages?** SERIES-MAP
  lists them `[prov]`; the planner needs the final list to assign them.
