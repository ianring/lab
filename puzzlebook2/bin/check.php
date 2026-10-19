#!/usr/bin/env php
<?php

declare(strict_types=1);

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '1024M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Solver\Board;

$root = dirname(__DIR__);

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$chainConfig = json_decode((string) file_get_contents($root . '/config/chain.json'), true);

$spec = new BoardSpec($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');
echo "Board: {$spec->pages} pages x {$spec->rows} x {$spec->cols} = {$spec->size()} cells.  ";
echo "Dictionary: {$dictionary->count()} words.\n\n";

$board = new Board($spec);
$plan = ChainPlan::load($chainConfig, $board, $dictionary, 1);

echo str_pad('step', 5) . str_pad('rule', 34) . str_pad('kind', 9)
    . str_pad('scopes', 8) . str_pad('cells', 7) . str_pad('supply', 9) . "headroom\n";
echo str_repeat('-', 80) . "\n";
foreach ($plan->demands() as $pos => $demand) {
    $step = $plan->steps()[$pos];
    $rule = $plan->rules()[$pos];
    $cells = $demand->count * $demand->cellsPerScope;
    $supply = $demand->rawSupplyEstimate === PHP_INT_MAX ? '-' : (string) $demand->rawSupplyEstimate;
    $headroom = ($demand->count > 0 && $demand->rawSupplyEstimate !== PHP_INT_MAX)
        ? sprintf('%.1fx', $demand->rawSupplyEstimate / $demand->count)
        : '-';
    echo str_pad((string) $step['id'], 5)
        . str_pad($rule->id(), 34)
        . str_pad($rule->kind(), 9)
        . str_pad((string) $demand->count, 8)
        . str_pad((string) $cells, 7)
        . str_pad($supply, 9)
        . $headroom . "\n";
}
echo str_repeat('-', 80) . "\n";
$totalCells = 1; // answer
foreach ($plan->demands() as $d) {
    $totalCells += $d->count * $d->cellsPerScope;
}
echo "Total cells demanded (answer included): {$totalCells} of {$spec->size()}\n\n";

$recommendations = \Puzzlebook2\Feasibility\Advisor::advise($plan, $dictionary);
if ($recommendations === []) {
    echo "No problems found. The chain is buildable as far as Phase-0 analysis can tell.\n";
    exit(0);
}
echo "RECOMMENDATIONS:\n";
foreach ($recommendations as $rec) {
    echo '  ' . $rec->render() . "\n";
}
exit($plan->problems() === [] ? 0 : 2);
