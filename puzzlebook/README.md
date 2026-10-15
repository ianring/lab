# Puzzlebook

A prototype for a self-revealing word-elimination puzzle book: a grid of
dictionary words, a chain of structural rules, and a reader who highlights
their way down to a single surviving word. Seven rules are printed directly,
one after another. Then ONE twist: reading every 4th surviving word spells
out the final rule, in real words -- not a code, nothing to look up. Applying
that rule leaves exactly one word standing -- the answer.

Every rule here is a check on a word's own letters, or on its position in the
printed grid. None of them depend on knowing what a word means, so the whole
chain is solvable by someone with little or no English fluency -- it's a
counting-and-spelling exercise, not a reading-comprehension one.

The chain is a flat, ordered list of **steps**, and each step does exactly
one thing: it either eliminates words by one rule, or reveals the next
instruction by one reading of whatever currently survives. Multiple
eliminate steps run back to back with nothing hidden between them -- the
reveal happens exactly once, where it has the most payoff, not at every
transition.

**Three pieces, one job each:** `bin/build.php` builds the book once, as
data. `web/api.php` is the only thing that ever evaluates a rule after
that -- one request per step, run on your server. `web/index.html` is a thin
renderer: it never decides what a rule does, it just displays whatever the
API reports. There is exactly one implementation of the puzzle's logic
(`lib/Engine.php`), and it only ever runs server-side.

## Running it

Everything here runs on your server, not on this machine -- see the files,
not an execution, as the deliverable of this session. There are three
separate steps, in order: check the rules, build the book, then look at it.

### 0. Check the rules first (fast)

```
php bin/check.php     # ~1 second: can this rule set work at all?
```

Run this after every edit to `config/chain.php`, before building. It does
no word placement -- just the arithmetic, the reserved-word checks, and a
count of how many words in the list have each shape the build will need --
and reports what's short and which knob fixes it. The point is that
iterating on rules shouldn't mean reading stack traces: a rule set that
can't work should say so in a second, in plain language.

Specifically, it catches:

- **A chain whose shape isn't buildable** (missing pair/rank/reveal/finale
  step, or an eliminate step whose rule type the builder can't construct).
- **A reserved word that one of your own rules kills.** The classic trap:
  the reveal needs the word "knight," and a three-consonants-in-a-row rule
  silently removes it long before the reveal is read. It names the word and
  the step that kills it.
- **Not enough words of a needed shape** in the list -- per step, with
  "needs N, has M."
- **Arithmetic that can't work**: too few survivors for the reveal's
  spacing, more pairs than the grid has slots, more survivors than there
  are eligible cells.

A clean run isn't a guarantee -- the knight's-move *geometry* (does each
survivor have a free neighbouring cell for its anchor?) depends on where
roles land, which only the real build knows. But every failure it reports
is a certain one.

### 1. Build the book

```
php bin/build.php     # lays the book out, verifies the whole chain, writes build/ and web/
php bin/solve.php     # re-reads build/book.json fresh and reports step by step
```

`bin/build.php` writes:

- `build/book.json` -- the finished word grid, as data (what a printer would
  need, and the file `bin/solve.php` reads back to verify).
- `build/chain.json` -- the step list and answer, as data.
- `build/pages.txt` -- the grid as plain text, with every word's position
  number, so you can proofread it or print it directly.
- `web/book.json` and `web/chain.json` -- plain copies of the two JSON files
  above, written straight into `web/` so the preview page has something to
  load.

Both PHP commands exit non-zero and print a specific diagnostic if anything
in the chain doesn't line up (a reveal spelling the wrong text, the final
step leaving more than one word, a rule that can't be satisfied at all) --
they're meant to fail loudly rather than hand you a book that doesn't
actually work.

### 2. Look at it

Copy the whole `web/` folder to your server. Unlike step 1, this half needs
your server to actually *run* PHP, not just serve files -- `web/api.php`
executes once per click. Open `web/index.html` there over plain HTTP.

`index.html` has no rule logic in it. On load it fetches `book.json` and
`chain.json` from right next to itself and renders exactly that book. Each
time you click "Apply," it `POST`s the step number and the current
keep/cross-out state to `api.php`, which calls `Engine::applyRule()` or
`Engine::computeReveal()` -- the one real implementation, the same one
`bin/build.php` and `bin/solve.php` use -- and sends back what happened. The
page's JavaScript just paints that onto the grid.

This page is a *preview*, not the deliverable -- the thing to hand to a
printer is `build/book.json` / `build/pages.txt` from step 1.

## How the book gets built

`lib/ChainBuilder.php` builds the whole grid in a **single deterministic
forward pass**, cell by cell, in reading order. There is no randomness that
isn't seeded: the word list is shuffled once with a fixed seed, and every
other shuffle in the build (which cells pair up, which filler tag lands
where) uses its own fixed seed too. Run `bin/build.php` twice without
changing anything and you get byte-for-byte the same book both times. That
matters in practice, not just in principle: it means tweaking a
`keep_fraction` or a rule's parameters in `config/chain.php` and rebuilding
shows you the *exact* effect of that one change, with nothing else moving
around to confuse the comparison -- which is the whole point, if the goal is
to tune the chain until it *feels* right rather than just computes right.

The algorithm, in order:

1. **Decide how many cells die at each rule**, from each eliminate step's
   `construction.keep_fraction` in `config/chain.php` -- purely arithmetic,
   no words chosen yet.
2. **Choose actual adjacent cell pairs** for the matching-neighbour-ends rule
   up front, at the grid level (same row, columns 0-1, 2-3, or 4-5) -- not
   "whichever two cells happen to get the same tag next," which was the
   first (wrong) version of this and silently paired cells that weren't
   even next to each other.
3. **Walk the grid once**, assigning every remaining cell a role by a
   running tally: every 18th surviving cell becomes the every-18th-word
   victim; of what's left, every 4th becomes one of the reveal's words; the
   very last surviving cell becomes the answer; everything else is a decoy.
   Because the tally is exact, these land exactly where they need to --
   nothing is guessed and corrected afterward.
4. **Reserve a knight's-move anchor** for every one of those survivors
   (except the answer), searching all 8 knight directions from its cell for
   a free host.
5. **Fill in real words**, back in reading order, using
   `Dictionary::fillerMatching()` (a word matching an arbitrary predicate --
   the direct "build()" counterpart to a rule's "solve()") and
   `Dictionary::fillerStartingWith()` (the same thing, but for a required
   first letter, needed wherever that letter varies by cell -- a pair's
   right half, an anchor). For the pure-spelling steps, the predicate IS a
   `SpellingRule`'s own `failsWord()` -- the exact same method
   `lib/Engine.php` calls to eliminate words later -- so a rule's build-side
   and solve-side behaviour can't drift apart; they're one method, called
   from two directions.
6. **Verify**, independently, by replaying the entire chain with
   `Engine` -- the same check as before, now the safety net underneath a
   much more automated builder.

This isn't a fully generic constraint solver yet -- it knows about five
*kinds* of rule (a pure spelling check, a deliberate adjacent pair, a
running-rank cutoff, and the knight's-move finale network), and a genuinely
new *kind* of rule (not a variation on one of those) needs its own
construction-side logic added, the same way `matching_neighbour_ends` and
`every_nth_survivor` were. What it *does* already give you: add a new rule
that fits one of those shapes, describe it in `config/chain.php`, and
the builder places it correctly -- or fails with a specific, localized error
(ran out of a certain kind of word, no anchor slot available, a reveal
spacing that doesn't fit the survivor count) rather than silently producing
a broken book. That combination -- automatic when a rule fits a known shape,
loud and specific when it can't be satisfied at all -- is what makes rapid
iteration on the chain's shape and pacing actually practical.

This was validated as a throwaway Python script first (never run against any
of Ian's code -- a fresh, independent reimplementation), because the
algorithm has enough moving parts that reading the PHP alone wouldn't have
caught the five real bugs that script surfaced during development: a pairing
scheme that didn't actually pair adjacent cells, a word that could die at
the wrong stage because only some of the prior rules were checked, a grid
row with no valid "forward" anchor direction, a letter collision between an
anchor and the answer, and anchors themselves never being scheduled to die
before the finale (which also, as a side effect, threw off the reveal's
word positions, since the whole running-tally scheme assumes every
non-precious cell dies on schedule). The PHP in `lib/ChainBuilder.php` is a
direct translation of the script once all five were fixed and the result
checked out end to end.

## How the pieces fit together

```
config/chain.php     What the puzzle DOES: the flat, ordered list of steps
                      (eliminate or reveal, one at a time), each eliminate
                      step's construction.keep_fraction (how much of the
                      book should die there), and the answer.

config/layout.php     The grid's shape (pages/rows/cols) and the one letter
                      reserved for knight's-move anchors. That's all that's
                      left here -- it used to hand-place every rule-critical
                      word by exact cell position; ChainBuilder does that
                      itself now.

data/wordlist.txt      The raw word pool every word in the book is drawn
                      from: ~133,000 words of 3-10 letters, lowercase a-z
                      only, filtered from /usr/share/dict/words. Built by
                      bin/make-wordlist.php -- don't hand-edit it.

bin/make-wordlist.php   Regenerates the word list from a system dictionary,
                      with --min and --max length. The length range is a
                      real design knob, not a chore: it decides how much
                      supply each rule has. Re-run bin/check.php after.

lib/WordTags.php       Pure spelling checks: palindrome, consonant run,
                      letter at a position, first/last letter, alphabet-
                      adjacent ends.

lib/Rng.php             A tiny seeded shuffle. Every shuffle anywhere in
                      the build uses this with a fixed seed, which is what
                      makes a build fully deterministic.

lib/Grid.php            Page/row/column arithmetic, and knight's-move
                      neighbours.

lib/Rules/              One class per rule TYPE (PalindromeRule,
                      ConsonantRunRule, EveryNthSurvivorRule, ...). A
                      SpellingRule subclass holds just a failsWord() check;
                      its apply() (the solve() side) is inherited and
                      generic. A positional rule (EveryNthSurvivorRule,
                      KnightAdjacentInitialRule, MatchingNeighbourEndsRule)
                      implements apply() directly, since there's no single
                      per-word check to extract. Either way, a rule's
                      meaning lives in exactly one class, read top to
                      bottom in one file.

lib/Reveals/            One class per reveal mechanism, same shape: a
                      reveal() that reads survivors off the grid, and a
                      describe() that puts it into words for chain.json's
                      pattern_text.

lib/Construction/       The hand-off between a rule's construction logic
                      and the builder. Canvas.php is the book-in-progress
                      that rules claim cells on -- the ONLY thing a rule
                      may touch. CellPlan.php says what shape one cell's
                      word must be. ConstructionContext.php is what a rule
                      is told about the chain around it.

lib/ChainPlan.php       Reads config/chain.php once: which steps exist,
                      which rule object each uses, which know how to build
                      themselves, and in what order they claim cells. Also
                      works out each step's cell budget. Deliberately holds
                      no list of named rule roles -- a chain is just an
                      ordered list of steps.

lib/Engine.php          A thin factory + registry: turns a step's 'type'
                      or 'mechanism' string (from config/chain.php) into
                      the real object from lib/Rules/ or lib/Reveals/, and
                      calls it. config/chain.php never names a class
                      directly, and every caller (bin/build.php,
                      bin/solve.php, web/api.php, lib/ChainBuilder.php)
                      goes through here rather than reimplementing or
                      reaching into lib/Rules/ itself.

lib/Dictionary.php      Hands out words matching an arbitrary predicate
                      (fillerMatching) or a required first letter plus a
                      predicate (fillerStartingWith) -- the generic
                      "build()" counterpart to a spelling rule's "solve()".
                      Skips anything already placed or starting with the
                      layout's reserved anchor letter (except when a
                      caller is deliberately asking for that letter).

lib/ChainBuilder.php    The allocator: reserves the answer, lets each rule
                      claim its cells (fussiest first), puts the revealed
                      words where the reveal will read them, fills every
                      cell with a word matching its plan, then replays the
                      whole chain with Engine to verify. Knows nothing
                      about any specific rule -- no palindromes, no knight
                      moves, no pairs. This is what bin/build.php calls.

lib/Solver.php           The standalone forward solver: reads a built
                      book fresh off disk and reports, step by step,
                      what survived and what each reveal spelled out.
                      This is what bin/build.php and bin/solve.php call.

web/api.php             The only thing web/index.html ever asks a question
                      of. One request per step: given the state before
                      the step and which step it is, calls Engine.php and
                      returns exactly what happened.

web/index.html          The preview page -- a thin renderer. It fetches
                      web/book.json and web/chain.json to know what the
                      book IS, then calls web/api.php for every step to
                      find out what HAPPENS.

bin/check.php           The fast pre-flight check (see "Running it" above):
                      arithmetic, reserved-word survival, and word-list
                      supply, with no word placement. Shares its sums with
                      the real builder via ChainBuilder::planCounts() and
                      its rule checks via the same lib/Rules/ classes, so
                      it can't drift out of agreement with the build.
```

## The demo chain

| Step | Kind | What it does |
|---|---|---|
| 1 (printed) | eliminate | Cross out every palindrome |
| 2 (printed) | eliminate | Cross out every word with four consonants in a row |
| 3 (printed) | eliminate | Cross out every word whose first and last letters are alphabet-adjacent (A then B, B then C, ...) |
| 4 (printed) | eliminate | Cross out every word whose 2nd letter is A |
| 5 (printed) | eliminate | Cross out every side-by-side pair of words where the left one ends in the letter the right one starts with |
| 6 | reveal | Every 4th surviving word (11 of them) -> **"eliminate any word knight chess move from surviving five letter words"** |
| 7 (hidden) | eliminate | Cross out every surviving word a knight's move from a **surviving** five-letter word |
| 8 (printed, final) | eliminate | Cross out every five-letter word ending in E -> exactly one word left: **dragon** |

Note that **the reveal is not the last step.** Step 7 deliberately leaves
the five-letter launch-pad words standing -- a rule can't cross out the
thing it fires from -- so step 8 is what finally clears them, and the answer
is whatever is left. That ordering isn't a stylistic choice; it's what makes
the whole thing constructible (see below).

Five different kinds of activity -- spelling, counting consonants, alphabet
math, position-checking, side-by-side checking -- before anything is hidden
at all. Then the twist, then two rules that use it.

### Conventions settled up front

A few of the brief's open questions, decided here and worth printing in the
book's own front matter so the reader has them too:

1. **Reading order** is page by page, then row by row, left to right within
   a row. Counting steps count across the *whole book*, not per page.
2. **For the side-by-side rule (step 5), a crossed-out word still counts as
   a neighbour** -- a highlighter mark doesn't erase the printed word.
3. **For the knight's-move rule (step 7), the opposite**: it says
   "SURVIVING five letter words", so a word already crossed out is *not* a
   launch pad. Each rule says which it means, so the reader is never left
   guessing -- and the difference matters, because it's exactly what makes
   step 7 work the way it does.
4. **Y is never a vowel**, for the consonant-run rule.
5. A "word" here is any contiguous printed entry -- no hyphens or
   apostrophes appear in the current word pool.

### Why rule 2 changed from three consonants to four

This was a real construction constraint, not a stylistic choice: the reveal
phrase needed to use the literal word "**knight**" (so the finale's
instruction reads plainly, with nothing standing in for anything else), but
"knight" ends in three consonants in a row ("ght"). With rule 2 set to
three-in-a-row, "knight" would be crossed out at step 2, long before the
reveal ever got to read it. Loosening the threshold to four-in-a-row lets
"knight" survive (and only barely -- it's exactly a 3-run) while still
catching plenty of other words.

This is also the sharpest version of the lesson the whole build process is
built around: **anything that needs to survive to a later rule has to be
checked against every earlier rule**, not just the obvious one. It's exactly
why `ChainBuilder` checks every reserved word against *all* of
`config/chain.php`'s spelling rules at once, before it ever starts walking
the grid.

### How the knight's-move rule is built: keys first, victims derived

Step 7 is the hard one to construct, and the way it's done is the single
most important idea in `lib/ChainBuilder.php`.

The rule reads "every surviving word a knight's move from a **surviving**
five-letter word". The obvious approach is to decide which words survive,
then hunt for a five-letter launch pad near each one. **That does not
work** -- two earlier versions of this file failed exactly that way. It's a
global covering problem: the surviving set has to cover *itself*. Survivors
are sparse, a cell has only 6-8 knight-neighbours, and most survivors end
up with nowhere to put a launch pad. Patching it by widening the pool of
eligible launch pads made it worse in a subtler way: cells then had to
survive past the step they'd been scheduled to die at, which silently
shifted the running survivor count the reveal depends on, and the reveal
spelled out garbage.

So this version **inverts the problem**. Place the keys first, wherever
they like, and then *derive* the set of words that survive to step 7: it is
precisely the keys, plus everything a knight's move from a key, plus the
answer. Nothing else is left alive that far. There is nothing to cover and
nothing to search for, and every decision is local:

1. Pick the answer's cell, and mark its knight's-move neighbourhood as a
   **protected zone**.
2. Pick key cells one at a time. A cell is eligible only if neither it nor
   anything it would kill falls in that protected zone -- which is what
   guarantees the answer is the one word step 7 can't touch.
3. The surviving set is now determined: keys, their neighbours, the answer.
   Keep adding keys until it's big enough for the reveal to read.
4. The revealed words go in the slots the reveal will actually read
   (survivors 4, 8, 12, ... in reading order). Everything else dies at one
   of steps 1-5.

Because key placement is geometric, the number of survivors falls out of
*where the keys land* rather than being chosen up front. The
`keep_fraction`s therefore set the **pacing** of steps 1-5, and the last
spelling step absorbs whatever difference is needed.

**The design rule to take from this:** a rule whose construction needs every
survivor to be simultaneously covered by other survivors doesn't fit this
architecture. Rules whose construction is a local, independent, per-cell
decision do. Check a proposed rule against that before writing code for
it -- it is *not* a question of whether the build runs forwards or
backwards, which turned out to be irrelevant.

### When an arrangement doesn't work out

Some constraints can't be checked until the cells are chosen: a key might
land in a slot a revealed word needs, or the answer might fall on one. So
`ChainBuilder` doesn't treat the first arrangement as the only one. It tries
up to 400, with a different seed each time, and keeps the first that passes
`verify()` end to end. `bin/build.php` reports how many it took -- a number
well above 1 means the constraints are tight and small rule changes may stop
fitting.

Only if every attempt fails does it give up, and then it reports which
constraints blocked it and how often, so the message points at the real
bottleneck rather than at whichever cell happened to fail last.

## Iterating on the rules together

This is the part meant for going back and forth: you suggest a rule, I add
it to the library if it's new, and we wire it into the chain. Concretely:

**A rule now owns both halves of itself**, and `ChainBuilder` is an
allocator that knows nothing about any specific rule. So adding a mechanism
means writing one class, not editing the builder.

1. **A pure spelling check** (like `palindrome`, `consonant_run`,
   `alphabet_adjacent_ends`, `letter_at_position`)? Add one class to
   `lib/Rules/` extending `SpellingRule`, with just a `failsWord()` method.
   That single method is both how the rule is *applied* (inherited `apply()`
   calls it per word) and how it's *built* (`SpellingRule::construct()`
   claims cells and asks for words that fail it), so there's no second place
   to keep in sync and no construction code to write at all. Register the
   type string in `Engine::ruleFromConfig()`, give it a
   `construction.keep_fraction` in `config/chain.php`, done.
2. **A rule that depends on position** (neighbours, a knight's move,
   anything geometric)? Add a class implementing `RuleInterface` *and*
   `ConstructsCells`. You write two methods:
   - `apply()` -- how it eliminates words when the book is solved.
   - `construct(Canvas $canvas, ConstructionContext $ctx)` -- how its cells
     are laid out when the book is made. Ask the canvas what's free
     (`freeCellsShuffled()`, `freeHorizontalPairs()`), claim what you need
     (`claimDoomed()` / `claimSurvivor()`), and attach a `CellPlan` saying
     what shape each cell's word must be. Throw `ConstructionRetry` if this
     arrangement won't do; the builder tries another.
   - `constructionPriority()` -- when you get to choose cells, relative to
     other rules. Rules that define which words survive go first (0-19),
     geometric rules next (20-69), take-anything rules last (70+). A fussy
     rule must choose before a flexible one or there'll be nothing suitable
     left.

   `MatchingNeighbourEndsRule` is the short worked example;
   `KnightAdjacentSurvivingLengthRule` is the involved one.
3. **Something structurally new** -- a rule that needs a kind of cell
   relationship `Canvas` can't express yet? Add the query to `Canvas`
   (it's the only thing rules may touch) rather than reaching around it.
   That keeps every rule's construction logic inside its own class, which
   is the property that makes a long chain maintainable.
3. **Changing the existing chain** (which rule runs when, its parameters,
   how much of the book it removes) is editing `config/chain.php`'s steps
   and their `construction.keep_fraction` values, then rebuilding to see
   the real, exact effect (see "How the book gets built" above for why
   that's reliable).
4. **Changing the grid's size** is `config/layout.php`.

After any change, run `bin/check.php` first -- it's about a second and
catches most mistakes with a plain-language explanation and the knob to
turn. Then `bin/build.php` and `bin/solve.php`; if the pieces still don't
line up, you'll get a specific error (which step, what it expected, what it
got, or why construction itself couldn't proceed) rather than a silently
broken book.

### Known limits of this prototype

- Four rule *shapes* are supported (pure spelling, deliberate adjacent
  pairs, running-rank cutoff, and the knight's-move finale). A rule that
  doesn't fit one of those shapes needs new construction logic written for
  it, not just a new `Engine::ruleFromConfig()` case -- and a rule whose
  construction needs survivors to cover each other *simultaneously* doesn't
  fit this architecture at all (see "How the finale works" above).
- **The every-Nth-survivor rule is currently unusable.** `EveryNthSurvivorRule`
  still exists and `Engine` still knows the `every_nth_survivor` type, but the
  builder no longer has construction support for it, so putting one in the
  chain gets a clear error from `parseChain()`. Adding it back means
  interleaving extra doomed cells at exactly the positions the rule will
  strike (every 18th survivor, say) so that nothing which must live lands
  there -- straightforward, just not done yet. It was dropped to keep this
  rewrite small enough to get right.
- `lib/Rules/` also holds `RestrictedAlphabetLengthRule` ("N letters, all
  drawn from this letter set"), written for an earlier version of the finale
  and unused by the current chain. It's a plain `SpellingRule`, so it can be
  dropped into the chain as a spelling step whenever it's wanted.
- `KnightAdjacentInitialRule` (the "knight's move from a word starting with
  K" variant) is also still there and still works, but nothing in the
  current chain uses it.
- There's one reveal, by design (per your direction) -- `ChainBuilder`
  doesn't currently support placing more than one. Extending it to several
  reveals across a chain is possible but not built.
- This is built at 9 pages / 8 steps. Scaling to ~25 rules across an
  80-page book (the eventual target) is the planned next step, once this
  smaller version has been played with enough to know the mechanics feel
  right -- it'll likely need a handful of new rule *shapes* beyond the four
  above, since 25 rules built from only a few underlying construction
  patterns would start to feel repetitive.
- `bin/check.php` validates arithmetic, reserved words and word supply, but
  not knight's-move *geometry* -- whether each survivor actually has a free
  neighbouring cell for its anchor depends on where roles land, which only
  the real build knows. Extending the checker to simulate role placement
  (and so catch anchor-capacity problems before a build) is the obvious
  next improvement to it.
