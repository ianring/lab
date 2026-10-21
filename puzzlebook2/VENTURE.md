# Eliminoid — the venture document

This file is the running record of the Eliminoid project beyond the engine:
the business design, the quest architecture, the decisions and their
reasoning. Maintained by Claude as scribe from brainstorming sessions with
Ian; the engine/book design itself is documented in README.md,
ARCHITECTURE.md and IMPLEMENTATION.md. Nothing here is code-binding until
it graduates into an implementation task.

Status: pre-launch design. Target launch: 2027 (no earlier than next year).
Nothing announced publicly.

---

## Vision

A series of printed word-elimination puzzle books. Each book stands alone:
buy it, solve it, reach its one surviving ANSWER word, done — satisfying
with no strings. But finishing Book 1 reveals the trapdoor: a layered
treasure hunt in the lineage of Kit Williams' *Masquerade* (1979) and his
untitled 1984 follow-up — cross-book mysteries, side quests, hidden books,
secret domains, and ultimately a real, physical, one-of-one prize. The
depth must always read as generosity, never gating: no book ever requires
another book to complete ITS OWN puzzle.

Tone targets: intrigue, complexity, rabbit holes, earned revelation.
"People who finish book 1 discover they have opened up to a bizarre
treasure hunt."

---

## Principles (founding, ratified by Ian)

**P1 — No literacy requirement beyond structure. (2026-10-20)**
No rule may ever depend on what a word MEANS — only on its structure:
letters, consonants/vowels, lengths, positions, grid geometry. A player
needs only enough language to understand each rule's instruction; target
reading level for ALL player-facing text is GRADE 6. Implications:
- Bans forever: meaning-based rules ("cross out animals"), synonym/
  cultural-knowledge rules, wordplay requiring fluency.
- Rule texts must spell out any referenced set explicitly (the keyboard
  rule already lists Q W E R T Y U I O P rather than assuming QWERTY).
- Opens the market: kids, non-native speakers, accessibility.
- Tooling idea (fits the software-does-the-work directive): a readability
  lint (e.g. Flesch-Kincaid) over every ruleText() in bin/check.php, so
  a too-dense instruction is flagged like a supply shortfall. Current
  texts need an eventual grade-6 pass ("vertically adjacent" -> "directly
  above or below").

**P2 — No hidden rules; every secret has a revealer. (2026-10-20)**
Nothing in any book is meant to be found by scouring for arcane patterns
("bible-code" searching is explicitly the anti-goal). Every discoverable
thing — acrostics, Veins/Inquiries, hidden books, secret domains — must
have a REVEALER somewhere along the journey: an explicit instruction, in
a book, on a certificate, in the Registry, that points to it. Implications:
- AMENDS the survivor-acrostic standing idea: it may NOT rely on players
  "noticing on their own." A later book or the portal must eventually
  say "look at the first letters of the last words standing in Book 1."
  (This is arguably stronger: the reveal becomes a designed gift moment.)
- The quest map becomes a REVEAL GRAPH: every secret node needs at least
  one inbound revealer edge reachable from a purchased book. This is
  machine-checkable — the future quest manifest validator should PROVE
  no orphan secrets exist, the same way the builder proves the chain.
- Protects the community from apophenia spirals (the Masquerade failure
  mode of obsessives finding meaning in noise).

**P3 — Every book is standalone and order-free: the used-bookstore test.
(2026-10-20)**
"Someone should be able to pick up book 50 at a used bookstore and still
enjoy the solving of it." No ownership gating, ever: books may be bought,
owned, and solved in any order. The original gatekeeping concept (can't
get book 2 until book 1 is finished) is formally dead; ORDER emerges
socially — timed releases, cohorts, the prize race — never by enforcement.
A solver in disorder misses only the Quest layer, and knowingly.
Implications:
- The paper must be self-sufficient: each book restates all its own
  rules, and solving + self-verification must work with NO internet (the
  Checkpoint counts and the Appendix already provide offline
  verification — retroactively, those decisions serve P3).
- The used/secondhand/library market is a funnel, not a threat.
- A used copy may arrive with its Redemption perks already claimed by a
  previous owner; design the Registry kindly here — re-verification of
  finishing should still work (status, flair) even where one-time
  rewards were consumed. (Parked under Q6, redemption identity.)
- Nothing in any book may assume the reader has seen another book,
  except inside clearly-marked Quest material.

**P4 — Forthright at every juncture. (2026-10-20)**
Things may be hidden UNTIL revealed (P2), but the player is never teased,
misled, or left guessing about what they should do. Coy copy ("you may be
glad you did") is out; plain statement is in: the books SAY there is a
grand quest, SAY future books will consult this one's words, SAY every
secret has a revealer. Book 1's quest page even states the inverse
promise: "If you are hunting for something no instruction has mentioned,
you can stop: it is not there."
Refinement (Ian, 2026-10-20): TEASES ARE PERMITTED when they are
explicit promises with guaranteed later reward (Book 1's empty Mystery
box is the model). What P4 forbids is coyness — vague atmosphere with
no commitment. Per map camouflage law 5, a tease's fulfilment must
also genuinely REQUIRE the content that delivers it (no artificial
waiting): the later book must be an ingredient, not an envelope.

**P5 — The quest lives entirely in the books; the website is an amenity.
(2026-10-20)**
On the grand timescale (used copies, dead platforms, decades), the quest
must function with paper alone: someone who owns all the books must be
able to complete the ENTIRE quest and arrive at the prize — or the key
that unlocks it — with no website, no internet, no platform. The website
may offer leaderboards, registration, coupons, news, and may MIRROR
revealers, but it is never the sole home of a revealer and never an
impediment. Implications:
- The printed quest page's revealer promise now says revealers are
  "printed in some book" (website mention removed from that sentence).
- Q4 (finale) needs rework: a synchronized ONLINE finale event conflicts
  with P5. The terminal act may be minimal CONTACT (a letter, an email
  address derived from the final solution — Masquerade's model), but the
  solving that produces the claim must be all-paper.
- Community-milestone unlocks and the archive-oracle become amenities
  only; no quest content may gate on them.
- If any quest path runs through hidden/not-for-sale books (Apocrypha),
  the MAIN path to the prize must not — Apocrypha are side rewards.
- STATUS NOTE (Ian, 2026-10-20): P5 is a strong default and design
  posture, not an eternal vow — later decisions may find places where
  website involvement genuinely makes sense, and the principle can be
  revisited then. Design paper-first; treat any website dependency as a
  deliberate, logged exception rather than a drift.

**P6 — Durable player state is only what the book had them WRITE DOWN.
(2026-10-20)**
Mechanisms may rely on: words written into printed boxes (the Answer,
MysteryCodes, Trial results), anything PRINTED (coordinates stay
consultable because dead words stay readable), and nothing else. They may
NEVER rely on transient solve states: which words were the "final few"
standing before the last rule, which rule killed which word, the order
anything happened. A highlighter-solved book ends visually uniform — the
player cannot reconstruct any of that, and must never be asked to.
(Ian's framing: the penultimate survivors "aren't any more special than
the others, and the player won't necessarily keep track." Discard all
ideas depending on knowing the final few.)

---

## Decisions (dated, with reasoning)

**D1 — No price gating / no $75 anchor. (2026-10-19)**
Original idea: sequels listed at $75, finishers of book N get a $15
voucher for book N+1. Dropped because (a) Amazon KDP has no coupon or
voucher mechanism at all, so the plan was unbuildable on the main channel;
(b) a $75 sticker on a storefront repels browsers and invites "scam"
reviews; (c) the password gate leaks instantly and then punishes only the
honest. Replacement: every book sells at an honest flat price (~$15);
finishing is rewarded with STATUS and CONTENT (mystery layer, certificates,
early access, recognition), not discounts.

**D2 — Redemption = possession + completion proof, not a password.
(2026-10-19)**
The website verifies a finisher by asking for the answer PLUS the words at
2-3 server-chosen random coordinates (book/page/row/column). Answerable in
seconds with the book in hand; not answerable from a leaked answer alone.
The server's oracle is the build's own book.json. Defeating it requires
transcribing the entire book, which is indistinguishable from piracy and
not worth a modest reward.

**D3 — No 50-variant editions. (2026-10-20)**
Variants (many seeds of the same book, unique answers each) were armor
against answer-leaking. Nixed: with a real competitive prize in play,
leak incentives invert — treasure hunters don't share (cf. The Secret
community hoarding leads for 40 years). Early books may leak; once players
have months invested and a prize is live, leaking works against the
leaker. D2's possession probes remain as the baseline defense and are
variant-independent. (Engine note: variants remain cheap to produce later
if this ever reverses — ~20s/build; nothing downstream assumed
single-edition except simplicity.)

**D4 — Prize is pottery, not gold. (2026-10-20)**
Ian is a potter. A one-of-one ceramic artifact made by the author's own
hands has more provenance and mythology than purchased metal, costs
little. Maker's mark in the glaze = authentication.
SUPERSEDED DETAIL (2026-10-20): no burial, no dig — Ian: "there is no
dig. I'm likely just sending it in the mail to the winner." Delivery
is insured mail. (The genre's burial grief — Preiss's casques — is
thereby avoided entirely rather than managed.)

**D5 — Each book: one ANSWER, one MYSTERY CODE. (2026-10-20)**
The ANSWER is the book's own self-contained elimination result (the basic
product). The MYSTERY is the optional cross-book layer for quest players:
derived by combining the current book's answer with lookups in PREVIOUS
books only (never subsequent — a book must never require a later book).
Worked example (Ian's): Book 2's answer is GREENHOUSE; the mystery
instruction says "find the answer word printed in Book 1; note its page,
row, column; read that same page/row/column in Book 2 — that word is the
Mystery." Owning+finishing chain is thus demonstrated by the mystery code.
Book 3's sketch: find Book 2's mystery word in Book 3's body, take its
coordinates, read those coordinates in Book 1. Pattern for books 4+ not
yet settled (open question Q2).

**D6 — Claude is scribe and creative partner; this document is the log.
(2026-10-20)**

**D7 — The grand prize is a hand-made ceramic art piece. (2026-10-20)**
Thrown by Ian: "something beautiful and bizarre," one of one. Ratifies
D4's direction. Open adjunct (not yet decided): ceramic TOKENS with codes
inscribed in the clay, stamped with an ELIMINOID logo (logo itself TBD --
see Q8). Candidate token roles: finisher prizes, quest keys, or shards
that physically assemble (see standing ideas).

**D9 — The entire Series is developed before Book 1 publishes.
(2026-10-20)**
All books planned, built, rendered, and cross-validated pre-launch.
Reasoning: the Diagonal Book requires every chain fixed in advance;
forward references become pure harvest (every "other book" is frozen);
the Commitment can cover the WHOLE series before Book 1 ships; release
cadence carries zero miss-risk (fully banked); and the RevealGraph can be
machine-verified end to end before anything prints. Uniquely viable here
because the marginal cost of a finished book is compute (~20s) — the real
spend is rule invention and design, not production, and POD means zero
inventory risk on unpublished volumes.
Freeze-level nuance: what the quest HARD-FREEZES per book is its answer,
its diagonal-contributed rule, planted coordinates, survivor set, and any
dead-cell picture placements; grids re-render freely until any other book
harvests their coordinates — after which the series freezes as one unit.
Implication: a SERIES-LEVEL build pipeline (future bin/build-series.php):
one command builds every book + the companion, validates every
cross-reference and the diagonal, proves the RevealGraph has no orphan
secrets, and emits the Commitment hash. The series becomes one verified
artifact.
Risks + mitigations: no mid-series learning from the public (mitigate:
beta solvers on early books BEFORE series freeze); author burnout /
motivation across a long pre-revenue runway (mitigate: waves with real
milestones, Book 1 can soft-launch to betas); typo-fix vs Commitment
tension (decide at freeze: commit to quest-critical invariants only, or
full-text hash — logged as open sub-question under Q4).

**D10 — The Mystery formula is LOCKED: all FIND, no arithmetic.
(2026-10-21)**
Finalizes D5 and closes Q2 (pattern for books 4+). The single uniform
rule for every book N >= 2:
  Mystery[N] is read in book N-1, at { page = the page book N's ANSWER
  survives on; row, column = where book N-1's MYSTERY is found in book
  N }.
Book 1 is the root (no book before): its Mystery is the top-left word
of the page its answer survives on, read in Book 1 itself. The whole
story: the answer tells you which page, the previous Mystery tells you
the spot, you read it in the book before.
  Why FIND and not arithmetic: Ian rejected using a letter-sum checksum
(A=1..Z=26) for anything in the DERIVATION — "not a very good hash, and
I don't like using it." Earlier drafts used CHECKSUM(answer) for the
page and LENGTH/VOWELCOUNT(prev mystery) for row/column; all removed.
  What it guarantees (the point): the page needs THIS book's answer
(you solved it), the spot needs the PREVIOUS book's Mystery (you walked
the chain). So holding Book N's Mystery proves books 1..N were solved,
and the Capstone's Mystery proves all 21. A leak of an early Mystery
buys nothing — every step still demands that book's answer.
  Clean consequence: every word in a book is unique (engine invariant),
so each FIND lands on exactly one cell; no ambiguity, no bounds to hand-
tune (any answer's page is 1..100, valid in both books). Plants needed:
Mystery[N-1] sits somewhere in book N (so it can be found) and
Mystery[N] sits at the computed cell in book N-1. A direct consequence
is that Book N's Mystery word physically lives in Book N-1.

**D11 — Verification is a salted hash on the website, not a printed
letter-sum. (2026-10-21)**
Each ANSWER and MYSTERY box prints a one-way hash of the correct word
(SHA1/MD5 of word + a server-side secret salt), like a file-download
fingerprint. The player types a candidate word on eliminoid.com and is
told whether it matches. Replaces the old printed letter-sum checksum,
which collided heavily (weak verifier) and leaked information about the
word. The hash is precise (only the exact word matches) and one-way
(the printed hash reveals nothing; the secret salt blocks offline
reverse-tables — brute force means querying the site word by word,
which is rate-limitable). P5 note: this is a logged website amenity;
solving never requires it — the check is optional, books-alone still
works. The PO box number also derives from the Capstone Mystery by a
FIND (no arithmetic): the word is planted on the page equal to the
rented box number, so finding it yields the box.

---

## Lexicon (technical, ratified direction 2026-10-20)

Purpose, per Ian: NOT world-building or marketing flavour — a consistent
domain language so discussion is unambiguous and PHP classes have obvious
names. Plain words, singular nouns, PascalCase-able. Marketing/lore
naming is a separate, deferred exercise. (Two flavour registers were
drafted and set aside; see git history if ever wanted.)

**D8 — "the Eliminoid" is the prize artifact (ratified).** Branding fact,
not lexicon: Ian owns eliminoid.com. Technical discussions say "the
prize."

Engine terms stay exactly as the code already has them: Board, BoardSpec,
cell, Rule, chain, step, Demand, Scope, Reservation, Constraint, decoy,
answer, survivor, KeepMask, keep_fraction.

| Entity | Term | Notes |
|---|---|---|
| Ordered published sequence of books | **Series** | "Series 1" targets 20 books (provisional). |
| One published volume | **Book** | Already universal (book.json). |
| One word position | **Cell** | Engine term, reused everywhere. |
| A (book, page, row, col) address | **Coordinate** | Class: Coordinate. |
| A book's single surviving word | **Answer** | Engine term. |
| Derived cross-book proof word | **MysteryCode** | PRINTED as "the Mystery Word" for now; Ian has asked for rename candidates (see note below table). |
| The whole optional cross-book layer | **Quest** | |
| One optional thread within it | **SideQuest** | |
| Any discoverable thing | **Secret** | Node in the reveal graph. |
| A book's kept unlock-item (record box) | **Key** | Ratified 2026-10-20. At most one per book; most books have none. Books 10, 13, 19 grant them currently. Distinct from the Capstone's champion word. |
| Instruction pointing at a Secret | **Revealer** | Ian's coinage (P2), kept. |
| The validated secret/revealer structure | **RevealGraph** | Validator proves no orphan Secrets (P2). |
| Deliberately placed word at a Coordinate | **Plant** / PlantedWord | |
| Choosing existing words from published books | **Harvest** | Verb; opposite of planting. |
| One possession-check question | **Probe** | |
| The verify-a-finisher flow | **Redemption** | The website is just "the website." |
| book.json as lookup service | **Oracle** | Informal but precise. |
| A verified completer of a Book | **Finisher** | |
| Scheduled publication event | **Release** | Cadence discussions. |
| Easy/hard route through the Series | **Path** | Fork = where Paths diverge. |
| The series-ending event | **Finale** | |
| The solution document, locked and hash-posted before Book 1 sells, published at the end | **Commitment** | Proves the quest was planned in advance; plain cryptography, no blockchain. |
| Ceramic piece with inscribed code | **Token** | |
| The one-of-one prize artifact | **Prize** | Branded "the Eliminoid" (D8). |
| Numbered completion document | **Certificate** | |
| In-book warning before state-dependent rules | **Checkpoint** | Already printed. |
| Per-rule counts section | **Appendix** | Already printed. |

Rename question (PARKED 2026-10-20): "Mystery Word" stays in print and
MysteryCode in the lexicon for now. Ian likes Codeword but it isn't "the
one"; the final name waits for the public-facing storytelling /
world-building pass, which may supply it naturally. Candidates on file:
Codeword, Keyword, Passkey, Watchword. Print + lexicon update together
when chosen.

Back matter structure (decided 2026-10-20): word pages, then THE ANSWER
page (a lone writing box), then the quest pages (forthright blurb + THE
MYSTERY WORD box; Book 1's box states its revealer comes in a later
book, per P2), then the spoiler-guarded counts Appendix last. Per-book
quest/mystery copy moves to config when Book 2 exists.

---

## Standing ideas (endorsed, not yet scheduled)

- **Timed releases / seasons.** Bi-monthly drops create synchronized
  cohorts (everyone fights rule 19 the same week — the subreddit's
  lifeblood). Gate the FRONTIER, never the back catalog (newcomers binge
  to the frontier). Bank 2-3 finished books before announcing any cadence;
  a missed public date is worse than no date. "Season" framing (a world
  map of books revealed over a year, finale resolves the meta-puzzle) is
  the strongest shape.
- **DEFERRED for map v1 (2026-10-20): forking.** The first Series map is
  LINEAR; the fork idea below stays on file as a later embellishment.
- **Easy/hard forked paths (Mario World).** At a fork: six easy books or
  two hard books reach the same unlock state. Fixes completion-rate
  arithmetic (easy books are climbable), gives the website a progress map,
  and difficulty is an engine config knob (page count, rule count, benched
  nasty rules). Fork state lives in the redemption DB.
- **Survivor acrostic.** STRUCK (P6, 2026-10-20): depended on the player
  knowing the final standing words — a transient state a highlighter
  erases. The engine capability (constraining decoy initials) remains,
  unused.
- **Hidden books.** Lulu supports unlisted direct-only products: books
  purchasable only via secret link, or printed as author copies and MAILED
  to quest completers — a book that cannot be bought.
- **Recognition ladder.** Numbered finisher certificates (first-finisher
  certificates especially); names of early finishers typeset into the
  acknowledgments of later printings (free with POD re-uploads); verified
  finisher flair on the subreddit; "you are finisher #N" counters.
- **Hash commitments (the one good "blockchain" idea, minus blockchain).**
  Before Book 1 ships, publish the SHA-256 of a document containing every
  answer, quest coordinate, and the full map. Reveal the document at the
  finale: provable advance planning, anti-"Ken Thomas" integrity, pure
  showmanship. No wallets, no chain. (Blockchain/NFTs otherwise rejected:
  wrong audience, scam-adjacent optics, a MySQL table does everything
  needed.)
- **Subreddit.** Start early, seed with build-journal posts as the
  transparent author; spoiler-tag norms from day one; verified-finisher
  flair granted via redemption. Let finishers make the noise.
- **Gift loop.** At the moment of successful redemption, offer a
  discounted GIFT code for a friend's Book 1 — recruitment at peak joy.
- **Multi-book reward mechanisms (brainstorm 2026-10-20).** Families of
  paper-sovereign ways to reward owning several books, beyond the basic
  coordinate lookup. All satisfy P1 (structural), P2 (revealed by
  instruction), P3 (optional layer), P5 (paper only):
  1. DERIVED-COORDINATE family — variants on lookup where the
     coordinates themselves are computed: letters of an earlier Answer
     index pages/rows/columns; APPENDIX ARITHMETIC (add the counts Rule
     4 removed across Books 1-3 -> a page number) — the printed counts
     appendix becomes quest material; a KEY-ASSEMBLY form page (blank
     cells labelled with coordinates across books; filled letters spell
     the key).
  2. META-ELIMINATION family (engine-native flagship) — a later book
     prints a BONUS RULE CHAIN to be applied to the combined survivors
     of several earlier books: pool the ~11 survivors of Books 1-3 (33
     words), run a printed mini-chain, one word remains. The core
     mechanic, recursed. The engine can construct and verify it exactly
     like a book.
  3. VISUAL/PHYSICAL family — DEAD-CELL PICTURES: the engine can PLACE a
     rule's eliminations so the crossed-out cells of page P form a
     glyph/arrow/numeral once the book is solved (geometric demands with
     shape-mask cells; counts stay exact) — the act of solving paints
     the clue, invisible in an unsolved copy; dotted STENCIL/MAP pages
     (a printed grid silhouette marking which cells of another book to
     read); SPINE/COVER ART that tiles across the shelf into an image
     (collection reward, can encode a revealer picture).
- **The Diagonal Book — RATIFIED SIMPLE (Ian, 2026-10-20).** The
  Capstone's chain is Rule N of Book N, N=1..20. That is all. The
  fill-in-the-blank posture (C) was Claude's elaboration and is
  WITHDRAWN — Ian: "We don't need to incorporate blanks in those
  rules." Final champion-word mechanism PROPOSED (2026-10-20, [prov],
  pending Ian's ratification): the FINAL TRIAL — a last 5x5 mini-grid
  filled from record lines (20 Mysteries + 4 Trial words + the
  Capstone's answer = exactly 25), solved with printed mini-rules to
  one champion word ([prov] CINDER, Book 1's Mystery — full circle).
  Requires Trial books to carry both a Trial word and an ordinary
  Mystery. Claim mechanics still [prov shape].
  EXTENDED (Ian, 2026-10-20, [prov]): the Capstone is THE BOOK WITH
  NO RULES — it prints no elimination rule of its own; the diagonal
  is referenced from the shelf, and the Final Trial's mini-rules are
  gathered from plants hidden across the series (Books 13, 7, 16, 18
  [prov]), named by the Capstone's manifest with exact addresses. A side-quest companion volume whose chain is borrowed
  across the series: Rule 1 of Book 1, Rule 2 of Book 2, ... Rule N of
  Book N, down the diagonal, solved to one word as usual. Engine cost:
  nearly zero — chains are data, rules are shared classes; the Advisor
  retunes keep_fractions for the new composition. P3 tension (a book
  requiring 19 others) with three print postures:
  (A) Tribute: print the rules in full, reveal the diagonal as a
      collector's aha — standalone-safe, but no multi-book requirement.
  (B) Reference-only ("Apply Book 7, Rule 7") — truly requires the
      shelf; must live as Apocrypha/clearly-marked companion, never a
      normal listing; used copies still eventually disappoint someone.
  (C) FILL-IN-THE-BLANK rules ("Cross out every word of ___ or ___
      letters whose..."), blanks completed by consulting the owned
      books — nefarious AND forthright: the shelf is the decoder, and a
      used-bookstore finder sees instantly what the object is.
  Capstone candidacy: this volume is a strong Q4/finale instrument —
  solving it requires the whole shelf (proof of the journey), is
  all-paper (P5), and its final word can BE the claim key.
- **Social-media quest steps.** (Ian, 2026-10-20, self-flagged as
  low-quality but said aloud per house rule.) Parts of the quest could
  require posting on social media. Risks: reads as spammy/growth-hack,
  excludes players without accounts (P3-adjacent spirit: the paper
  should suffice), platform dependence (a dead platform breaks a printed
  book's instruction — books outlive platforms). Salvageable kernels:
  celebration over requirement — first-finisher announcements, flair,
  cohort events can LIVE on social media without any quest step
  REQUIRING it; community-wide milestones ("when 100 finishers
  register, X unlocks") give collective-action energy with the Registry,
  not a platform, as the mechanism.
- **Bundles.** "Full pilgrimage" bundle on the direct store; gift
  certificates are standard store machinery. Not possible on Amazon.
- **Certificates as quest objects.** A planted coordinate printed on the
  first-finisher certificate itself — winning the race yields a quest
  piece.
- **Ceramic tokens.** Codes inscribed in leather-hard clay, ELIMINOID
  stamp; batch-producible in normal firings; near-indestructible in the
  mail with padding; tamper-proof and photogenic (photos of them are free
  marketing — put codes on the reverse so photos don't leak them).
  VETOED (Ian, 2026-10-20): the shard variant — tokens as pieces of one
  deliberately broken vessel that assemble to reveal a spanning engraving.
  Vetoed for practical reasons (controlled fracture is unreliable, sharp
  edges in the mail, replacement logistics). Tokens stay WHOLE objects.
  Recorded rather than deleted per the house rule: wild ideas keep
  flowing, vetoes get remembered so they aren't re-pitched.
- **Tokens as finale tickets.** The finale challenge could require the
  code from a mailed token (proof of sustained participation, not just
  armchair aggregation). Needs a fairness valve for mail delays and
  international players.

## Channels & production facts

- **Amazon KDP:** mass discoverability; no coupons, no variants, no API,
  no bundles. One interior file per listing. Author copies at print cost
  (~$3-5) are the self-fulfillment inventory.
- **Lulu:** "Lulu Direct" = no-code Shopify/WooCommerce integration,
  drop-ships to the customer in plain packaging. "Lulu Print API" = REST +
  OAuth + sandbox; POST a print job with interior/cover PDF URLs and a
  product spec; webhooks for status. The API enables anything per-order.
  ~109-page 5.5x8.5 B&W paperback ≈ $3-4 print + shipping.
- **Current book product** (see README.md): Eliminoid, 5.5"x8.5", 100
  pages of 25x5 grids (12,500 words), Courier Prime 9.5pt caps grid, PT
  Serif text, rules up front, CHECKPOINT warnings before state-dependent
  rules, spoiler-guarded per-rule counts appendix. Build: ~20s, verified,
  zero backtracks.

## The engine is ready for the quest layer

- Planted words at fixed cells = Exact-arity demand with a pinned-cell
  generator (AnswerRule already does the word half). A PlantedWordRule +
  quest manifest (quests.json: word, book, cell) is an afternoon.
- Harvest beats planting: for anything referencing an already-PUBLISHED
  book, just search its book.json for words/coordinates that spell what's
  needed — no planting, infinitely flexible, retroactive. Planting is only
  for exotic words (domains, invented words) and forward references.
- Mystery-chain answers can be HARVESTED too: choose Book N's answer from
  words that already occur in the required earlier books (search is
  trivial), subject to the answer surviving Book N's chain — the Phase-0
  analyzer validates all of it before any build.
- The decoy wordChooser can take an acrostic constraint (initial letters).
- book.json is the redemption oracle as-is.

## The mechanism kit (proposed 2026-10-20, for the linear map)

**The Series map lives in SERIES-MAP.md (v0 drafted 2026-10-20):**
20 books + Capstone, four acts closed by Trials (5/10/15/20), four
retro-revelations (3/10/15/20), three showpieces (7/12/14), mechanism
debut ramp through Acts I-III, Act IV = mastery/convergence, the
Diagonal Book consuming Trial words + letter strip into the claim key.
That file is the master plot; this file stays the decision log.

Design law for combining mechanisms: each has ONE role, is introduced
once with a plain explanation (P4), and then RECURS predictably — the
journey should become a learnable ritual, not a grab-bag of tricks.
Layers:
1. **The Spine — MysteryCode chain** (every book from Book 2 on): the
   uniform per-book ritual. Finish -> write the Answer -> follow the
   printed Mystery instruction (coordinate lookups into earlier books)
   -> write the MysteryCode. One per book, same furniture every time
   (Answer page, Mystery page).
2. **The Pulse — Trials** (roughly every 5th book; REDEFINED per P6):
   a printed FILL-IN MINI-GRID whose cells are labelled with
   coordinates across the span's books ("fill these 36 cells from
   these 36 Coordinates in Books 1-5"), followed by a printed
   mini-chain applied to that grid — the player crosses out words in
   the fresh mini-grid until one Trial word remains, and writes it in
   a box. Multi-book by construction, paper-pure, no reliance on any
   transient state (merges the old key-assembly idea with
   meta-elimination). Engine-verifiable like any book.
3. **Retro-revelations** (irregular, 3-4 per series; P6-constrained):
   a later book reveals dormant content in an earlier one — but only
   content that is DURABLE: printed-but-unexplained pages, patterns in
   printed words at coordinates, the first letters of written
   MysteryCodes. Never transient solve states. The "your old book just
   got deeper" moments; pure P2 revealers.
4. **Texture showpieces** (sparing, 2-3 per series; P6-constrained):
   DEAD-CELL PICTURES — VETOED (Ian, 2026-10-20): "we'd want the
   filled-in pattern on the page to look like something and that's
   hard to do with a 5 x 25 grid." Five cells wide is no canvas.
   (The engine's shape-masked placement capability remains, unused;
   it would only revive on a much wider grid format.) Stencil pages
   (printed) are fully durable and still in.
5. **The Capstone — the Diagonal Book**: needs the whole shelf; its
   final word is the claim key (see Q4 lean).
6. **Physical continuity (non-puzzle)**: spine art tiling across the
   shelf; tokens/certificates as amenities. Collection pride with zero
   quest load.
Substrate extensions (proposed 2026-10-20 — more mechanisms on the
P6 substrate of printed ink + player-written ink):
- **Snapshot boxes** (the P6-legal rescue of transient state): an
  in-line instruction captures a mid-solve fact INTO WRITING at the
  moment it exists — "when you finish this rule, one word in row 1 of
  page 17 is still standing; write it here." Design law: every snapshot
  must be LOCALLY recomputable (a few cells' worth of re-checking), so
  a missed snapshot is recoverable, never fatal. Generalizes pause
  pages from spectacle into durable mechanism.
- **Rule-as-compass**: any spelling rule can be reapplied POST-HOC as a
  pointer over printed text, because applying a rule to printed words
  needs no solve history — "apply Rule 3 to column 2 of page 77; the
  4th word it would cross out is your next word." Reuses skills the
  player already learned; infinitely composable; zero new concepts.
- **Printed checksums** — SUPERSEDED by D11 (2026-10-21). The letter-sum
  checksum (A=1..Z=26) was endorsed 2026-10-20 as offline self-
  verification, but Ian later rejected it: it collides heavily (a wrong
  word often shares a sum) and leaks information about the word.
  Replaced by a one-way salted hash checked on eliminoid.com — precise,
  one-way, and still optional (solving never requires the site). The
  letter-sum is also gone from the Mystery derivation (see D10).
- **The record page**: passport-style page in every book where ALL
  written state lives (Answer, MysteryCode, Trial word, snapshots) in
  numbered lines — later books reference "Book 4's record page, line
  3" uniformly. Furniture that makes written state addressable.
  ENDORSED (Ian, 2026-10-20) with a tone caveat: "feels a bit like
  filling in tax forms" — the page's DESIGN must fight that (an
  illustrator-brief item: it should feel like a logbook/artifact, not
  paperwork), and later books should use it for mechanisms neat enough
  to justify the ritual.
- **Letter strips**: a row of boxes assembling single letters drawn
  from the book's own written state ("box 1: 3rd letter of your
  Answer..."); strips concatenate across the series into the finale's
  input.
- **Two-book juxtaposition**: identical trim and grid geometry across
  all books enables "open Book 3 and Book 5 to page 10 side by side;
  read rows alternating left/right" — interleaved sequences from pure
  print.
- **Drawn-line geometry**: VETOED (Ian, 2026-10-20, gut feel: "clever
  but they feel wrong"). Was: ruled lines between listed cells forming
  a figure. Removed from the map (Books 9 and 19 reworked); recorded
  here per house rule.
- **Folio arithmetic**: light connective tissue from printed structure
  (page numbers, appendix counts) — "the page where Rule 9 lives minus
  the page where Rule 2 lives..."
- **Grid acrostics (Ian, 2026-10-20 — endorsed trio, part 1):** plant a
  message in the INITIALS of words running down a column (or any letter
  position per cell — "whatever works"). Engine: a per-cell letter-at-
  position constraint intersected into the cell's pool (~1/26 supply
  cut per cell; pools of thousands absorb it; Phase-0 validates).
  Message cells must route to filler-owned cells or constrain choosers.
  Pure print = fully durable (P6); needs a revealer (P2). Construction
  difficulty: MEDIUM.
- **Mid-game Nth-survivor reading (part 2, v1's reveal machinery
  reborn):** an in-line instruction at rule k's own position: "pause:
  read every Nth still-standing word on page P; write them here."
  P6-legal ONLY as a snapshot box (captured in the moment); keep the
  span one page and N small so recomputation stays bounded (the
  recoverability law). Construction: the builder must plan death-steps
  (standing-at-k = deathIndex > k) AND plant clue words at the
  Nth-standing cells — the fanciest construction ask yet, but every
  needed capability (Plant, death-step control, verification) exists.
  Difficulty: HIGH.
- **Nth-word-any-state reading (part 3):** "read every Nth word on page
  P, alive or dead." Ian filed it as tricky — it is actually the
  EASIEST of the three: alive-or-dead means ALL printed words, i.e.
  pure print, zero state, consultable any time (this is why the
  readable-dead instruction matters). Just plants at arithmetic
  positions. Difficulty: LOW.
- FLAGGED RISKY, parked: fold-alignment tricks (fold corner to corner
  to bring cells into contact) — POD trim variance (~1mm) makes
  physical registration unreliable across printings.

Pedagogical ramp: Book 2 teaches the Spine ritual in its simplest form;
the first Trial lands once the ritual is habitual (Book ~5); the first
retro-revelation lands mid-series when trust is established.

## Open questions

- **Q1 (sharpie problem):** mysteries require READING eliminated words in
  earlier books. A Sharpie-obliterator is stuck. Strategy chosen 2026-10-20:
  see D7 below once ratified — candidate answer: instruct thin strikes
  (lore-flavored: "never obliterate the dead"), plus a finisher-portal
  "archive oracle" for verified owners as the safety net. Printed
  full-word-list index rejected? (pending Ian's call)
- **Q2 — RESOLVED (Ian, 2026-10-21): see D10.** The uniform all-FIND
  formula scales to every book, only references backward, and (via D11's
  per-box website hash) lets the site verify each book's Mystery
  independently, not only the final chain.
- **Q3 — RESOLVED (Ian, 2026-10-20): no row/column labels.** Grid
  pages stay clean; readers finger-count ("they can finger-count down
  14 rows"). Revisit only if beta testers struggle.
- **Q4 (finale reveal mechanism):** prize form decided (D7); the open
  half is HOW the end of the quest reveals itself and selects the winner.
  Options on the table:
  (a) Convergence: the books' mystery words combine (e.g. as coordinates
      back into the books) into a final instruction/URL; first verified
      redemption wins. Fully online-verifiable; race decided by months of
      head start, which may feel unfair to late bingers.
  (d) Synchronized finale event: when the last book's cohort is ready, a
      final challenge drops for everyone AT ONCE (all verified finishers
      eligible); first correct submission wins. Resets the race so
      binge-catchers have a real shot; creates a season-finale event.
  Current lean (2026-10-20): the Capstone selects (all-paper; owning
  and solving the shelf IS the qualification); the winner mails a
  letter; the prize ships by insured mail. No dig, no burial. On the
  same day, the pre-committed solution document is published (its
  hash was posted before Book 1 sold), proving it was all planned in
  advance.
- **Q5:** cadence (two months? seasons?) and how the easy/hard fork
  coexists with serial drops.
- **Q6:** redemption identity model (email? account?) and what single-use
  means without variants.
- **Q7 (claim-to-delivery pipeline, expanded 2026-10-20):** the lean:
  (1) claim by mailed letter (champion word + contact) to the
  strange-page address; (2) VERIFICATION by correspondence against
  the oracle (trail questions only a real solver answers) — standard
  printed in the Capstone per P4; (3) selection by postmark, one
  claim per person; (4) delivery by insured mail, potter-packed; (5)
  the pre-committed solution document published the same day. Open
  commitments: an IMMORTAL ADDRESS (PO box renewed for the quest's
  life; consider a printed fallback line "or successor address at
  eliminoid.com" as a logged P5 exception); the written verification
  protocol; a contest-law check before freeze (skill-based, Canada;
  printed eligibility/rules paragraph). Address design (2026-10-20):
  strange-page INITIALS spell the full address (grid words can't —
  numbers and "PO" aren't dictionary words); addressee is a
  quest-name ("The Keeper of the Eliminoid") over a Canada Post PO
  box — Ian's name and home never in print; city-level disclosure is
  inherent to mail (a box one city over is the privacy upgrade if
  wanted); the address's secrecy is NOT load-bearing — verification
  is.
- **Q8 (logo):** ELIMINOID logo not yet designed. Hard constraint from
  D7: it must work as a CLAY STAMP -- bold strokes, no fine detail,
  legible in relief at 2-3 cm, ideally circular composition. Design the
  logo stamp-first and let print/web inherit it, not the reverse. (Ian
  already 3D-prints pottery stamps -- the lab repo's 3d/stamps/ workflow
  -- so stamp fabrication is in-house.) The sigil itself could quietly be
  a quest element (monogram hiding a glyph or coordinate).

## Risk register (carried from brainstorms)

- Completion-rate arithmetic: sequential-dependency funnels multiply down
  fast; easy-path books and standalone-satisfying design are the
  mitigations.
- Public cadence = public misses; bank books first.
- Answer leaks in early books (pre-prize): accepted cost per D3.
- Reddit self-promo immune response: be the transparent author-organizer.
- Font/content licensing: all book assets must be OFL or owned (Courier
  Prime + PT Serif are OFL; Elegant Typewriter was dropped for licensing).
- (Buried-prize liability: moot since 2026-10-20 — no burial.)
