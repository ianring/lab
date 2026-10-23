#!/usr/bin/env php
<?php

declare(strict_types=1);

// Phase-2 proof: pin a few chosen words at chosen cells, build the book, and
// confirm each plant (a) lands exactly where asked and (b) is crossed out by
// the chain while the real answer still wins. This is the primitive the
// Mystery chain and the acrostics are built on (see SERIES_IMPLEMENTATION.md).

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '1024M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Feasibility\Advisor;
use Puzzlebook2\Output\ChainRunner;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\Solver;
use Puzzlebook2\Solver\SolveOutcome;

$root = dirname(__DIR__);
$seed = 1;
$pagesOverride = null;   // --pages=N shrinks the board for fast diagnosis
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--pages=(\d+)$/', $arg, $m)) {
        $pagesOverride = (int) $m[1];
    }
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$chainConfig = json_decode((string) file_get_contents($root . '/config/chain.json'), true);
$pages = $pagesOverride ?? $layout['grid']['pages'];
$spec = new BoardSpec($pages, $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');

// A few interior cells (row 12, col 2 -- not an edge the answer uses, not a
// corner the corner rule wants), each pinned to a real, eliminable word.
// Pages kept low so the demo works at any --pages size.
$plants = [
    ['cell' => $spec->linearIndex(1, 12, 2), 'word' => 'level',  'id' => 'demo-a'],
    ['cell' => $spec->linearIndex(4, 12, 2), 'word' => 'radar',  'id' => 'demo-b'],
    ['cell' => $spec->linearIndex(7, 12, 2), 'word' => 'civic', 'id' => 'demo-c'],
];

echo "Planting:\n";
foreach ($plants as $p) {
    [$pg, $r, $c] = $spec->coordsOf($p['cell']);
    printf("  '%s' at cell %d  (page %d, row %d, col %d)\n", $p['word'], $p['cell'], $pg + 1, $r + 1, $c + 1);
}
echo "\n";

$board = new Board($spec);
$plan = ChainPlan::load($chainConfig, $board, $dictionary, $seed, $plants);
if ($plan->problems() !== []) {
    fwrite(STDERR, "Plan has problems:\n");
    foreach ($plan->problems() as $prob) {
        fwrite(STDERR, "  - {$prob}\n");
    }
    exit(2);
}

$plan->registerGlobalConstraints();
$queue = new ReservationQueue();
$plan->fillQueue($queue);

$t0 = microtime(true);
$lastBeat = $t0;
$solver = new Solver(static function (int $a, int $tot, int $steps, int $bt) use (&$lastBeat, $t0): void {
    $now = microtime(true);
    if ($now - $lastBeat < 3.0) {
        return;
    }
    fwrite(STDERR, sprintf("  ...%d/%d cells (%d%%), %d steps, %d backtracks, %.0fs\n",
        $a, $tot, (int) round($a / $tot * 100), $steps, $bt, $now - $t0));
    $lastBeat = $now;
});
$result = $solver->solve($board, $queue);
if ($result->outcome !== SolveOutcome::Success) {
    fwrite(STDERR, "Solve failed: {$result->outcome->name} -- {$result->reason}\n");
    foreach (Advisor::advise($plan, $dictionary, $result) as $rec) {
        fwrite(STDERR, '  ' . $rec->render() . "\n");
    }
    exit(1);
}

$verification = ChainRunner::run($result->board, $plan);
$words = $result->board->allWords();

echo "Placement check (word sits exactly where pinned):\n";
$placedOk = true;
foreach ($plants as $p) {
    $got = strtoupper($words[$p['cell']]);
    $want = strtoupper($p['word']);
    $ok = $got === $want;
    $placedOk = $placedOk && $ok;
    printf("  cell %d: expected %-8s got %-8s  %s\n", $p['cell'], $want, $got, $ok ? 'OK' : 'MISMATCH');
}

$survivors = array_map('strtoupper', $verification['survivors']);
echo "\nElimination check (plants are dead, not survivors):\n";
$deadOk = true;
foreach ($plants as $p) {
    $isSurvivor = in_array(strtoupper($p['word']), $survivors, true);
    $deadOk = $deadOk && !$isSurvivor;
    printf("  '%s' survived? %s  %s\n", strtoupper($p['word']), $isSurvivor ? 'YES' : 'no', $isSurvivor ? 'BAD' : 'OK');
}

printf("\nFinal survivors: [%s]  (expected the answer '%s' alone)\n",
    implode(', ', $survivors), strtoupper($plan->answer()));

$answerOk = count($survivors) === 1 && $survivors[0] === strtoupper($plan->answer());
$allOk = $placedOk && $deadOk && $answerOk && $verification['ok'];
echo "\n" . ($allOk
    ? "PASS -- plants placed exactly, all crossed out, answer alone survives.\n"
    : "FAIL -- see mismatches above.\n");
exit($allOk ? 0 : 1);
