# Puzzlebook 2.0

The CSP-based, ground-up rewrite of the word-elimination puzzle book engine.
`ARCHITECTURE.md` is the design; `IMPLEMENTATION.md` is the class-level plan;
both are kept truthful to what was actually built (deviations are called out
in place).

## Commands

```sh
php bin/check.php            # Phase-0 analysis: budgets, supply, headroom -- no solving
php bin/advise.php           # machine-generated recommendations (rebalance/reorder/flags)
php bin/advise.php --apply   # apply them automatically -> config/chain.tuned.json
php bin/build.php            # build + verify + write artifacts (--seed=N for a different book)

sh print/install-libs.sh     # once: vendors FPDF into print/libs/ (no Composer)
php print/render.php         # build/book.json -> build/book.pdf, 5.25" x 7" trim
```

The PDF is print-ready at 5.25" x 7" (133.35 x 177.8 mm): title page,
how-it-works, the numbered rules (printed steps only -- the decoy step stays
the engine's secret), then one page per book page of words.
`--title="..."` / `--out=...` override the defaults.

A successful build writes `build/book.json`, `build/chain.json`,
`build/pages.txt`, `build/eliminated/` (what each step removed, one file per
step), and copies `book.json`/`chain.json` into `preview/` for the preview page.
Open `preview/index.html` (served, so `api.php` runs) to walk the chain step by
step.

Current performance baseline: the 60-page (7,200-cell) book builds in ~11s
with zero backtracks and verifies end to end, answer `dragon`.

## Design knobs — one place

Everything about the book's physical shape lives in `config/layout.json`:

```json
{
    "grid":  { "pages": 100, "rows": 20, "cols": 5 },
    "print": { "trim_width_mm": 133.35, "trim_height_mm": 177.8,
               "margin_mm": 9.0, "grid_font_pt": 9.0 }
}
```

Edit any of them, then re-run: `php print/fit-wordlist.php` (refilters the
dictionary for the new geometry) -> `php bin/build.php` -> `php
print/render.php`. The renderer errors rather than ever shrinking below
`grid_font_pt`.

Reader-facing rule text lives on each Rule class (`ruleText()`), right
beside the logic it describes — the ONE authoritative copy; `chain.json`
carries no wording. Builds inject the text into the exported
`build/chain.json` / `preview/chain.json`, so the previewer and PDF read it
from there. (Moving it there immediately caught a drifted text:
VowelRunRule said "nine letters or fewer" while the code capped at six.)

## Layout

- `config/` — `layout.json` (board size + print design) + `chain.json` (the
  rule chain: order, keep_fractions, notes — no wording).
- `lib/Solver/` — the systematic assign/backtrack engine. Nothing in it knows
  any rule.
- `lib/Rules/` — the clue library. A new spelling rule = `failsWord()` +
  `describe()` extending `SpellingRuleBase`; a new geometric rule implements
  `Rule` and declares its cross-rule safety via `globalConstraints()`
  (usually one `StandingPatternConstraint`).
- `lib/Chain/` — chain reading, budgets (keep_fraction cascade), prefix
  pools, the one `Engine` used by build, verify, and the web API alike.
- `lib/Feasibility/` — `Advisor` (recommendations) and `AutoTuner`
  (applies them, writes a tuned chain for human review).
- `tests/smoke_solver.php` — six scenarios covering the solver's happy path,
  analytic infeasibility, proven structural infeasibility, backtrack
  recovery, constraint-vs-existing-words validation, and the Exact path.

## Notes discovered by building

- Scheduling is computed, never hand-tuned: Exact slots first, then
  geometry-restricted demands (corners, page edges) by capacity headroom,
  then unrestricted filler by supply headroom. Both orderings that matter
  were discovered by real full-scale dead ends, not guessed.
- `SurroundedCaptureRule` placed late in a chain can ONLY capture the final
  survivors (everything else is already dead when it runs). The engine now
  protects words that outlive it (decoys, answer: page-edge placement plus a
  ring guard), so in this chain the step verifiably removes 0 words -- worth
  deciding deliberately whether a never-firing step should stay in the book.
