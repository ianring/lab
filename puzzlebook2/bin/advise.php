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
use Puzzlebook2\Feasibility\Advisor;
use Puzzlebook2\Feasibility\AutoTuner;
use Puzzlebook2\Solver\Board;

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$chainConfig = json_decode((string) file_get_contents($root . '/config/chain.json'), true);
$spec = new BoardSpec($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');

if (!$apply) {
    $plan = ChainPlan::load($chainConfig, new Board($spec), $dictionary, 1);
    $recs = Advisor::advise($plan, $dictionary);
    if ($recs === []) {
        echo "Nothing to recommend -- the chain is clean as far as Phase-0 analysis can tell.\n";
        exit(0);
    }
    foreach ($recs as $rec) {
        echo $rec->render() . "\n";
    }
    echo "\nRun with --apply to have these applied automatically (written to config/chain.tuned.json).\n";
    exit(0);
}

$result = AutoTuner::tune($chainConfig, $spec, $dictionary, 1);
if ($result['changes'] === []) {
    echo "No auto-applicable changes -- the chain is already clean (or its problems need a human).\n";
    exit(0);
}

echo "Applied:\n";
foreach ($result['changes'] as $change) {
    echo "  - {$change}\n";
}
$out = $root . '/config/chain.tuned.json';
file_put_contents($out, json_encode($result['chain'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nWrote {$out} -- review it, and adopt it by copying over config/chain.json if you agree.\n";
echo $result['clean']
    ? "The tuned chain passes every Phase-0 check.\n"
    : "The tuned chain still has findings a human needs to decide on -- run bin/check.php against it.\n";
exit($result['clean'] ? 0 : 2);
