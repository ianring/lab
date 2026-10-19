#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-flight check on a rule set, WITHOUT building a book.
 *
 * The point is iteration speed: edit config/chain.json, run this, and find out
 * whether the rule set can work -- and if not, what's short and by how much --
 * in about a second, instead of discovering it from a stack trace partway
 * through a full build.
 *
 * What it checks:
 *
 *   1. Is the chain's SHAPE buildable? Every eliminate step needs a rule that
 *      knows how to construct itself, one reveal, one final step.
 *   2. Do the answer and every revealed word SURVIVE all the spelling rules?
 *      The classic authoring trap -- a rule added later silently kills a word
 *      an earlier reveal depends on.
 *   3. Does the DICTIONARY hold enough words of each shape the build needs?
 *      This is the ceiling that decides whether a big chain is possible at
 *      all, because the "passes every rule" pool shrinks multiplicatively
 *      with each rule added.
 *   4. Does the ARITHMETIC work -- enough survivors for the reveal's spacing,
 *      enough room for the pacing the keep_fractions ask for?
 *
 * A clean run doesn't guarantee a build: cell geometry is decided per attempt,
 * and the builder may need several tries. But every failure reported here is a
 * certain one, named in terms of the knob that fixes it.
 *
 * Exit 0 means no blockers; 1 means at least one.
 */

// A full-size book is 12,000 cells laid out against a 133,000-word list, which needs
// more than PHP's usual 128M default. Raised here so this works out of the box rather
// than needing a php.ini change -- left alone if the limit is already unlimited.
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '512M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainBuilder;
use Puzzlebook\ChainPlan;
use Puzzlebook\Engine;
use Puzzlebook\Grid;
use Puzzlebook\JsonConfig;
use Puzzlebook\Rules\ConstructsCells;
use Puzzlebook\Rules\KnightAdjacentSurvivingLengthRule;
use Puzzlebook\Rules\SpellingRule;
use Puzzlebook\WordIndexCache;

$root = dirname(__DIR__);
$layout = JsonConfig::load($root . '/config/layout.json');
$chain = JsonConfig::load($root . '/config/chain.json');
$chain = ChainPlan::numberSteps($chain);
$wordlistPath = $root . '/data/wordlist.txt';

/** @var string[] $problems */
$problems = [];

echo "Checking config/chain.json against config/layout.json and the word list.\n\n";

// ---------------------------------------------------------------- 1. shape

$grid = new Grid($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);
$size = $grid->size();
echo "Grid: {$grid->pages} pages x {$grid->rows} rows x {$grid->cols} cols = {$size} words.\n";

try {
    $plan = ChainPlan::fromConfig($chain);
} catch (Throwable $e) {
    echo "BLOCKED: the chain's shape isn't buildable.\n  " . $e->getMessage() . "\n";
    exit(1);
}

$spellingRules = $plan->spellingRules();
$spellingCount = count($spellingRules);
$revealWords = $plan->revealWords();
$revealN = $plan->revealInterval();
$answer = $chain['answer'];

echo 'Chain: ' . count($chain['steps']) . " steps -- {$spellingCount} spelling rules, "
    . count($plan->constructorsByPriority()) . ' rules that build their own cells, '
    . ($plan->hasReveal() ? '1 reveal, ' : 'no reveal, ') . "1 final rule.\n";

// What the chain actually DOES, in reading order, so a dozen-plus rules stay legible
// without cross-referencing config/chain.json. Each rule describes itself.
echo "\nThe chain, in the order a reader works through it:\n";
foreach ($chain['steps'] as $step) {
    $id = str_pad((string) $step['id'], 2);
    if (($step['kind'] ?? '') === 'reveal') {
        echo "  {$id} REVEAL  " . Engine::describeReveal($step['reveal']) . "\n";
        echo "            -> \"" . $step['expected'] . "\"\n";
        continue;
    }
    $rule = Engine::ruleFromConfig($step['rule']);
    $marker = !empty($step['final']) ? 'FINAL ' : (empty($step['printed']) ? 'HIDDEN' : '      ');
    echo "  {$id} {$marker}  crosses out " . $rule->describe() . "\n";
}

echo "\nConstruction order (which rule gets to choose its cells first):\n";
foreach ($plan->constructorsByPriority() as [$stepId, $rule, $priority]) {
    $kind = $rule instanceof SpellingRule ? 'spelling, takes any cell' : 'positional, needs specific cells';
    echo "  priority " . str_pad((string) $priority, 3) . " step " . str_pad((string) $stepId, 2)
        . " -- {$kind}\n";
}
echo "\n";

// -------------------------------------------- 2. reserved words survive the rules

echo "Reserved words (the answer plus every revealed word) vs. the spelling rules:\n";
foreach (array_merge([$answer], $revealWords) as $word) {
    $killedBy = [];
    foreach ($spellingRules as $index => $rule) {
        if ($rule->failsWord($word)) {
            $killedBy[] = '#' . ($index + 1) . ' (' . $rule->describe() . ')';
        }
    }
    if ($killedBy === []) {
        echo "  ok      {$word}\n";
    } else {
        $which = implode(', ', $killedBy);
        echo "  BLOCKED {$word}  <- crossed out by spelling rule {$which}\n";
        $problems[] = "'{$word}' must survive every spelling rule, but rule {$which} removes it. "
            . 'Reword it, or loosen that rule.';
    }
}

try {
    $plan->validateReservedWords($answer);
} catch (Throwable $e) {
    $problems[] = $e->getMessage();
}

// A reserved word doesn't have to be in the word list -- it's placed directly at its
// cell rather than looked up -- but it's worth knowing when one isn't, because it will
// then be the only word in the book that didn't come from the pool. Usually that's
// invisible; it's only awkward if the word also stands out in some other way (being
// longer than anything else, say).
//
// Checked with one streaming pass rather than by building an index: a hash table keyed
// on 133,000 strings costs several megabytes, and we only have a dozen words to look up.
$wanted = array_fill_keys(array_merge([$answer], $revealWords), false);
$scan = fopen($wordlistPath, 'r');
if ($scan !== false) {
    while (($line = fgets($scan)) !== false) {
        $word = trim($line);
        if (array_key_exists($word, $wanted)) {
            $wanted[$word] = true;
        }
    }
    fclose($scan);
}
$notInPool = array_keys(array_filter($wanted, static fn (bool $found): bool => !$found));
if ($notInPool !== []) {
    echo "\n  note: " . implode(', ', $notInPool) . ' not in the word list. '
        . (count($notInPool) === 1 ? 'It' : 'They') . " will still be placed, but won't\n"
        . "        appear anywhere else in the book. Fine unless they look conspicuous.\n";
}
echo "\n";

// ------------------------------------------------------------ 3. arithmetic

$slotsNeeded = $revealN * count($revealWords);
$targetSurvivors = $slotsNeeded + 8;

if ($plan->hasReveal()) {
    echo "The reveal reads every {$revealN}th survivor, " . count($revealWords) . " words, so at least "
        . "{$slotsNeeded} words must still be standing when it's read; the builder aims for {$targetSurvivors}.\n";

    if ($targetSurvivors > $size) {
        $problems[] = "The reveal needs {$targetSurvivors} surviving words but the book is only {$size} cells.";
    }
} else {
    echo "No reveal step in this chain -- nothing has to still be standing partway through.\n";
}

// The word list has to be loaded before the layout, not after: rules that pair words
// up need to know what the dictionary holds before they can say what they want.
$words = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($words === false) {
    echo "BLOCKED: could not read {$wordlistPath}\n";
    exit(1);
}
$excluded = $layout['filler_excluded_initial'] ?? '';
$eligible = [];
foreach ($words as $w) {
    if ($excluded === '' || $w[0] !== $excluded) {
        $eligible[] = $w;
    }
}

// Same hash ChainBuilder::build() computes, from the same file, so a dictionary-
// derived index saved by a real build is found here too (and vice versa) rather
// than being silently rebuilt because the two cache keys didn't quite match.
$wordPoolHash = md5_file($wordlistPath) ?: '';

// A rule that picks genuine word PAIRS during construction (PageSignaturePairRule,
// ContainedWordOnPageRule) reads this off ConstructionContext::survivorsSoFar() --
// same partition ChainBuilder::build() computes, built the same way here, so a
// construction-time failure shows up HERE too, not only during a real build.
$prefixPools = ChainBuilder::prefixPools($words, $spellingRules);

// Lay the book out for real -- no words chosen, just which rule claims which cell and
// what shape each cell's word must be. Everything below is read off this, so the
// numbers are what the build will really do rather than what the fractions imply.
//
// Passed as $words (UNFILTERED), not $eligible: ChainBuilder::build() hands every
// rule the raw word list, not one filtered by filler_excluded_initial, so matching
// that here is what keeps this check's numbers honest about what a real build does
// -- and what keeps this call's cache key meaning the same underlying data as
// build()'s, so a cached index is never served back against the wrong word list.
$canvas = null;
$layoutError = null;
for ($try = 0; $try < 25 && $canvas === null; $try++) {
    try {
        $canvas = ChainBuilder::layOutCells($try, $grid, $plan, $chain, $words, $wordPoolHash, $prefixPools);
    } catch (Throwable $e) {
        $layoutError = $e->getMessage();
    }
}

if ($canvas === null) {
    echo "\nBLOCKED: the cells couldn't be laid out in 25 attempts, before any words were chosen.\n";
    echo "         last reason: {$layoutError}\n";
    exit(1);
}

echo "\nPacing -- cells each rule actually claims in a real layout:\n";
$claims = $canvas->claimsByStep();
$runningLeft = $size;
foreach ($claims as $stepId => $claimed) {
    $runningLeft -= $claimed;
    $pct = $size > 0 ? round($claimed / $size * 100, 1) : 0.0;
    $sized = $plan->hasBudget($stepId) ? '' : '  [sizes itself]';
    echo '  step ' . str_pad((string) $stepId, 2) . ': removes ' . str_pad((string) $claimed, 5)
        . " ({$pct}% of the book), leaving {$runningLeft}{$sized}\n";
}
echo "  still standing for the reveal: " . $canvas->survivorCount() . "\n\n";

// ------------------------------------------------- 4. dictionary supply

echo 'Word list: ' . count($eligible) . " words available";
echo $excluded !== '' ? " (filler skips those starting with '{$excluded}').\n\n" : ".\n\n";

$survivesThrough = static function (string $word, int $through) use ($spellingRules): bool {
    for ($j = 0; $j < $through; $j++) {
        if ($spellingRules[$j]->failsWord($word)) {
            return false;
        }
    }
    return true;
};

// The pool that passes EVERY rule is the one that shrinks multiplicatively as rules
// are added, and it's the hard ceiling on how big a chain can get. Show it shrinking.
//
// Everything below is built from each rule's own "fail set" -- the words IT removes,
// on its own, regardless of chain order or any other rule -- rather than from one
// joint scan. Cached per rule on disk (WordIndexCache), keyed on the word list, the
// rule's own parameters (describe()), and a hash of the PHP that defines the rule's
// matching logic: only a rule that actually changed has to be rescanned, and the rest
// come back from disk well under a second even at 133,000 words.
echo "The \"passes every rule so far\" pool, rule by rule -- this is the ceiling on chain length:\n";

$rulesFailWords = [];
foreach ($spellingRules as $i => $rule) {
    $rulesFailWords[$i] = WordIndexCache::remember(
        get_class($rule),
        $rule->describe(),
        $wordPoolHash,
        static function () use ($eligible, $rule): array {
            $fails = [];
            foreach ($eligible as $w) {
                if ($rule->failsWord($w)) {
                    $fails[] = $w;
                }
            }
            return $fails;
        }
    );
}

$failsRule = array_map('count', $rulesFailWords);

// Pairwise overlap, from the sets above rather than a fresh scan: flip each list to a
// hash set once, then walk the SMALLER side's words against the bigger side's set. An
// N-rule chain costs N flips plus (N choose 2) small sweeps -- it never touches the
// word list itself.
$flipped = [];
foreach ($rulesFailWords as $i => $list) {
    $flipped[$i] = array_flip($list);
}
$overlap = [];
for ($i = 0; $i < $spellingCount; $i++) {
    for ($j = $i + 1; $j < $spellingCount; $j++) {
        if (count($rulesFailWords[$i]) <= count($rulesFailWords[$j])) {
            [$small, $big] = [$rulesFailWords[$i], $flipped[$j]];
        } else {
            [$small, $big] = [$rulesFailWords[$j], $flipped[$i]];
        }
        $shared = 0;
        foreach ($small as $w) {
            if (isset($big[$w])) {
                $shared++;
            }
        }
        if ($shared > 0) {
            $overlap["{$i},{$j}"] = $shared;
        }
    }
}

// The cascade -- how many words die AT rule j specifically -- IS order-dependent (a
// word dies at the first rule in CHAIN ORDER that fails it, not just any rule that
// does), but it's still derivable from the same sets without a fresh scan: walk the
// rules in order, and a word only counts here if this rule fails it and no earlier
// rule already did.
$diesAt = array_fill(0, $spellingCount, 0);
$dead = [];
for ($j = 0; $j < $spellingCount; $j++) {
    foreach ($rulesFailWords[$j] as $w) {
        if (!isset($dead[$w])) {
            $diesAt[$j]++;
            $dead[$w] = true;
        }
    }
}

$survivesEverything = count($eligible) - count($dead);
$survivesEverythingByLength = [];
foreach ($eligible as $w) {
    if (!isset($dead[$w])) {
        $len = strlen($w);
        $survivesEverythingByLength[$len] = ($survivesEverythingByLength[$len] ?? 0) + 1;
    }
}

$stillStanding = count($eligible);
echo '  before any rule: ' . $stillStanding . " words\n";
for ($j = 0; $j < $spellingCount; $j++) {
    $stillStanding -= $diesAt[$j];
    echo '  after rule #' . ($j + 1) . ': ' . $stillStanding
        . " words survive (it can remove at most {$diesAt[$j]}) -- " . $spellingRules[$j]->describe() . "\n";
}
echo "  -> {$survivesEverything} words pass all {$spellingCount} rules. Everything still standing at the\n";
echo "     reveal has to come from that pool, and it shrinks every time a rule is added.\n\n";

// Broken down by length, because a rule can quietly wipe out ONE length without
// touching the total: "exactly 2/5 vowels" barely dents the OVERALL pool (it only
// ever matches 5- and 10-letter words to begin with) while still destroying the
// supply of five-letter words specifically -- and the knight's-move finale needs
// exactly one length, so a thin slice here is a real problem the total hides.
echo "  that pool, by word length (a rule needing one specific length only has this\n";
echo "  much to draw from, however healthy the total above looks):\n";
ksort($survivesEverythingByLength);
foreach ($survivesEverythingByLength as $len => $count) {
    echo "    {$len} letters: {$count}\n";
}
echo "\n";

// Two rules can each look fine in isolation -- healthy catch counts above -- and still
// be fighting over the SAME words without either author noticing, because nothing
// upstream of this ever asked "when rule A removes a word, does rule B usually want
// that word too?" When it does, rule B's real-world removal is mostly wasted on words
// A would have taken anyway, so whatever ELSE B was meant to be pruning barely gets
// touched -- the exact shape of the earlier vowel_fraction/knight's-move incident,
// just caught here in general instead of per-incident.
//
// Reported as "share of the SMALLER rule's catch" rather than a raw count or a count
// against the total pool: a rule that only ever matches 500 words sharing 400 of them
// with a rule that matches 50,000 is a real collision for the small rule even though
// 400 is nothing against 50,000. The threshold below (20%) is a starting point for
// spotting candidates to look at, not a hard pass/fail -- overlap by itself isn't
// necessarily wrong, it's only a problem combined with something downstream (like a
// length-specific finale) actually running short, which the checks elsewhere catch
// directly. Informational, so nothing here adds to $problems.
echo "Rule-pair overlap -- how much of each rule's catch is also caught by another rule\n";
echo "(ranked by share of the smaller rule's catch; >=20% shown, top 15):\n";

$overlapPairs = [];
foreach ($overlap as $key => $count) {
    [$i, $j] = array_map('intval', explode(',', $key));
    $smaller = min($failsRule[$i], $failsRule[$j]);
    if ($smaller === 0) {
        continue;
    }
    $overlapPairs[] = [$i, $j, $count, $count / $smaller];
}
usort($overlapPairs, static fn (array $a, array $b): int => $b[3] <=> $a[3]);

$shown = 0;
foreach ($overlapPairs as [$i, $j, $count, $fraction]) {
    if ($fraction < 0.2 || $shown >= 15) {
        continue;
    }
    $pct = round($fraction * 100, 1);
    echo '  rule #' . ($i + 1) . ' & #' . ($j + 1) . ": share {$count} words ({$pct}% of the smaller rule's catch)\n";
    echo '      #' . ($i + 1) . ': ' . $spellingRules[$i]->describe() . "\n";
    echo '      #' . ($j + 1) . ': ' . $spellingRules[$j]->describe() . "\n";
    $shown++;
}
if ($shown === 0) {
    echo "  none at or above 20% -- no rule pair is meaningfully competing for the same words.\n";
}
echo "\n";

// The ranked report above is a judgment call (the 20% cutoff, the top-15 cap) on top of
// real data -- and the data itself is cheap enough to just write out whole, so it isn't
// locked behind that judgment call. One plain word-per-line file per rule (actually
// read the words two rules are fighting over, `diff`/`comm` any two of them directly)
// plus one CSV with every pair's cardinality (every pair, not just the ones shown above
// -- open it in a spreadsheet and sort however's useful). $rulesFailWords itself is
// already cached per rule above; this just re-renders that same data as human-readable
// files every run, which is cheap regardless of whether the data behind it was a cache
// hit or a fresh scan.
$overlapDir = $root . '/data/cache/overlap';
if (!is_dir($overlapDir)) {
    mkdir($overlapDir, 0775, true);
}

$slugs = [];
foreach ($spellingRules as $i => $rule) {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $rule->describe()));
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 40);
    $slugs[$i] = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '-' . $slug;
    sort($rulesFailWords[$i]);
    file_put_contents("{$overlapDir}/rule-{$slugs[$i]}.txt", implode("\n", $rulesFailWords[$i]) . "\n");
}

$csv = ["rule_a,rule_b,rule_a_desc,rule_b_desc,rule_a_count,rule_b_count,shared,pct_of_smaller\n"];
foreach ($overlapPairs as [$i, $j, $count, $fraction]) {
    $row = [
        $i + 1,
        $j + 1,
        '"' . str_replace('"', '""', $spellingRules[$i]->describe()) . '"',
        '"' . str_replace('"', '""', $spellingRules[$j]->describe()) . '"',
        $failsRule[$i],
        $failsRule[$j],
        $count,
        round($fraction * 100, 1),
    ];
    $csv[] = implode(',', $row) . "\n";
}
file_put_contents("{$overlapDir}/pairs.csv", implode('', $csv));

echo "  wrote " . count($spellingRules) . " word lists and the full pairwise matrix to\n";
echo "  {$overlapDir}/ -- rule-NN-*.txt (one word per line, the rule's whole catch) and\n";
echo "  pairs.csv (every pair's cardinality, not just the ones shown above).\n\n";

// A direct check on the finale's own requirement, rather than trusting that a
// healthy-looking total implies a healthy slice. The finale needs launch-pad words
// of ONE exact length, every one of which also has to survive every spelling rule --
// so this is the number that actually decides whether step construct() can work, no
// matter how big $survivesEverything looks.
foreach ($chain['steps'] as $step) {
    $ruleClass = 'Puzzlebook\\Rules\\' . ($step['rule']['type'] ?? '');
    if (is_a($ruleClass, KnightAdjacentSurvivingLengthRule::class, true)) {
        $keyLength = Engine::ruleFromConfig($step['rule'])->length();
        $keyPoolSize = $survivesEverythingByLength[$keyLength] ?? 0;

        // Each launch pad can cover up to ~8 knight-move neighbours, but cells near a
        // page edge cover fewer, and some supply gets spent on launch pads covering
        // each other rather than ordinary survivors. A conservative 1-in-3 ratio
        // keeps this warning honest: it fires well before a real build would
        // actually run dry, not after.
        $roughKeysNeeded = (int) ceil($targetSurvivors / 3);

        $verdict = $keyPoolSize >= $roughKeysNeeded ? 'ok     ' : 'BLOCKED';
        echo "  {$verdict} knight's-move finale: needs roughly {$roughKeysNeeded}+ launch pads of exactly "
            . "{$keyLength} letters, pool has {$keyPoolSize}\n\n";

        if ($keyPoolSize < $roughKeysNeeded) {
            $problems[] = "Only {$keyPoolSize} words of exactly {$keyLength} letters survive every spelling "
                . "rule, but the finale needs roughly {$roughKeysNeeded}+ to work with. Some earlier rule is "
                . "disproportionately removing {$keyLength}-letter words -- check the per-length breakdown "
                . 'above against each rule above it, and loosen or reshape whichever one is responsible.';
        }
        break;
    }
}

// Lay the book out for real -- no words chosen, just which rule claims which cell and
// what shape each cell's word must be -- then read the demands straight off it. Doing
// it this way means EVERY rule gets checked, positional ones included: a rule can't be
// left out, because the demand being tested is the one the rule itself declared.
echo "Word supply -- every demand the rules actually make, read from a real layout:\n";

$stepNames = [];
foreach ($chain['steps'] as $step) {
    if (($step['kind'] ?? '') === 'eliminate') {
        $stepNames[$step['id']] = Engine::ruleFromConfig($step['rule'])->describe();
    }
}

foreach ($canvas->demandsByLabel() as $label => $demand) {
    $cellPlan = $demand['plan'];
    $needed = $demand['cells'];
    $prefix = $cellPlan->survivesPrefix();
    $steps = $demand['steps'] === [] ? '-' : implode(',', $demand['steps']);

    // Cells holding a predetermined word -- the answer, the revealed phrase -- have no
    // supply requirement at all: the word is assigned straight to its cell and never
    // looked up. They also can't be counted as a group, because each one wants a
    // DIFFERENT specific word while the group carries a single representative plan;
    // testing that plan against the list asks "how many words equal 'eliminate'?" and
    // compares the answer, 1, against all 13 cells. Whether these words are in the list
    // is reported separately, further up.
    if ($cellPlan->literalWord() !== null) {
        echo '  ok      ' . str_pad($label, 14) . 'needs ' . str_pad((string) $needed, 5)
            . "set in advance, placed directly (no supply needed)\n";
        continue;
    }

    // A plan whose shape depends on a word placed elsewhere -- the second half of
    // any deliberate pair -- can't be counted in isolation, because there's no
    // partner yet. Say so rather than reporting a misleading zero.
    if ($cellPlan->dependsOnOtherCells()) {
        echo '  ok      ' . str_pad($label, 14) . 'needs ' . str_pad((string) $needed, 5)
            . "depends on its partner word (checked during the build)\n";
        continue;
    }

    $available = 0;
    foreach ($eligible as $w) {
        if ($survivesThrough($w, $prefix) && $cellPlan->shapeAllows($w, [])) {
            $available++;
        }
    }

    $verdict = $available >= $needed ? 'ok     ' : 'BLOCKED';
    echo "  {$verdict} " . str_pad($label, 14) . 'needs ' . str_pad((string) $needed, 5)
        . 'has ' . str_pad((string) $available, 7)
        . "(step {$steps}: " . ($stepNames[$demand['steps'][0] ?? 0] ?? 'reserved words') . ")\n";

    if ($available < $needed) {
        $stepId = $demand['steps'][0] ?? null;
        $fractionNow = null;
        foreach ($chain['steps'] as $step) {
            if (($step['id'] ?? null) === $stepId) {
                $fractionNow = $step['construction']['keep_fraction'] ?? null;
            }
        }
        $advice = $fractionNow === null
            ? 'This rule sizes itself from its own geometry, so reduce what it asks for by narrowing '
                . 'its parameters, or give it more pages to work with.'
            : "Raise step {$stepId}'s keep_fraction above {$fractionNow} so it asks for fewer.";
        $problems[] = "The '{$label}' cells need {$needed} words of a shape the list only has "
            . "{$available} of. {$advice}";
    }
}

echo "\n";

// -------------------------------------------------------------- verdict

if ($problems === []) {
    echo "No blockers found. Shape, reserved words, arithmetic and word supply all check out --\n";
    echo "run bin/build.php to place the words and verify the real book.\n";
    exit(0);
}

echo 'Found ' . count($problems) . " blocker(s):\n\n";
foreach ($problems as $n => $p) {
    echo '  ' . ($n + 1) . ". {$p}\n\n";
}
exit(1);
