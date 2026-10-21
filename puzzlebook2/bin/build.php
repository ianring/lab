#!/usr/bin/env php
<?php

declare(strict_types=1);

// A full-size book lays thousands of cells against a 133,000-word list;
// raised here so this works out of the box rather than needing php.ini.
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '1024M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Feasibility\Advisor;
use Puzzlebook2\Output\BookWriter;
use Puzzlebook2\Output\ChainRunner;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\Solver;
use Puzzlebook2\Solver\SolveOutcome;

$root = dirname(__DIR__);

$seed = 1;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--seed=(\d+)$/', $arg, $m)) {
        $seed = (int) $m[1];
    }
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$chainConfig = json_decode((string) file_get_contents($root . '/config/chain.json'), true);
if (!is_array($layout) || !is_array($chainConfig)) {
    fwrite(STDERR, "config/layout.json or config/chain.json failed to parse.\n");
    exit(2);
}

$spec = new BoardSpec($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');
echo "Board: {$spec->pages} pages x {$spec->rows} x {$spec->cols} = {$spec->size()} cells.  ";
echo "Dictionary: {$dictionary->count()} words.  Seed: {$seed}.\n";

$startedAt = microtime(true);
$board = new Board($spec);
$plan = ChainPlan::load($chainConfig, $board, $dictionary, $seed);

if ($plan->problems() !== []) {
    fwrite(STDERR, "The chain cannot build as configured. Recommendations:\n");
    foreach (Advisor::advise($plan, $dictionary) as $rec) {
        fwrite(STDERR, '  ' . $rec->render() . "\n");
    }
    exit(2);
}
printf("Plan resolved in %.1fs: every budget balanced, every supply covers its demand.\n", microtime(true) - $startedAt);

$plan->registerGlobalConstraints();
$queue = new ReservationQueue();
$plan->fillQueue($queue);
echo 'Slots to resolve: ' . $queue->count() . "\n";

$lastHeartbeat = microtime(true);
$solver = new Solver(static function (int $assigned, int $total, int $steps, int $backtracks) use (&$lastHeartbeat, $startedAt): void {
    $now = microtime(true);
    if ($now - $lastHeartbeat < 5.0) {
        return;
    }
    fwrite(STDERR, sprintf(
        "  ...%d/%d cells (%d%%), %d steps, %d backtracks, %.0fs elapsed\n",
        $assigned,
        $total,
        (int) round($assigned / $total * 100),
        $steps,
        $backtracks,
        $now - $startedAt,
    ));
    $lastHeartbeat = $now;
});

$result = $solver->solve($board, $queue);

if ($result->outcome !== SolveOutcome::Success) {
    $label = $result->outcome === SolveOutcome::Infeasible ? 'PROVEN INFEASIBLE' : 'INCONCLUSIVE';
    fwrite(STDERR, "{$label} after {$result->stepsTaken} steps ({$result->backtracks} backtracks):\n");
    fwrite(STDERR, "  {$result->reason}\n\nRecommendations:\n");
    foreach (Advisor::advise($plan, $dictionary, $result) as $rec) {
        fwrite(STDERR, '  ' . $rec->render() . "\n");
    }
    exit(1);
}

printf(
    "Solved: %d cells in %d steps (%d backtracks), %.1fs total.\n",
    $spec->size(),
    $result->stepsTaken,
    $result->backtracks,
    microtime(true) - $startedAt,
);

$verification = ChainRunner::run($result->board, $plan);
if (!$verification['ok']) {
    fwrite(STDERR, "VERIFICATION FAILED -- the solver produced a board the chain does not solve:\n");
    foreach ($verification['lines'] as $line) {
        fwrite(STDERR, "  {$line}\n");
    }
    exit(1);
}

$written = BookWriter::write($root, $result->board, $plan, $verification, $layout['print'] ?? []);
echo "Verified the full chain end to end.\n\n";
foreach ($verification['lines'] as $line) {
    echo $line . "\n";
}
echo "\nWrote:\n";
foreach ($written as $path) {
    echo "  {$path}\n";
}
