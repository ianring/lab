# Config

Three JSON files, each pure data -- no logic, nothing executable, nothing that
needs PHP comment syntax. The documentation that used to live as inline PHP
comments in these files lives here instead, plus each chain step's own
`"note"` field for anything short and step-specific.

## layout.json

How the book is physically shaped: the grid's size, and one optional knob for
keeping a letter out of the filler. Rule semantics live in `chain.json`, and
nothing here places any individual word -- `ChainBuilder` works out which cell
plays which role itself, guided by each step's own `construction` hints.

Full size is 100 pages x 20 rows x 6 columns = 12,000 words. A smaller grid
(say 9 x 15 x 6, 810 words) is a useful setting to fall back to while testing
a change -- a build at full size takes much longer, so prove a new rule works
small before scaling it up.

`filler_excluded_initial` keeps every filler word from starting with a given
letter. An earlier version of the finale needed `k` held back, so the only
K-words in the book were the ones placed on purpose; the current finale
(`decoy_length` + the final `word_length` step) doesn't care about initials
at all. Left empty so the whole word list is available -- set it to a letter
if a future rule needs one held in reserve.

## clues.json

The CANDIDATE LIBRARY: every clue mechanism available to build a book from,
not yet ordered, not yet given a `keep_fraction`. `chain.json` is a specific
ARRANGEMENT of some of these, for one particular book; `clues.json` is the
whole catalog a book gets arranged from. `bin/sequence.php` reads this, picks
an order for the `spelling` family, calibrates each one's `keep_fraction`, and
writes the result straight to `chain.json`.

**A clue is a playing card, not a type-plus-parameters pair.** `"rule": {"type":
"ContainsZRule"}` is the whole of it -- no `"params"` key. `"type"` is simply
the short name of a class under `lib/Rules/`, resolved directly by
`Engine::ruleFromConfig()` (`new (Puzzlebook\Rules\{$type})()`), and every
such class has a public, zero-argument constructor: everything the rule needs
is hardcoded inside its own file. The clue library IS the `lib/Rules/`
directory -- adding a card to the deck means adding a class, never editing a
registry. A handful of classes that genuinely need parameters to express a
whole FAMILY of cards (`ContainsSubstringRule`, `WordLengthRule`,
`LetterAtPositionRule`, ...) stay as non-instantiable base classes with a
`protected` constructor; each real card is a small `final` subclass that
calls `parent::__construct(...)` with its values baked in (`ContainsZRule`,
`WordLength5Rule`, `SecondLetterARule`, ...). Nothing in `clues.json` or
`chain.json` ever writes a parameter value directly.

Two families, because they need to be planned two completely different ways:

- **`spelling`** -- a pure function of a word's own letters, no cell
  position, no neighbours, no page. Its cardinality against any word pool is
  a fixed, computable number: run `failsWord()` over the pool, count what
  matches. That's what makes automatic ordering possible at all.
- **`positional`** -- needs real GEOMETRY: two free cells on the same page, a
  page's own target length, a knight's move. How many words it actually
  removes depends on page layout and what else is already there, not on the
  word list alone, so `bin/sequence.php` does not try to auto-order this
  family. These stay hand-placed, spliced in after the auto-sequenced
  spelling clues.
- **`finale`** -- the hidden decoy-and-clear tail (see below), appended last,
  unchanged.

**Deliberately NOT in the library** -- see "Held back," below, for the full
account of each:

- `vowel_fraction`, Scrabble score, letter sum -- reserved for a harder book
  later in the series.
- `same_prefix_on_page` -- hits an accidental-collision problem the other
  three signature-pair rules don't.
- `reversal_on_page` -- structurally, not just accidentally, incompatible
  with `anagram_pair_on_page`: reversing a word can't change which letters
  it contains, so a word and its exact reverse always share the identical
  sorted-letter signature. Every reversal pair IS an anagram pair, and
  `anagram_pair_on_page`'s own `UniquePageSignature` invariant only exempts
  anagram's own deliberately-placed cells -- never reversal's -- so
  reversal's second word gets flagged as a duplicate signature on
  essentially every attempt that places one, with no retry able to fix it
  (a different random seed doesn't change what reversing a word does to its
  letters). Kept one or the other rather than both; see
  `lib/Rules/ReversalOnPageRule.php` -- still implemented, just not in this
  book's active library.
- `SecondLetterARule` (second letter A, six letters -- a concrete card of
  `LetterAtPositionRule`) -- kept starving its word pool on real builds even
  after the length-partitioned filler pools (`CellPlan::fixedLength()` /
  `ChainBuilder`'s `$lengthPrefixPools`) made the scan itself cheap. The
  bottleneck wasn't scan cost, it was genuine supply: too few surviving words
  meet that exact position+letter+length combination once the earlier rules
  have already thinned the field, so construction kept backtracking into the
  same handful of starved cells. Rather than chase this one rule's supply
  further, it's dropped from Book 1's active library outright -- the card and
  `lib/Rules/LetterAtPositionRule.php` are untouched and still usable for a
  book whose chain gives them room.
- `anagram_pair_on_page`, `contained_word_on_page` -- the rest of the "two
  cells on a page sharing some computed relationship" family, still pulled
  from Book 1, not individually diagnosed. `SameVowelsOnPageRule` is back in
  as of the 15-page trial (see `chain.json`'s own note on that step) --
  reintroduced individually, now that the book builds at all, rather than
  the whole family going back in at once. Every structural
  surprise this project hit came from this family specifically: a
  predetermined pair-word has no fallback if it collides with something --
  not with its own rule (handled), not with ANOTHER rule's invariant (fixed
  generically, see `Canvas::exemptFromInvariants()`), but the pattern of
  "fix one collision, find another" repeated enough times (`same_prefix`,
  then `reversal` vs `anagram`, then `contained_word` vs `length_by_page`)
  that the family itself is the risk, not any one member of it. Nothing
  here is deleted: `lib/Rules/PageSignaturePairRule.php` (the shared base
  for anagram/reversal/same-prefix/same-vowels) and
  `lib/Rules/ContainedWordOnPageRule.php`, construction-time pair-selection
  and all, are exactly as reliable as the fixes made them -- worth
  revisiting with more patience once a reliable Book 1 actually exists, not
  before.
- `ConditionalCellRule`, `LengthByPageRule`, `MatchingNeighbourEndsRule` --
  every POSITIONAL mechanism, pulled too, even though `length_by_page` and
  `matching_neighbour_ends` specifically had never caused a structural issue
  all project. Not a reaction to a bug in these three -- a deliberate choice
  to get ONE book built on the simplest, most mechanically reliable
  mechanism available (a plain spelling rule claims any free cell, no
  cross-cell coordination at all) before layering any geometry back in.
  `MatchingNeighbourEndsRule` was also the step that absorbed whatever cells
  were left over after every other step's `keep_fraction`; with it gone,
  whichever plain spelling rule is last in the chain (`VowelRunRule`, right
  now) takes over that role automatically -- `ChainPlan::budgetsFor()` was
  already written to top up "whichever keep_fraction step is last," not a
  specific rule, so this needed no code change. All three classes are
  untouched and still usable the moment this book is built once.

## chain.json

The puzzle itself: an ordered, flat list of steps. Each step does exactly one
thing -- eliminates words by one structural rule, or (when reactivated)
reveals the next instruction by one structural reading of whatever currently
survives. Step numbers come from array position, not a hand-written `id`, so
inserting a step renumbers everything automatically.

### The shape of this chain, for now

- Steps 1-12: printed rules, applied one after another, nothing hidden. Every
  one is a plain spelling rule -- no page geometry, no adjacency, no
  cross-cell coordination of any kind.
- Step 13: hidden -- holds back a handful of extra words of the final step's
  length, so that step has something to visibly clear.
- Step 14: a printed rule that clears them, leaving exactly one word: the
  answer.

### No reveal, no knight's-move twist -- deliberately, for now

Both are implemented and kept (`EveryNthWordReveal`,
`KnightAdjacentSurvivingLengthRule`) but held out of Book 1's active chain.
What happened: the reveal and the knight's-move finale both reserve SURVIVOR
cells that can't be used for anything else, and they reserve them
independently -- the reveal needs its own read positions to land on cells
free to be rewritten, the knight's-move rule needs its "key" launch pads to
stay put, and the two were competing for the same scarce pool of non-doomed
cells. In practice this meant most build attempts failed late, specifically
on "a reveal slot landed on a cell whose word the rules need as it is" -- not
a word-supply problem (`bin/check.php` reported no blockers), a GEOMETRY
problem: enough of the handful of true survivors were knight's-move keys
that most attempts collided with one somewhere in the reveal's sequence of
read positions. `DecoyLengthRule` replaces knight's-move here: same "hold
back some cells for the final step to clear" role, with no adjacency or
covering logic and no reveal to compete with -- see its own docblock. Worth
another look once the reveal comes back, or if the knight's-move mechanism
is wanted on its own.

### Held back for a separate reason -- `same_prefix_on_page`

`PageSignaturePairRule` now picks and reserves both words of a pair up
front, during construction, instead of discovering a follower's existence
(or lack of it) at fill time -- a real fix for a worse problem, but it
removed the fallback an ordinary filler cell has (try a different candidate)
if something ELSE already on the page happens to collide with the
deliberately-placed word. A 3-letter prefix match is common enough by
accident ("con", "pre") that this rule hit that collision far more than the
other three signature rules, which all need an exact match on something
much rarer.

### Ordering: three pressures, and they don't always agree

- **SUPPLY** -- a rule only ever sees words the earlier rules left behind, so
  a rule with few matching words in the dictionary has to run while the pool
  is still full. Palindromes (~100 in 133,000) and symmetric-letter words
  (258) must go first or they starve.
- **EFFORT** -- the reader's work is (words still standing) x (effort per
  word), so a rule that's laborious per word belongs late. What matters is
  whether a check SHORT-CIRCUITS: "letters in alphabetical order" is cheap
  because most words fail on the first pair, while adding up a score has no
  early exit.
- **DENSITY** -- a low-cardinality rule is a HUNT, and hunting is fun while
  the pages are full. The same rule late is just page-turning.

Greedy, cheap rules (both-ends-on-the-top-row, two-vowels-together) sit in
the middle where they can do the heavy thinning. Everything that pairs words
up within a page goes after them, where there's less to collide with.

`bin/sequence.php` now automates the SUPPLY/scarcity side of this (scarcest
cardinality first) for the `spelling` family; EFFORT and DENSITY are still
editorial judgment calls, made by hand when writing a clue's `rule_text`.

### This is Book 1, the gentle one

Every rule here is spotting or counting -- nothing asks the reader to do
arithmetic. Someone unwinding with a word puzzle shouldn't have to add
anything up.

### Held back for harder books later in the series

- **Scrabble score** -- add the tile values, test the total.
- **letter sum** -- A=1..Z=26, add them up, test the total.

Both are genuinely more work per word than anything in this book, which is
exactly why they belong in a later one rather than here. Note that both need
a REMAINDER rather than a plain multiple to coexist with the (currently
inactive) revealed phrase: KNIGHT scores exactly 14 (so "multiple of 7"
removes it), and POSITIONED sums to 126 with CHESS at 54 (so "multiple of 9"
removes both). Scrabble % 7 == 5 and letter-sum % 9 == 2 are free. A
different phrase will have a different set of free values.

### Held back with no rescue

No parameter fixes this one, because NO STEP MAY REMOVE A REVEALED WORD
before the reveal reads it:

- **straight-stroke letters only** -- removes ELIMINATE, ANY, AWAY and FIVE.

Becomes available the moment the phrase or the answer changes.
`bin/check.php` reports any such clash.

### Held back for a different reason -- `vowel_fraction`

At its first setting (2/5) it removed ANY, LETTER, SURVIVING and DRAGON
outright, same as above; 3/7 clears all of them. But it's held back anyway,
because the word clash turned out to be the SMALLER problem: this rule can
only ever match a word whose length is a multiple of its denominator, and at
denominator 5 it was quietly destroying the supply of five-letter words the
(then-active) knight's-move finale depends on entirely for its launch pads --
"exactly 2 vowels in 5 letters" is the single most common shape in English.
Two rules that were each fine alone, colliding on a resource neither one
appeared to touch. 3/7 sidesteps both problems and ought to be safe to use in
a future book -- but CHECK THE PER-LENGTH SUPPLY IN `bin/check.php` AGAINST
WHATEVER LENGTH THAT BOOK'S FINALE USES before trusting it, exactly the check
that was missing here.

### What `construction` means

- `"keep_fraction"` -- roughly the share of words entering this step that
  should survive it (so `0.90` removes ~10%). It sets the PACING; the exact
  survivor count depends on what's actually available, and the last
  keep_fraction step in the positional section -- the side-by-side rule --
  absorbs the difference. A rule's supply does NOT grow with the book, so a
  scarce rule's keep_fraction has to rise towards 1 as the book grows.
- `"pairs": true` -- marks the side-by-side rule, which removes words two at
  a time by deliberately matching some horizontally adjacent cells.
- `"derived": true` -- marks a rule whose reach isn't a fraction of
  anything -- how much it removes falls out of its own geometry.

Run `php bin/check.php` after editing. It reports whether the rule set can
work, and for any rule asking for more words than exist, the keep_fraction
that would fit.

### The reader's own conventions

Every rule is a check on a word's own letters or its position in the grid --
never on what the word means -- so the puzzle can be solved by counting and
spelling alone, with no English fluency required. These conventions are
settled here, and should be printed in the book's own front matter:

1. Reading order is page by page, then row by row, left to right.
2. "Surviving" / "still standing" means not yet crossed out.
3. **No rule ever asks you to read a word you have already crossed out.**
   Once a word is gone it is gone: it can't trigger a later rule and it can't
   be half of a pair. This is a constraint on the BOOK, not just a
   convention for the reader -- people mark up puzzles with whatever is to
   hand, and a marker obliterates a word rather than veiling it, so any rule
   needing the letters of an eliminated word would be literally unsolvable
   for them. Nor does any rule ask you to remember a word that was there a
   moment ago: work through a step in reading order, crossing out as you go,
   acting on whatever is still standing when you reach it. No step's answer
   depends on doing it in some other order -- the book is built so that it
   can't. Noticing that a word has been crossed out is still fair game: you
   can see a struck word is struck without reading it, which is why the
   top-left/top-right rule works.
4. Y is never treated as a vowel.
