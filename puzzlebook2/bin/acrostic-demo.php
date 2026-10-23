#!/usr/bin/env php
<?php

declare(strict_types=1);

// Phase-4 proof of the acrostic primitive (Ian's optimization): an acrostic
// cell fixes only its FIRST LETTER; the rest of the word is any eliminable one
// the chain would allow. We spell a word down a column and confirm (a) each
// cell's word starts with the right letter, (b) the initials read the target,
// and (c) every acrostic word is crossed out while the answer still wins.

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '1024M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Output\ChainRunner;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\Solver;
use Puzzlebook2\Solver\SolveOutcome;

$root = dirname(__DIR__);
$seed = 1;
$pages = 20;
$target = 'HELLO';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--pages=(\d+)$/', $arg, $m)) {
        $pages = (int) $m[1];
    } elseif (preg_match('/^--word=([A-Za-z]+)$/', $arg, $m)) {
        $target = strtoupper($m[1]);
    }
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$chainConfig = json_decode((string) file_get_contents($root . '/config/chain.json'), true);
$spec = new BoardSpec($pages, $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');

// Spell $target down column 2 (middle, so no corners), page 5, interior rows.
$col = 2;
$page = 5;
$plants = [];
$cells = [];
for ($i = 0; $i < strlen($target); $i++) {
    $cell = $spec->linearIndex($page, 2 + $i, $col);
    $cells[] = $cell;
    $plants[] = ['cell' => $cell, 'letter' => $target[$i], 'id' => "acro-{$i}", 'role' => 'acrostic'];
}

echo "Acrostic target: {$target}  (column " . ($col + 1) . ", page " . ($page + 1) . ")\n\n";

$board = new Board($spec);
$plan = ChainPlan::load($chainConfig, $board, $dictionary, $seed, $plants);
if ($plan->problems() !== []) {
    fwrite(STDERR, "Plan has problems:\n");
    foreach ($plan->problems() as $p) {
        fwrite(STDERR, "  - {$p}\n");
    }
    exit(2);
}

$plan->registerGlobalConstraints();
$queue = new ReservationQueue();
$plan->fillQueue($queue);
$result = (new Solver())->solve($board, $queue);
if ($result->outcome !== SolveOutcome::Success) {
    fwrite(STDERR, "Solve failed: {$result->outcome->name} -- {$result->reason}\n");
    exit(1);
}
$verification = ChainRunner::run($result->board, $plan);
$words = $result->board->allWords();
$survivors = array_map('strtolower', $verification['survivors']);

$spelled = '';
$ok = true;
echo "Cell words (first letter fixed, rest free):\n";
foreach ($cells as $i => $cell) {
    $w = strtoupper($words[$cell]);
    $initial = $w[0];
    $spelled .= $initial;
    $startsRight = $initial === $target[$i];
    $dead = !in_array(strtolower($w), $survivors, true);
    $ok = $ok && $startsRight && $dead;
    printf("  row %2d: %-8s  starts '%s' (want '%s') %s  dead %s\n",
        2 + $i, $w, $initial, $target[$i],
        $startsRight ? 'OK' : 'NO', $dead ? 'OK' : 'NO');
}

printf("\nInitials spell: %s   (target %s)\n", $spelled, $target);
printf("Final survivors: [%s]  (expect answer '%s' alone)\n",
    implode(', ', array_map('strtoupper', $survivors)), strtoupper($plan->answer()));

$answerOk = count($survivors) === 1 && $survivors[0] === strtolower($plan->answer());
$allOk = $ok && $spelled === $target && $answerOk && $verification['ok'];
echo "\n" . ($allOk
    ? "PASS -- first letters spell the target, words are free & eliminable, answer alone survives.\n"
    : "FAIL -- see above.\n");
exit($allOk ? 0 : 1);
