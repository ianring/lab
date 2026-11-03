# Difficult rule ideas

A parking lot for rules that are **too hard for the main series** but might suit
a future *abridged* edition: far fewer words per page, aimed at solvers who want
real difficulty. The accessibility bar that governs the main arsenal is
deliberately relaxed here — a rule in this file is allowed to demand counting,
bookkeeping, or cross-referencing that we would never put in a mass-market book.

Each entry records the idea, why it does **not** belong in the main series, what
it measured at, and any salvageable simpler variant. Nothing here is
implemented; this is a design record, not a backlog commitment.

---

## 1. Scrabble move with blank tiles (no letter reuse, must intersect a board word)

**The idea.** Treat elimination like a Scrabble play. You have a fixed rack of
tiles — say `R S T L N E` — plus some blank (wildcard) tiles. A word is crossed
out if you can *build* it from the rack under Scrabble-ish rules:

- each tile is used **at most once** (no reuse — unlike `RstlneRule`, where
  letters may repeat freely),
- each **blank** stands for any one letter, used once,
- and the word must **intersect a word already on the board** — i.e. it must
  share at least one letter with some given "anchor" word. If the anchor is
  `ARTHRITIS`, its letters are `{A, R, T, H, I, S}`; the crossed-out word has to
  use at least one of those as a *required* extra letter, while the rack tiles
  stay optional.

**Why it is not in the main series.** It fails the two bars the main arsenal
holds to — *checkable by hand without knowing the word*, and *fires on
recognizable words* — on almost every count:

- **Not hand-checkable.** Verifying one word means: tally its letters, subtract
  the rack (respecting no-reuse), spend blanks on the leftovers, *and* confirm a
  letter overlaps the anchor word. That is a multi-step mini-puzzle per word, far
  past "glance and cross out." Compare `RstlneRule`: "every letter is one of
  R S T L N E" is a single sweep.
- **Blanks blow up the cardinality and make it mushy.** A stripped-down version —
  rack `R S T L N E` each once, **2 blanks**, no reuse, *without* the intersection
  rule — already matched **5,988 words (4.5%)** of the 132,858-word dictionary
  (e.g. ABET, ABLE, ABSENT, ACNE, ACRE). Two blanks turn the rule into "any word
  of ≤8 letters with at most two unusual letters," which is not a crisp,
  nameable property. Cardinality is high and hard to tune predictably.
- **The intersection clause barely filters.** A long anchor like `ARTHRITIS`
  exposes six of the commonest letters `{A, R, T, H, I, S}`, so nearly every
  candidate already contains one. It adds a paragraph of explanation for almost
  no selectivity.
- **Hard to state.** The printed instruction would be a dense paragraph, and the
  "rack optional but the crossing letter required" asymmetry is genuinely
  confusing to read.

**Salvageable kernels (if the Scrabble theme is wanted later).**

- **Plain tile-set, no blanks, no intersection:** cross out words that are an
  anagram of a *subset* of a fixed tile multiset (each tile used at most once).
  Checkable-ish (count letters against the rack), but honestly just a
  harder-to-verify `RstlneRule`, and the "each tile once" distinction is subtle
  to apply by eye. Marginal.
- **Scrabble score threshold:** cross out words whose Scrabble tile value is ≥ N.
  (`WordTags::scrabbleScore()` already exists.) Checkable only with a printed
  value chart — plausible for a difficult edition, where a reference chart on the
  page is acceptable. This is probably the cleanest Scrabble-flavoured hook: it
  keeps the theme without the rack/blank/intersection bookkeeping.

**If revisited:** drop the blanks and the intersection first; both are the parts
that break checkability and cardinality control. Start from the score-threshold
variant.
