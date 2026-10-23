#!/usr/bin/env php
<?php

declare(strict_types=1);

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '2048M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Rules\SpellingRules\BoggleRule;

/**
 * Precompute, for every BoggleRule board, the dictionary words that can be
 * traced on it -- the expensive search done ONCE, up front, so the rule is a
 * fast set lookup at build time.
 *
 *   php bin/compute-boggle.php
 *
 * Writes data/boggle/<ClassName>.txt (one word per line) for each concrete
 * BoggleRule subclass, and prints each board's cardinality. Re-run whenever a
 * board changes or a new Boggle board rule is added.
 */

$root = dirname(__DIR__);
$dict = Dictionary::loadFromFile($root . '/data/wordlist.txt');
$words = $dict->all();
$outDir = $root . '/data/boggle';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "could not create {$outDir}\n");
    exit(2);
}

$ruleDir = $root . '/lib/Rules/SpellingRules';
$ns = 'Puzzlebook2\\Rules\\SpellingRules\\';
$boards = [];
foreach (scandir($ruleDir) as $file) {
    if (!str_ends_with($file, 'Rule.php')) {
        continue;
    }
    $class = $ns . substr($file, 0, -4);
    if (!class_exists($class)) {
        continue;
    }
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->isInstantiable() || !$rc->isSubclassOf(BoggleRule::class)) {
        continue;
    }
    $boards[$rc->getShortName()] = new $class();
}
if ($boards === []) {
    fwrite(STDERR, "No concrete BoggleRule subclasses found.\n");
    exit(1);
}

printf("Tracing %d dictionary words on %d board(s):\n\n", count($words), count($boards));
foreach ($boards as $short => $rule) {
    $grid = $rule->grid();
    $hits = [];
    foreach ($words as $w) {
        if (strlen($w) >= BoggleRule::MIN_LENGTH && BoggleRule::trace($grid, $w)) {
            $hits[] = $w;
        }
    }
    sort($hits);
    $path = "{$outDir}/{$short}.txt";
    file_put_contents($path, implode("\n", $hits) . "\n");

    $byLen = $hits;
    usort($byLen, static fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
    $short3 = array_slice($byLen, 0, 8);
    $long = array_slice(array_reverse($byLen), 0, 5);
    printf("  %-18s %6d words (%.3f%%)  ->  data/boggle/%s.txt\n", $short, count($hits), count($hits) / count($words) * 100, $short);
    printf("      short:   %s\n", strtoupper(implode(', ', $short3)));
    printf("      longest: %s\n\n", strtoupper(implode(', ', $long)));
}
echo "Done. Commit data/boggle/ so the boards build without recomputation.\n";
