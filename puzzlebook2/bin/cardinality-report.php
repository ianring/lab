#!/usr/bin/env php
<?php

declare(strict_types=1);

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '2048M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Rule cardinality report: for every concrete spelling rule, how many words of
 * the dictionary it matches -- its intrinsic "supply", independent of any book.
 * (Different from bin/elim-report.php, which is per-book actual eliminations.)
 *
 *   php bin/cardinality-report.php          # sorted high to low
 *   php bin/cardinality-report.php --asc     # sorted low to high
 */

$root = dirname(__DIR__);
$asc = in_array('--asc', array_slice($argv, 1), true);
$dict = Dictionary::loadFromFile($root . '/data/wordlist.txt');
$words = $dict->all();
$total = count($words);

$dir = $root . '/lib/Rules/SpellingRules';
$ns = 'Puzzlebook2\\Rules\\SpellingRules\\';
$rows = [];
foreach (scandir($dir) as $file) {
    if (!str_ends_with($file, 'Rule.php')) {
        continue;
    }
    $class = $ns . substr($file, 0, -4);
    if (!class_exists($class)) {
        continue;
    }
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->isInstantiable() || !$rc->isSubclassOf(SpellingRuleBase::class)) {
        continue;
    }
    if ($rc->getConstructor() !== null && $rc->getConstructor()->getNumberOfRequiredParameters() > 0) {
        continue;
    }
    $rule = new $class();
    $n = 0;
    foreach ($words as $w) {
        if ($rule->failsWord($w)) {
            $n++;
        }
    }
    $rows[$rc->getShortName()] = $n;
}

$asc ? asort($rows) : arsort($rows);

printf("Rule cardinality against %d dictionary words (%d rules):\n\n", $total, count($rows));
printf("%8s  %7s  %s\n", 'matches', '%', 'rule');
foreach ($rows as $name => $n) {
    printf("%8d  %6.3f%%  %s\n", $n, $n / $total * 100, $name);
}
