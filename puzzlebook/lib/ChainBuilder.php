<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Rules\ConstructsCells;
use Puzzlebook\Rules\SpellingRule;

/**
 * Builds the book. This class is an ALLOCATOR, not a library of rule
 * knowledge: it decides how many cells each step may claim, asks each rule
 * to claim them, puts the revealed words where the reveal will read them,
 * then fills in real dictionary words satisfying whatever each cell's plan
 * asks for. It then verifies the result by replaying the whole chain.
 *
 * Nothing here knows what a palindrome is, what a knight's move is, or that
 * some rules work in pairs. That all lives in lib/Rules/*, behind
 * ConstructsCells::construct(), so adding a new mechanism means writing one
 * class -- not editing this file.
 *
 * The four phases:
 *
 *   1. Reserve the answer. Its cell is chosen first, and its knight's-move
 *      neighbourhood is marked off-limits for survivors, so no rule can
 *      arrange for the answer to be crossed out.
 *   2. Let the rules claim cells, in construction-priority order -- rules
 *      that DEFINE which words survive go first, then rules needing
 *      particular geometry, then rules that will take any free cell.
 *      Budgets are worked out between those tiers, because the number of
 *      survivors isn't known until the survivor-defining rules have run.
 *   3. Put the revealed words in the slots the reveal will actually read.
 *   4. Fill every cell with a real word matching its plan, in reading order.
 *
 * If an arrangement violates a constraint, that attempt is abandoned and
 * another seed tried (see ConstructionRetry). A build fails only after every
 * attempt fails, and then it names the constraints that blocked it.
 */
final class ChainBuilder
{
    /**
     * How many arrangements to try before declaring the rule set unbuildable.
     *
     * Every rule that claims cells can retry, and when one does, the WHOLE
     * attempt is discarded -- so the book only succeeds when every rule
     * succeeds in the same attempt at once. With one or two rules that's
     * likely on the first try; with a dozen independently-fallible ones
     * (several pair-on-a-page rules, the reveal landing clear of every
     * launch pad, every page-local placement finding room) the odds
     * multiply down fast even though no single rule is actually broken.
     *
     * Raised well past what a handful of rules would ever need, because the
     * failures mostly happen cheaply: deciding which rule claims which cell
     * does no dictionary work at all, so most retries never reach the
     * expensive part (filling in real words). If a build is still failing
     * at this limit, that's no longer "unlucky" -- see the per-reason counts
     * it prints, and look for one happening on every single attempt rather
     * than a spread of many small, independent ones.
     */
    private const MAX_ATTEMPTS = 20000;

    /**
     * How many times a SINGLE construction step (one rule, or one of the
     * bookkeeping steps between rules) gets retried in place -- a fresh seed,
     * everything before it in this attempt left untouched -- before giving up
     * on it and escalating to the step before IT. See attemptWithBacktracking().
     */
    private const MAX_LOCAL_ATTEMPTS = 25;

    private const ANSWER_SEED = 31000;
    private const STEP_SEED = 32000;

    /** Survivors beyond the reveal's strict minimum, so the last slots aren't knife-edge. */
    private const SURVIVOR_SLACK = 8;

    /**
     * $onAttempt, if given, is called with the attempt number (0-based) and the retry
     * reason tally SO FAR, before each attempt starts -- bin/build.php uses it to print
     * a heartbeat. Without this, a slow run and a HUNG one look identical (nothing is
     * written anywhere until build() returns or exhausts MAX_ATTEMPTS), AND the only
     * place the retry tally normally gets printed is after every one of those attempts
     * has already failed -- which can be a very long wait at this scale. Optional and a
     * no-op by default so every other caller (bin/check.php never calls build() at all)
     * is unaffected.
     *
     * $onFillProgress, if given, is passed down into fillWords() -- see its own
     * docblock. Covers the gap $onAttempt can't: a single attempt that's slow rather
     * than stuck (a budgeted step whose real word supply is thinner than the cells it
     * was asked to fill) prints nothing via $onAttempt, since that only fires BETWEEN
     * attempts.
     *
     * $onLocalRetry, if given, is called (fromIndex, fromStepId, toIndex, toStepId,
     * reason) every time attemptWithBacktracking() backtracks WITHOUT starting a new
     * top-level attempt. Distinguishes "most of this attempt survived, just one step
     * got redone" from a full reroll, which $onAttempt already reports on its own --
     * without this, filling visibly restarting from cell 0 is ambiguous between the
     * two (filling itself has no checkpoints yet, so ANY backtrack redoes it in full,
     * even when construction mostly didn't change).
     *
     * @return array{grid: array{pages:int,rows:int,cols:int}, words: string[], attempts: int}
     */
    public static function build(
        string $layoutPath,
        string $chainPath,
        string $wordlistPath,
        ?callable $onAttempt = null,
        ?callable $onFillProgress = null,
        ?callable $onLocalRetry = null
    ): array {
        $layout = JsonConfig::load($layoutPath);
        $chain = JsonConfig::load($chainPath);
        $chain = ChainPlan::numberSteps($chain);
        $grid = new Grid($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);

        $plan = ChainPlan::fromConfig($chain);
        $plan->validateReservedWords($chain['answer']);

        // Read once and reused for every attempt. Rules that pair words up need to
        // know what the dictionary holds before they can say what they want.
        $wordPool = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($wordPool === false) {
            throw new \RuntimeException("Could not read the word list at {$wordlistPath}");
        }

        // A content hash of the word list file -- md5_file() reads the file once in
        // C, which is far cheaper than hashing the 133,000-element array we just
        // built from it. This is what WordIndexCache uses to tell whether a
        // dictionary-derived index saved on an earlier run still applies.
        $wordPoolHash = md5_file($wordlistPath);
        if ($wordPoolHash === false) {
            $wordPoolHash = '';
        }

        $spellingRules = $plan->spellingRules();

        // One dictionary for the whole build; attempts reset it rather than rebuild it.
        $dictionary = new Dictionary($wordlistPath, $layout['filler_excluded_initial'] ?? '');

        // For every "survives the first N spelling rules" prefix a cell's plan might
        // ask for, the words that do -- from $dictionary's own shuffled pool (so
        // filler keeps coming out spread across the alphabet, not in
        // data/wordlist.txt's order). Built once, reused by all 20,000 possible
        // attempts: this depends only on the word list and the chain's RULES, never
        // on anything an individual attempt decides, so there's nothing to recompute
        // between attempts.
        //
        // This is also the fix for a bug that's hit this project three times: a
        // CellPlan's own shape and its prefix of spelling rules are the only things
        // that should ever gate which words even reach a cell's own predicate at fill
        // time. Everything else -- a cell's neighbours, what's already on its page --
        // varies by WHICH CELL is asking, not by word, and belongs entirely in that
        // per-cell predicate (see fillWords()), never baked into a set like this one.
        $excludedInitial = $layout['filler_excluded_initial'] ?? '';
        $rawPool = $dictionary->rawPool();
        $survivorsByPrefix = self::prefixPools($rawPool, $spellingRules);

        $prefixPools = [];
        $letterPrefixPools = [];
        $lengthPrefixPools = [];
        foreach ($survivorsByPrefix as $prefix => $survivors) {
            // The letter-pinned pools keep every survivor, excluded initial included: a
            // caller asking for a specific letter (a knight's-move anchor, the right
            // half of a matching-neighbour-ends pair) wants it ON PURPOSE -- see
            // Dictionary::fillerFromPool()'s docblock for why that pool must not apply
            // the exclusion for it.
            $byLetter = [];
            foreach ($survivors as $w) {
                $byLetter[$w[0]][] = $w;
            }
            $letterPrefixPools[$prefix] = $byLetter;

            // The plain pool is what ordinary, no-letter-pinned filler draws from, so
            // it excludes filler_excluded_initial up front -- the one thing every one
            // of those calls agrees on, so there's nothing left to re-check per call.
            $prefixPools[$prefix] = $excludedInitial === ''
                ? $survivors
                : array_values(array_filter(
                    $survivors,
                    static fn (string $w): bool => $w[0] !== $excludedInitial
                ));

            // Split that SAME pool further by length, for a cell whose plan reports a
            // fixedLength() (LetterAtPositionRule, WordLengthRule, DecoyLengthRule --
            // any rule whose own length parameter means EVERY word it could ever
            // accept is one specific length). Without this, filling such a cell means
            // scanning every surviving word of every length looking for the rare ones
            // that happen to be the right one -- wasted work on the vast majority that
            // could never match regardless of this cell's own neighbours or page,
            // repeated for every single cell that rule claims.
            $byLength = [];
            foreach ($prefixPools[$prefix] as $w) {
                $byLength[strlen($w)][] = $w;
            }
            $lengthPrefixPools[$prefix] = $byLength;
        }

        /** @var array<string,int> $retryReasons */
        $retryReasons = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if ($onAttempt !== null) {
                // $retryReasons so far, not just the attempt number: the whole point is
                // to see WHY attempts are failing without waiting for all MAX_ATTEMPTS
                // to exhaust, which is the only other place this tally gets printed.
                $onAttempt($attempt, $retryReasons);
            }
            try {
                $words = self::attemptWithBacktracking(
                    $attempt,
                    $grid,
                    $plan,
                    $chain,
                    $dictionary,
                    $wordPool,
                    $wordPoolHash,
                    $prefixPools,
                    $letterPrefixPools,
                    $lengthPrefixPools,
                    $onFillProgress,
                    $onLocalRetry
                );

                return [
                    'grid' => ['pages' => $grid->pages, 'rows' => $grid->rows, 'cols' => $grid->cols],
                    'words' => $words,
                    'attempts' => $attempt + 1,
                ];
            } catch (ConstructionRetry $e) {
                $reason = $e->getMessage();
                $retryReasons[$reason] = ($retryReasons[$reason] ?? 0) + 1;
            }
        }

        arsort($retryReasons);
        $detail = '';
        $shown = 0;
        foreach ($retryReasons as $reason => $n) {
            if ($shown++ >= 6) {
                $detail .= "\n  ... and " . (count($retryReasons) - 6) . ' other reason(s)';
                break;
            }
            $detail .= "\n  {$n}x  {$reason}";
        }
        throw new \RuntimeException(
            'Tried ' . self::MAX_ATTEMPTS . ' arrangements and none satisfied every constraint. What blocked them:'
            . $detail
            . "\n\nRun bin/check.php for a breakdown of the arithmetic and word supply."
        );
    }

    // ------------------------------------------------------------------- word pools

    /**
     * For every "survives the first N spelling rules" prefix, the words from $pool
     * that do -- unfiltered by anything else (filler_excluded_initial, a specific
     * letter) a caller wants on top, which it applies itself afterward, the way
     * build() already splits "plain" filler from letter-pinned filler.
     *
     * Shared by build() and bin/check.php -- and exposed to rule construct()
     * methods via ConstructionContext::survivorsSoFar() -- so every one of them
     * partitions the SAME word list the SAME way. A rule that needs genuine word
     * PAIRS rather than just a shape (two anagrams, a word hiding inside another)
     * uses this to pick both halves up front, during construction, and know
     * they're REALLY both available, rather than picking one and hoping a match
     * for it is still around by the time fillWords() gets there.
     *
     * @param string[] $pool
     * @param SpellingRule[] $spellingRules
     * @return array<int,string[]>
     */
    public static function prefixPools(array $pool, array $spellingRules): array
    {
        // Which spelling rules each word fails, as one bit per rule. Asked directly,
        // "does this word survive the first k rules" is k calls into k rule objects
        // PER CANDIDATE; precomputed it's a single integer AND. One pass over the
        // word list builds the whole table.
        $failureMasks = [];
        foreach ($pool as $word) {
            $mask = 0;
            foreach ($spellingRules as $index => $rule) {
                if ($rule->failsWord($word)) {
                    $mask |= 1 << $index;
                }
            }
            $failureMasks[$word] = $mask;
        }

        $pools = [];
        $spellingCount = count($spellingRules);
        for ($prefix = 0; $prefix <= $spellingCount; $prefix++) {
            $prefixMask = (1 << $prefix) - 1;
            $pools[$prefix] = array_values(array_filter(
                $pool,
                static fn (string $w): bool => (($failureMasks[$w] ?? 0) & $prefixMask) === 0
            ));
        }
        return $pools;
    }

    // ------------------------------------------------------------------ one attempt

    /**
     * Builds the whole book for one top-level attempt -- construction, filling,
     * verifying -- as a single flat list of STEPS, walked with checkpoint-and-
     * backtrack: snapshot the canvas before each step, and if a step (or
     * filling/verifying at the very end) fails in a way blamed on one specific
     * EARLIER step, restore to that step's snapshot and re-walk forward from
     * there with a fresh seed, instead of discarding this whole attempt.
     *
     * Each step gets its OWN bounded number of local retries
     * (MAX_LOCAL_ATTEMPTS) before giving up on it and escalating to whichever
     * step is blamed for THAT failure (which may be the same step again, one
     * step earlier, or -- for a failure with no attributable step, like a
     * cascading budget mismatch or the wrong final survivor count -- the very
     * first step). If escalation ever walks past the first step, this throws
     * out to build()'s own loop, which starts a completely fresh top-level
     * attempt -- the same safety net every attempt has always had, now just
     * the last resort instead of the only tool.
     *
     * @return string[]
     */
    private static function attemptWithBacktracking(
        int $attempt,
        Grid $grid,
        ChainPlan $plan,
        array $chain,
        Dictionary $dictionary,
        array $wordPool,
        string $wordPoolHash,
        array $prefixPools,
        array $letterPrefixPools,
        array $lengthPrefixPools,
        ?callable $onProgress = null,
        ?callable $onLocalRetry = null
    ): array {
        $canvas = new Canvas($grid);
        $size = $grid->size();
        $answer = $chain['answer'];
        $revealWords = $plan->revealWords();
        $revealN = $plan->revealInterval();
        $targetSurvivors = $revealN * count($revealWords) + self::SURVIVOR_SLACK;

        // The answer's own placement isn't part of the backtracking loop below --
        // it varies only with $attempt (a fresh top-level attempt picks a new
        // cell for it), never with a local retry. It's foundational enough, and
        // cheap enough to get right first try, that giving it its own retry
        // tier would add complexity for a failure mode that's never actually
        // the bottleneck.
        $answerCell = Rng::shuffle(range(0, $size - 1), self::ANSWER_SEED + $attempt)[0];
        foreach ($grid->knightNeighbours($answerCell) as $n) {
            $canvas->forbidSurvivor($n);
        }
        $canvas->claimSurvivor(
            $answerCell,
            null,
            CellPlan::literal($answer, $plan->spellingRuleCount(), 'answer'),
            false
        );
        $canvas->reserveWord($answer);

        $constructors = $plan->constructorsByPriority();
        $derived = [];
        $budgeted = [];
        foreach ($constructors as $entry) {
            [$stepId] = $entry;
            if ($plan->hasBudget($stepId)) {
                $budgeted[] = $entry;
            } else {
                $derived[] = $entry;
            }
        }

        // Filled in by the "compute budgets" step below, read by every budgeted
        // rule's step after it -- by reference, since these are plain closures,
        // not objects with their own state to share.
        $budgets = null;

        /** @var array<int, array{0: ?int, 1: callable(int): void}> $steps [chain stepId or null, run($seed)] */
        $steps = [];

        foreach ($derived as [$stepId, $rule]) {
            $steps[] = [$stepId, static function (int $seed) use (
                $rule,
                $canvas,
                $plan,
                $stepId,
                $targetSurvivors,
                $wordPool,
                $wordPoolHash,
                $prefixPools
            ): void {
                self::invokeConstruct(
                    $rule,
                    $canvas,
                    $plan->contextFor($stepId, 0, $targetSurvivors, $seed, $wordPool, $wordPoolHash, $prefixPools)
                );
            }];
        }

        $steps[] = [null, static function (int $seed) use (&$budgets, $plan, $canvas, $size): void {
            $budgets = $plan->budgetsFor(count($canvas->freeCells()), $size);
        }];

        foreach ($budgeted as [$stepId, $rule]) {
            $steps[] = [$stepId, static function (int $seed) use (
                $rule,
                $canvas,
                $plan,
                $stepId,
                &$budgets,
                $targetSurvivors,
                $wordPool,
                $wordPoolHash,
                $prefixPools
            ): void {
                self::invokeConstruct(
                    $rule,
                    $canvas,
                    $plan->contextFor(
                        $stepId,
                        $budgets[$stepId] ?? 0,
                        $targetSurvivors,
                        $seed,
                        $wordPool,
                        $wordPoolHash,
                        $prefixPools
                    )
                );
            }];
        }

        // A rule that claims cells in groups -- pairs, say -- can't always spend
        // its budget to the last cell, so a few may be left with nothing to
        // eliminate them. Hand those to the most flexible rule in the chain (one
        // that takes any cell), rather than failing over a rounding remainder.
        $steps[] = [null, static function (int $seed) use (
            $canvas,
            $constructors,
            $plan,
            $targetSurvivors,
            $wordPool,
            $wordPoolHash,
            $prefixPools
        ): void {
            $leftOver = $canvas->freeCells();
            if ($leftOver === []) {
                return;
            }
            $absorber = null;
            foreach ($constructors as [$stepId, $rule]) {
                if ($rule instanceof SpellingRule) {
                    $absorber = [$stepId, $rule];
                }
            }
            if ($absorber === null) {
                throw new ConstructionRetry(
                    count($leftOver) . ' cells were left over and no rule in the chain will take any cell'
                );
            }
            [$stepId, $rule] = $absorber;
            self::invokeConstruct(
                $rule,
                $canvas,
                $plan->contextFor($stepId, count($leftOver), $targetSurvivors, $seed, $wordPool, $wordPoolHash, $prefixPools)
            );
            if ($canvas->freeCells() !== []) {
                throw new ConstructionRetry(
                    count($canvas->freeCells()) . ' cells were left with no rule to eliminate them'
                );
            }
        }];

        $steps[] = [null, static function (int $seed) use ($canvas, $revealWords, $revealN, $plan): void {
            $survivors = $canvas->survivors();
            foreach ($revealWords as $k => $word) {
                $position = ($k + 1) * $revealN;
                if (!isset($survivors[$position - 1])) {
                    throw new ConstructionRetry(
                        'the reveal wanted survivor #' . $position . ' but only ' . count($survivors) . ' survive'
                    );
                }
                $cell = $survivors[$position - 1];
                if (!$canvas->isReplaceable($cell)) {
                    throw new ConstructionRetry(
                        "reveal slot #{$position} landed on a cell whose word the rules need as it is"
                    );
                }
                $canvas->rewritePlan($cell, CellPlan::literal($word, $plan->spellingRuleCount(), 'reveal'));
            }
        }];

        // The final step: fill every cell, then verify the whole chain. A
        // failure here still names a $stepId where possible (a fill-time literal
        // collision, via Canvas::killedByFor()) -- verify()'s own failures don't
        // (see ConstructionRetry's docblock), which simply means backtracking
        // falls back to step 0, same as every attempt did before this existed.
        $result = null;
        $steps[] = [null, static function (int $seed) use (
            $canvas,
            $dictionary,
            $chain,
            $plan,
            $prefixPools,
            $letterPrefixPools,
            $lengthPrefixPools,
            $onProgress,
            &$result
        ): void {
            $dictionary->reset();
            $words = self::fillWords(
                $canvas,
                $dictionary,
                array_values(array_unique(
                    array_merge([$chain['answer']], $plan->revealWords(), $canvas->reservedWords())
                )),
                $prefixPools,
                $letterPrefixPools,
                $lengthPrefixPools,
                $onProgress
            );
            self::verify($words, $canvas->grid(), $chain, $canvas);
            $result = $words;
        }];

        $stepIndexByStepId = [];
        foreach ($steps as $index => [$stepId]) {
            if ($stepId !== null) {
                $stepIndexByStepId[$stepId] = $index;
            }
        }

        $total = count($steps);
        $checkpoints = [];
        $localAttempts = array_fill(0, $total, 0);
        $index = 0;
        while ($index < $total) {
            $checkpoints[$index] = $canvas->snapshot();
            [, $run] = $steps[$index];
            $seed = self::STEP_SEED + $attempt * 10007 + $index * 101 + $localAttempts[$index] * 97;
            try {
                $run($seed);
                $index++;
            } catch (ConstructionRetry $e) {
                $targetIndex = $e->stepId() !== null ? ($stepIndexByStepId[$e->stepId()] ?? 0) : 0;
                if ($onLocalRetry !== null) {
                    // Distinguishes a LOCAL retry (most of this attempt's work kept,
                    // but filling always restarts from cell 0 -- it has no checkpoints
                    // of its own yet) from a full top-level reroll, which build()'s own
                    // heartbeat already reports separately. Without this, "it got to
                    // 80% and restarted" is ambiguous between the two.
                    $onLocalRetry($index, $e->stepId(), $targetIndex, $steps[$targetIndex][0], $e->getMessage());
                }
                $canvas->restore($checkpoints[$targetIndex]);
                $localAttempts[$targetIndex]++;
                if ($localAttempts[$targetIndex] > self::MAX_LOCAL_ATTEMPTS) {
                    throw $e;
                }
                $index = $targetIndex;
            }
        }

        return $result;
    }

    /**
     * Works out which rule eliminates which cell, and what shape each cell's word has
     * to be -- the whole layout, with no words chosen yet.
     *
     * Split out from attempt() so bin/check.php can run it and then inspect the
     * resulting CellPlans to see what each rule is really asking the dictionary for.
     * That keeps the check honest: it reads the demands the rules actually declare
     * rather than a second, hand-maintained list of what we think they need.
     */
    public static function layOutCells(
        int $attempt,
        Grid $grid,
        ChainPlan $plan,
        array $chain,
        array $wordPool = [],
        string $wordPoolHash = '',
        array $prefixPools = []
    ): Canvas
    {
        $canvas = new Canvas($grid);
        $size = $grid->size();
        $answer = $chain['answer'];
        $revealWords = $plan->revealWords();
        $revealN = $plan->revealInterval();
        $targetSurvivors = $revealN * count($revealWords) + self::SURVIVOR_SLACK;

        // --- 1. The answer, and the zone that protects it. ---

        $answerCell = Rng::shuffle(range(0, $size - 1), self::ANSWER_SEED + $attempt)[0];
        foreach ($grid->knightNeighbours($answerCell) as $n) {
            $canvas->forbidSurvivor($n);
        }
        $canvas->claimSurvivor(
            $answerCell,
            null,
            CellPlan::literal($answer, $plan->spellingRuleCount(), 'answer'),
            false
        );
        // Also reserved as a WORD, not just a cell -- otherwise a rule that picks its
        // own word pairs during construction (PageSignaturePairRule,
        // ContainedWordOnPageRule) has no way to know the answer is spoken for, and
        // could pick it for one half of a pair. (The revealed words don't need the
        // same treatment yet: with no reveal step active, there are none -- but
        // reactivating one would need this revisited, since they're not assigned to
        // a cell until step 3 below, after every pair rule has already run.)
        $canvas->reserveWord($answer);

        // --- 2. Let the rules claim their cells, fussiest first. ---

        // Two passes, split by whether a rule HAS a budget rather than by priority.
        //
        // A rule whose reach is 'derived' takes however many cells its own geometry
        // dictates, with no budget to cap it. So every derived rule has to claim before
        // the budgets for the others are worked out -- otherwise the arithmetic shares
        // out cells that a derived rule then takes, and whichever rule happens to run
        // last comes up short by exactly that much. (That was a real bug: the corner
        // rule quietly took ~200 cells the budgets had already promised elsewhere.)
        //
        // Priority still orders the rules WITHIN each pass.
        $constructors = $plan->constructorsByPriority();
        $budgeted = [];

        foreach ($constructors as [$stepId, $rule]) {
            if ($plan->hasBudget($stepId)) {
                $budgeted[] = [$stepId, $rule];
                continue;
            }
            self::invokeConstruct(
                $rule,
                $canvas,
                $plan->contextFor($stepId, 0, $targetSurvivors, self::STEP_SEED + $attempt, $wordPool, $wordPoolHash, $prefixPools)
            );
        }

        // Now the budgets can be exact: this is the number of cells genuinely left to
        // share, after everything that sizes itself has taken its share.
        $budgets = $plan->budgetsFor(count($canvas->freeCells()), $size);

        foreach ($budgeted as [$stepId, $rule]) {
            self::invokeConstruct(
                $rule,
                $canvas,
                $plan->contextFor(
                    $stepId,
                    $budgets[$stepId] ?? 0,
                    $targetSurvivors,
                    self::STEP_SEED + $attempt,
                    $wordPool,
                    $wordPoolHash,
                    $prefixPools
                )
            );
        }

        // A rule that claims cells in groups -- pairs, say -- can't always spend its
        // budget to the last cell, so a few may be left with nothing to eliminate them.
        // Hand those to the most flexible rule in the chain (one that takes any cell),
        // rather than failing over a rounding remainder.
        $leftOver = $canvas->freeCells();
        if ($leftOver !== []) {
            $absorber = null;
            foreach ($constructors as [$stepId, $rule]) {
                if ($rule instanceof SpellingRule) {
                    $absorber = [$stepId, $rule];
                }
            }
            if ($absorber === null) {
                throw new ConstructionRetry(
                    count($leftOver) . ' cells were left over and no rule in the chain will take any cell'
                );
            }
            [$stepId, $rule] = $absorber;
            self::invokeConstruct(
                $rule,
                $canvas,
                $plan->contextFor(
                    $stepId,
                    count($leftOver),
                    $targetSurvivors,
                    self::STEP_SEED + $attempt,
                    $wordPool,
                    $wordPoolHash,
                    $prefixPools
                )
            );
            if ($canvas->freeCells() !== []) {
                throw new ConstructionRetry(
                    count($canvas->freeCells()) . ' cells were left with no rule to eliminate them'
                );
            }
        }

        // --- 3. The revealed words go where the reveal will read them. ---

        $survivors = $canvas->survivors();
        foreach ($revealWords as $k => $word) {
            $position = ($k + 1) * $revealN;
            if (!isset($survivors[$position - 1])) {
                throw new ConstructionRetry(
                    'the reveal wanted survivor #' . $position . ' but only ' . count($survivors) . ' survive'
                );
            }
            $cell = $survivors[$position - 1];
            if (!$canvas->isReplaceable($cell)) {
                throw new ConstructionRetry(
                    "reveal slot #{$position} landed on a cell whose word the rules need as it is"
                );
            }
            $canvas->rewritePlan($cell, CellPlan::literal($word, $plan->spellingRuleCount(), 'reveal'));
        }

        return $canvas;
    }

    /**
     * Runs one rule's construct(), with its class name folded into any failure
     * message -- every such message is otherwise just "step N ...", which names
     * the step but not which rule class is behind it. Centralised here, the
     * allocator's one call site for construct(), rather than in each rule: a new
     * rule gets this for free, and no rule file needs to know its own class name
     * for the sake of a message.
     */
    private static function invokeConstruct(ConstructsCells $rule, Canvas $canvas, ConstructionContext $context): void
    {
        try {
            $rule->construct($canvas, $context);
        } catch (\RuntimeException $e) {
            $shortClass = substr(strrchr(get_class($rule), '\\'), 1);
            $message = "[{$shortClass}] {$e->getMessage()}";
            // Whatever this rule's OWN construct() threw, WE know which chain step
            // it was called for -- attaching that here (rather than asking every
            // rule to do it) is what lets attemptWithBacktracking() blame exactly
            // this step and retry just it.
            throw $e instanceof ConstructionRetry
                ? new ConstructionRetry($message, 0, $e, $context->stepId())
                : new \RuntimeException($message, 0, $e);
        }
    }

    // ------------------------------------------------------------------ word filling

    /**
     * Walks the grid in reading order and asks the dictionary for a word satisfying
     * each cell's plan. Fully generic: the only thing this adds to what a plan asks
     * for is the one constraint no single rule can see -- that a word must not
     * accidentally recreate whatever its horizontal neighbours would make of it.
     *
     * @param string[] $reservedWords
     * @param array<int,string[]> $prefixPools words surviving the first N spelling
     *     rules, indexed by N -- see build()
     * @param array<int,array<string,string[]>> $letterPrefixPools the same, split
     *     further by first letter, for calls that pin one
     * @param array<int,array<int,string[]>> $lengthPrefixPools the same, split
     *     further by word length, for a plan whose fixedLength() names one
     * @param ?callable(int,int):void $onProgress Called with (cells filled so far, total
     *     cells) on every cell -- bin/build.php uses it to print a heartbeat DURING a
     *     single attempt, throttled on its own end. Without this, a slow attempt (a
     *     budget that's thin on real supply, scanning deep into the filler pool
     *     thousands of times before giving up) looks identical to a hang: the
     *     attempt-level heartbeat only fires BETWEEN attempts, so one attempt taking
     *     minutes prints nothing at all in the meantime.
     * @return string[]
     */
    private static function fillWords(
        Canvas $canvas,
        Dictionary $dictionary,
        array $reservedWords,
        array $prefixPools = [],
        array $letterPrefixPools = [],
        array $lengthPrefixPools = [],
        ?callable $onProgress = null
    ): array {
        $grid = $canvas->grid();
        $size = $canvas->size();
        $words = array_fill(0, $size, null);
        $invariants = $canvas->invariants();

        // Claim the words that are needed at specific cells before any filler is
        // handed out -- they pass every rule, so they'd otherwise be valid filler
        // somewhere earlier and be gone by the time their own cell comes up.
        $dictionary->reserveAll($reservedWords);

        for ($i = 0; $i < $size; $i++) {
            if ($onProgress !== null) {
                $onProgress($i, $size);
            }
            $cellPlan = $canvas->planFor($i);
            if ($cellPlan === null) {
                throw new \LogicException("Cell {$i} was never given a plan.");
            }
            [, , $col] = $grid->coordsOf($i);

            // A word must not start on the letter its left neighbour ended on -- that
            // would make an accidental matching pair and cross both of them out. The
            // one exception is a cell whose plan says it WANTS that match, i.e. the
            // right half of a deliberate pair.
            $leftLast = null;
            if ($col > 0 && $words[$i - 1] !== null && !$cellPlan->allowsLeftMatch()) {
                $leftLast = WordTags::lastLetter($words[$i - 1]);
            }
            // And it must not end on the letter the cell to its right is pinned to --
            // unless that cell wants the match, in which case it's deliberate.
            $rightFirst = null;
            if ($col < $grid->cols - 1) {
                $rightPlan = $canvas->planFor($i + 1);
                if ($rightPlan !== null && !$rightPlan->allowsLeftMatch()) {
                    $rightFirst = $rightPlan->staticFirstLetter();
                }
            }

            // A word decided in advance is placed directly: it was reserved in the
            // dictionary up front, so a lookup would never hand it back.
            $literal = $cellPlan->literalWord();
            if ($literal !== null) {
                // The message deliberately does NOT include $literal: it's a different
                // specific word almost every attempt (most literal cells are now a
                // rule's own chosen pair, re-picked fresh each attempt -- see
                // PageSignaturePairRule, ContainedWordOnPageRule), so a message built
                // around it would give every occurrence of the SAME underlying,
                // structural problem its own distinct key, and the retry-reason tally
                // (see bin/build.php's heartbeat) would show a long tail of 1x entries
                // instead of the one real, recurring cause.
                if ($leftLast !== null && $literal[0] === $leftLast) {
                    throw new ConstructionRetry(
                        "a predetermined word for {$cellPlan->description()} collided with its left "
                        . 'neighbour',
                        stepId: $canvas->killedByFor($i)
                    );
                }
                if ($rightFirst !== null && WordTags::lastLetter($literal) === $rightFirst) {
                    throw new ConstructionRetry(
                        "a predetermined word for {$cellPlan->description()} collided with its right "
                        . 'neighbour',
                        stepId: $canvas->killedByFor($i)
                    );
                }
                // Predetermined words skip the dictionary, so they'd skip the invariant
                // checks with it -- but a revealed word can collide with a page-wide
                // condition just as easily as any other. EXCEPT for a cell a rule has
                // marked exempt (see Canvas::exemptFromInvariants()): a word a rule
                // chose deliberately for ITS OWN purpose has no fallback if some
                // UNRELATED invariant rejects it, so checking it against one can only
                // waste an attempt -- sometimes unfixably so (a reversal pair is
                // unavoidably also an anagram pair; no retry changes that).
                if (!$canvas->isExemptFromInvariants($i)) {
                    foreach ($invariants as $invariant) {
                        if (!$invariant->allows($literal, $i, $words, $grid)) {
                            // Same reasoning as the adjacency messages above for leaving
                            // $literal out: a different word almost every attempt would
                            // give every occurrence of the same structural problem its
                            // own distinct key in the retry-reason tally. The cell index
                            // is included anyway, temporarily -- diagnosing a suspiciously
                            // frequent invariant break needs to know WHERE, which no amount
                            // of staring at the generic message can answer on its own.
                            throw new ConstructionRetry(
                                "a predetermined word for {$cellPlan->description()} at cell {$i} breaks "
                                . $invariant->describe() . ' there',
                                stepId: $canvas->killedByFor($i)
                            );
                        }
                    }
                }
                $words[$i] = $literal;
                foreach ($invariants as $invariant) {
                    $invariant->record($literal, $i, $grid);
                }
                continue;
            }

            // $words is captured BY REFERENCE deliberately. By value, PHP would have to
            // separate and copy the whole array every time the next cell is filled --
            // once per cell, so quadratic in book size. The closure only reads it.
            //
            // Surviving the cell's prefix of spelling rules is NOT tested here anymore
            // -- $prefixPools[$prefix] (built once in build()) already contains only
            // words that do, so this predicate only has to cover what's true of THIS
            // CELL rather than of the word in general: its neighbours, its own shape,
            // and what's already on its page. Cheapest and most discriminating first,
            // because this runs for every candidate word for every cell -- easily tens
            // of millions of calls across a build.
            $prefix = $cellPlan->survivesPrefix();
            $extraPredicate = static function (string $candidate) use (
                $cellPlan,
                &$words,
                $leftLast,
                $rightFirst,
                $i,
                $invariants,
                $grid
            ): bool {
                // 1. Two character comparisons.
                if ($leftLast !== null && $candidate[0] === $leftLast) {
                    return false;
                }
                if ($rightFirst !== null && WordTags::lastLetter($candidate) === $rightFirst) {
                    return false;
                }

                // 2. The plan's own requirement -- usually a length or letter check.
                if (!$cellPlan->shapeAllows($candidate, $words, $i)) {
                    return false;
                }

                // 3. Dearest by far: signatures to compute, pages to scan. Only reached
                //    by candidates that have passed everything above.
                foreach ($invariants as $invariant) {
                    if (!$invariant->allows($candidate, $i, $words, $grid)) {
                        return false;
                    }
                }
                return true;
            };

            // Many cells share ONE CellPlan instance (every ordinary spelling-step
            // filler does -- see SpellingRule::construct()), which is deliberate: it's
            // what keeps construction from building thousands of plan objects, and
            // every one of them can now safely share this label too. A cell's
            // left/right neighbours and its page are NOT baked into $prefixPools --
            // they're only ever checked by $extraPredicate, fresh, per call -- so
            // Dictionary::fillerFromPool()'s floor only ever advances past words this
            // cell's call confirms are actually USED, never past ones merely rejected
            // for THIS cell's own reasons. A different cell sharing this label still
            // gets to see them. (This is what used to need a per-cell '@'.$i label to
            // avoid a cell's quirks permanently starving a later one; that's no longer
            // needed -- see Dictionary::fillerFromPool().)
            $label = $cellPlan->label();

            // Where the plan pins the first letter, or names a fixedLength(), draw
            // from that slice of the prefix pool instead of the whole thing -- the
            // same win either way: most of the full pool could never match this
            // cell's shape regardless of anything cell-specific, so there's no reason
            // to scan it. Letter takes priority on the rare chance a plan ever sets
            // both; no current rule does.
            $pinned = $cellPlan->firstLetter($words, $i);
            $fixedLength = $cellPlan->fixedLength();
            try {
                if ($pinned !== null) {
                    $words[$i] = $dictionary->fillerFromPoolStartingWith(
                        $pinned,
                        $letterPrefixPools[$prefix][$pinned] ?? [],
                        $extraPredicate,
                        $label
                    );
                } elseif ($fixedLength !== null) {
                    $words[$i] = $dictionary->fillerFromPool(
                        $lengthPrefixPools[$prefix][$fixedLength] ?? [],
                        $extraPredicate,
                        $label
                    );
                } else {
                    $words[$i] = $dictionary->fillerFromPool($prefixPools[$prefix] ?? [], $extraPredicate, $label);
                }
            } catch (ConstructionRetry $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                // Running out of words is a genuine dead end only when NOTHING about
                // this draw could plausibly come out differently on another attempt.
                // Three things make it attempt-dependent rather than a fixed fact
                // about the dictionary: a PARTNER dependency (a different partner
                // might exist for a different leader word); an ADJACENCY constraint
                // (a different layout gives this cell different neighbours); and --
                // easy to miss -- any PAGE-LEVEL INVARIANT in this chain at all
                // (anagram/containment/signature checks). $extraPredicate runs every
                // invariant for every cell regardless of type, and what's already on
                // a page is purely a property of THIS attempt's random layout, not of
                // the word. Only fail the build outright when none of these apply.
                if ($cellPlan->dependsOnOtherCells() || $leftLast !== null || $rightFirst !== null
                    || $invariants !== []) {
                    throw new ConstructionRetry(
                        "no word fit at cell {$i} for {$cellPlan->description()}",
                        stepId: $canvas->killedByFor($i)
                    );
                }
                throw new \RuntimeException(
                    "No word fits at cell {$i} for {$cellPlan->description()} -- the dictionary has "
                    . 'nothing left matching this shape, and nothing about this failure depends on how '
                    . "the book was laid out, so a different arrangement wouldn't help either."
                );
            }

            foreach ($invariants as $invariant) {
                $invariant->record($words[$i], $i, $grid);
            }
        }

        return $words;
    }

    // ------------------------------------------------------------------ verification

    /**
     * Re-plays the whole chain with Engine and throws on the first thing that
     * doesn't match. This is the independent check on everything above:
     * construction decides, this confirms.
     *
     * @param string[] $words
     */
    private static function verify(array $words, Grid $grid, array $chain, Canvas $canvas): void
    {
        $keep = array_fill(0, count($words), true);

        foreach ($chain['steps'] as $step) {
            if ($step['kind'] === 'eliminate') {
                $keep = Engine::applyRule($words, $grid, $keep, $step['rule']);

                if (!empty($step['final'])) {
                    $survivors = Engine::survivingIndices($keep);
                    if (count($survivors) !== 1) {
                        // A sample with cell positions, not just a count -- which page/row/col
                        // and what each survivor's own length is gives a real lead on WHICH
                        // mechanism failed to catch them (a cluster on one page points at a
                        // pair rule; a shared length points at the leftover-cell absorber
                        // having claimed more than its one-rule's-worth of slack).
                        $sample = array_slice($survivors, 0, 30);
                        $detail = [];
                        foreach ($sample as $i) {
                            [$page, $row, $col] = $grid->coordsOf($i);
                            $word = $words[$i];
                            // Which construction step CLAIMED this cell, and what it wanted --
                            // without this, a mystery survivor gives no lead on WHICH rule's
                            // pair got orphaned, only that something was. "nothing claimed it"
                            // would itself be a different, equally real bug (a leftover cell
                            // nobody ever assigned a plan to).
                            $claim = $canvas->planFor($i)?->description() ?? 'no construction step claims this cell';
                            $detail[] = "{$word} (len " . strlen($word) . ", cell {$i}, page "
                                . ($page + 1) . " row {$row} col {$col}) [{$claim}]";
                        }
                        throw new ConstructionRetry(
                            'the final step left ' . count($survivors) . ' words instead of 1 -- first '
                            . count($sample) . ": " . implode('; ', $detail)
                        );
                    }
                    if ($words[$survivors[0]] !== $chain['answer']) {
                        throw new ConstructionRetry(
                            "the last word standing was '{$words[$survivors[0]]}', not '{$chain['answer']}'"
                        );
                    }
                }
                continue;
            }

            $revealed = Engine::computeReveal($words, $grid, $keep, $step['reveal'])['text'];
            if ($revealed !== $step['expected']) {
                throw new ConstructionRetry(
                    "step {$step['id']}'s reveal read '{$revealed}' instead of the expected phrase"
                );
            }
        }
    }
}
