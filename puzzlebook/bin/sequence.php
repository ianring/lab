#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Arranges a book from config/clues.json's candidate library: picks an order
 * for the 'spelling' family (greedy, scarcest-supply-first) and calibrates
 * each one's keep_fraction, then splices in the 'positional' and 'finale'
 * sections unchanged. WRITES THE RESULT STRAIGHT TO config/chain.json -- no
 * staging file, no manual copy step -- after first saving whatever chain.json
 * held before to build/chain.previous.json, so there's always one generation
 * of undo without needing to think about it. Prints a step-by-step report as
 * it goes; run bin/check.php against the result before trusting it, same as
 * any hand-written chain.
 *
 * THE ALGORITHM. A 'spelling' clue's cardinality against any word pool is a
 * fixed, computable number -- unlike a positional clue, which needs real
 * geometry to know how much it removes. That's what makes this family
 * orderable at all: simulate the reader's cascade (a word dies at the FIRST
 * rule, in final order, that catches it -- the same model bin/check.php's
 * own cascade uses) one clue at a time, and at each step, from whatever
 * clues aren't placed yet, choose the one with the SMALLEST current
 * cardinality. Scarcest resource first: a clue with few matches has no slack
 * left to lose if it waits, while a clue with many matches can afford to run
 * later and still have plenty. Every clue placed this way removes its WHOLE
 * matching set from the simulated pool before the next one is chosen,
 * exactly mirroring what a real build does -- a word any earlier rule would
 * catch is gone for every later one too, not just however many cells the
 * earlier rule actually used.
 *
 * TARGET. bookSize / (spelling clues + positional clues) -- treating every
 * clue, in both families, as roughly one even slice of the book. A rough
 * anchor for calibrating keep_fraction, not a promise: the positional
 * section's own hand-tuned fractions are left exactly as they are, since
 * this script doesn't model geometry at all.
 */

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '512M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\Engine;
use Puzzlebook\JsonConfig;
use Puzzlebook\Rules\SpellingRule;

$root = dirname(__DIR__);
$layout = JsonConfig::load($root . '/config/layout.json');
$clues = JsonConfig::load($root . '/config/clues.json');
$wordlistPath = $root . '/data/wordlist.txt';

$words = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($words === false) {
    fwrite(STDERR, "Could not read {$wordlistPath}\n");
    exit(1);
}
$excluded = $layout['filler_excluded_initial'] ?? '';
$eligible = [];
foreach ($words as $w) {
    if ($excluded === '' || $w[0] !== $excluded) {
        $eligible[] = $w;
    }
}

$bookSize = $layout['grid']['pages'] * $layout['grid']['rows'] * $layout['grid']['cols'];
$positionalCount = count($clues['positional']);

echo 'Arranging ' . count($clues['spelling']) . ' spelling clues + ' . count($clues['positional'])
    . " positional clues against a {$bookSize}-cell book.\n";
echo "Target per step is RECOMPUTED after every placement -- remaining cells / remaining\n";
echo "steps (spelling AND positional both count) -- so when a naturally scarce clue can't\n";
echo "supply its even share, the shortfall is redistributed across whatever's still ahead\n";
echo "of it instead of becoming slack the positional section alone inherits.\n\n";

// One rule object per candidate, built once.
$candidates = [];
foreach ($clues['spelling'] as $i => $clue) {
    $rule = Engine::ruleFromConfig($clue['rule']);
    if (!$rule instanceof SpellingRule) {
        fwrite(
            STDERR,
            "clues.json's 'spelling' entry #{$i} ('{$clue['rule']['type']}') isn't a pure spelling rule "
                . "(doesn't extend SpellingRule) -- move it to 'positional' instead.\n"
        );
        exit(1);
    }
    $candidates[$i] = ['clue' => $clue, 'rule' => $rule, 'describe' => $rule->describe()];
}

// Each candidate's FULL match set against the untouched pool, computed once. This
// is both the input to the overlap report below AND the main loop's working set --
// filtering an already-small match list against a shrinking pool is far cheaper
// than re-running failsWord() over the whole ~133,000-word pool every iteration.
$fullMatches = [];
foreach ($candidates as $i => $c) {
    $matches = [];
    foreach ($eligible as $w) {
        if ($c['rule']->failsWord($w)) {
            $matches[] = $w;
        }
    }
    $fullMatches[$i] = $matches;
}

// Pairwise overlap, from those same sets -- same trick bin/check.php's own overlap
// report uses: flip the smaller side, walk it against the bigger side's hash set.
// This is BEFORE any ordering decision: two clues sharing most of their candidate
// words means whichever one is placed SECOND is mostly spending its reach on words
// the first one already would have caught -- worth seeing up front, since it
// explains a lot of what the step-by-step erosion numbers below turn out to show.
$ids = array_keys($candidates);
$overlapPairs = [];
for ($a = 0, $n = count($ids); $a < $n; $a++) {
    for ($b = $a + 1; $b < $n; $b++) {
        [$i, $j] = [$ids[$a], $ids[$b]];
        $iSmaller = count($fullMatches[$i]) <= count($fullMatches[$j]);
        $small = $iSmaller ? $fullMatches[$i] : $fullMatches[$j];
        $bigFlipped = array_flip($iSmaller ? $fullMatches[$j] : $fullMatches[$i]);
        $shared = 0;
        foreach ($small as $w) {
            if (isset($bigFlipped[$w])) {
                $shared++;
            }
        }
        $smaller = min(count($fullMatches[$i]), count($fullMatches[$j]));
        if ($smaller > 0 && $shared > 0) {
            $overlapPairs[] = [$i, $j, $shared, $shared / $smaller];
        }
    }
}
usort($overlapPairs, static fn (array $x, array $y): int => $y[3] <=> $x[3]);

echo "Rule-pair overlap, against the full dictionary, before any ordering decision\n";
echo "(ranked by share of the smaller clue's own total; >=20% shown, top 10):\n";
$shown = 0;
foreach ($overlapPairs as [$i, $j, $shared, $fraction]) {
    if ($fraction < 0.2 || $shown >= 10) {
        continue;
    }
    $pct = round($fraction * 100, 1);
    echo "  {$candidates[$i]['describe']}\n    & {$candidates[$j]['describe']}\n"
        . "    share {$shared} words ({$pct}% of the smaller clue's own total)\n";
    $shown++;
}
if ($shown === 0) {
    echo "  none at or above 20% -- no two clues are meaningfully redundant with each other.\n";
}
echo "\n";

$remainingSet = array_fill_keys($eligible, true);
$notPlaced = $ids;
$order = [];
$stillStandingCells = $bookSize;
$problems = [];

echo "Step-by-step (scarcest current cardinality chosen first):\n\n";

while ($notPlaced !== []) {
    $best = null;
    $bestMatches = null;
    foreach ($notPlaced as $i) {
        $matches = [];
        foreach ($fullMatches[$i] as $w) {
            if (isset($remainingSet[$w])) {
                $matches[] = $w;
            }
        }
        if ($bestMatches === null || count($matches) < count($bestMatches)) {
            $best = $i;
            $bestMatches = $matches;
        }
    }

    // Recomputed every iteration, not fixed up front: whatever's left to remove,
    // divided by however many steps (spelling AND positional) still have to claim
    // a share of it. A step that under-delivers doesn't just leave idle slack for
    // whatever comes last -- every step still ahead picks up a slightly bigger
    // target, spelling and positional alike.
    $stepsRemaining = count($notPlaced) + $positionalCount;
    $target = $stepsRemaining > 0 ? (int) round($stillStandingCells / $stepsRemaining) : 0;

    $cardinality = count($bestMatches);
    $erosion = count($fullMatches[$best]) - $cardinality;
    $cellsAssigned = min($cardinality, $target, $stillStandingCells);
    $keepFraction = $stillStandingCells > 0 ? round(1 - $cellsAssigned / $stillStandingCells, 4) : 1.0;
    $feasible = $cardinality >= $target;

    $stepNum = count($order) + 1;
    $verdict = $feasible ? 'ok  ' : 'THIN';
    echo "  {$verdict} step {$stepNum}: {$candidates[$best]['describe']}\n";
    echo "       originally " . count($fullMatches[$best]) . ", now {$cardinality} here "
        . "({$erosion} already claimed by earlier clues) -- target this step: ~{$target}\n";
    echo "       assigned {$cellsAssigned} cells, keep_fraction {$keepFraction}\n";
    if (!$feasible) {
        $msg = "step {$stepNum} ({$candidates[$best]['describe']}) only has {$cardinality} matching "
            . "words left, short of the ~{$target} target ({$erosion} of its original "
            . count($fullMatches[$best]) . ' already claimed by earlier clues) -- it\'s already the '
            . 'SCARCEST option available at this point, so moving it earlier (editing clues.json or '
            . 're-running after dropping a competing clue -- check the overlap report above for which '
            . 'one) is the only lever, not reordering what\'s already placed.';
        echo "       THIN SUPPLY: {$msg}\n";
        $problems[] = $msg;
    }

    $order[] = [
        'index' => $best,
        'cardinality' => $cardinality,
        'cellsAssigned' => $cellsAssigned,
        'keepFraction' => $keepFraction,
    ];

    foreach ($bestMatches as $w) {
        unset($remainingSet[$w]);
    }
    $stillStandingCells -= $cellsAssigned;
    $notPlaced = array_values(array_filter($notPlaced, static fn (int $i): bool => $i !== $best));
}

echo "\nAfter all spelling clues: {$stillStandingCells} cells and " . count($remainingSet)
    . " surviving words left for the positional section + finale (hand-tuned, unchanged).\n\n";

// ---------------------------------------------------------------- write the chain

$steps = [];
foreach ($order as $placement) {
    $clue = $candidates[$placement['index']]['clue'];
    $steps[] = [
        'kind' => 'eliminate',
        'printed' => true,
        'rule' => $clue['rule'],
        'rule_text' => $clue['rule_text'],
        'construction' => ['keep_fraction' => $placement['keepFraction']],
    ];
}
foreach ($clues['positional'] as $clue) {
    $steps[] = array_merge(['kind' => 'eliminate', 'printed' => true], $clue);
}
foreach ($clues['finale'] as $clue) {
    $steps[] = array_merge(['kind' => 'eliminate', 'printed' => true], $clue);
}

$chain = ['steps' => $steps, 'answer' => $clues['answer']];

// One generation of undo, automatically -- whatever chain.json held before this run
// gets saved alongside it, so writing straight to the live config never means losing
// the ability to see (or restore) what was there a moment ago.
$chainPath = $root . '/config/chain.json';
if (is_file($chainPath)) {
    $buildDir = $root . '/build';
    if (!is_dir($buildDir)) {
        mkdir($buildDir, 0777, true);
    }
    copy($chainPath, $buildDir . '/chain.previous.json');
    echo "Saved the previous config/chain.json to {$buildDir}/chain.previous.json first.\n";
}

JsonConfig::write($chainPath, $chain);

echo "Wrote {$chainPath} directly -- it's now the live, active chain.\n";
echo "Run bin/check.php before trusting it.\n\n";

if ($problems !== []) {
    echo 'Found ' . count($problems) . " thin-supply warning(s) above -- not fatal, but worth a look:\n\n";
    foreach ($problems as $n => $p) {
        echo '  ' . ($n + 1) . ". {$p}\n\n";
    }
}
