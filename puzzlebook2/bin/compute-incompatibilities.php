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
 * Find pairs of SPELLING rules whose match sets so overlap that a book should
 * never contain both -- and (with --write) stamp the finding into each class
 * as a `const INCOMPATIBLE_WITH`.
 *
 *   php bin/compute-incompatibilities.php            # report only (default)
 *   php bin/compute-incompatibilities.php --write     # also edit the classes
 *
 * The test is SUBSUMPTION over the real dictionary: if every word rule A
 * crosses out is also crossed out by rule B, then A adds nothing wherever B is
 * present (ContainsOntRule is pointless beside ContainsNtRule). Such a pair is
 * declared incompatible on BOTH rules (symmetric). Rules with identical match
 * sets are mutually subsumed -- also incompatible. "Heavy overlap" that is not
 * full subsumption is only reported, never auto-stamped: that is a taste call.
 *
 * This covers pure spelling rules only (those with failsWord()). Pair/geometric
 * rules overlap at the pair level, not word-by-word, so their incompatibilities
 * stay hand-declared on the class (e.g. SemordnilapPairOnPageRule).
 */

$root = dirname(__DIR__);
$write = in_array('--write', array_slice($argv, 1), true);
$dict = Dictionary::loadFromFile($root . '/data/wordlist.txt');
$words = $dict->all();
$total = count($words);

// ---- discover every concrete spelling rule -------------------------------
$dir = $root . '/lib/Rules/SpellingRules';
$ns = 'Puzzlebook2\\Rules\\SpellingRules\\';
$rules = [];   // short name => rule object
foreach (scandir($dir) as $file) {
    if (!str_ends_with($file, 'Rule.php')) {
        continue;
    }
    $short = substr($file, 0, -4);
    $class = $ns . $short;
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
    $rules[$short] = new $class();
}
ksort($rules);
printf("Scanning %d spelling rules against %d words.\n\n", count($rules), $total);

// ---- match sets ----------------------------------------------------------
$sets = [];   // short => [wordIndex => true]
$counts = [];
foreach ($rules as $short => $rule) {
    $set = [];
    foreach ($words as $i => $w) {
        if ($rule->failsWord($w)) {
            $set[$i] = true;
        }
    }
    $sets[$short] = $set;
    $counts[$short] = count($set);
}

$subsetOf = static function (array $a, array $b): bool {
    foreach ($a as $i => $_) {
        if (!isset($b[$i])) {
            return false;
        }
    }
    return true;
};

// ---- find subsumptions and heavy overlaps --------------------------------
$names = array_keys($rules);
$incompat = array_fill_keys($names, []);   // short => short[]
$subsumptionLines = [];
$overlapLines = [];

for ($x = 0; $x < count($names); $x++) {
    for ($y = $x + 1; $y < count($names); $y++) {
        $a = $names[$x];
        $b = $names[$y];
        if ($counts[$a] === 0 || $counts[$b] === 0) {
            continue;
        }
        $aInB = $subsetOf($sets[$a], $sets[$b]);
        $bInA = $subsetOf($sets[$b], $sets[$a]);
        if ($aInB || $bInA) {
            $incompat[$a][] = $b;
            $incompat[$b][] = $a;
            if ($aInB && $bInA) {
                $subsumptionLines[] = sprintf('%s == %s (identical: %d words)', $a, $b, $counts[$a]);
            } elseif ($aInB) {
                $subsumptionLines[] = sprintf('%s (%d) subset of %s (%d)', $a, $counts[$a], $b, $counts[$b]);
            } else {
                $subsumptionLines[] = sprintf('%s (%d) subset of %s (%d)', $b, $counts[$b], $a, $counts[$a]);
            }
            continue;
        }
        // Heavy overlap (not subsumption): reported for human review only.
        $inter = 0;
        $small = $counts[$a] <= $counts[$b] ? $sets[$a] : $sets[$b];
        $big = $counts[$a] <= $counts[$b] ? $sets[$b] : $sets[$a];
        foreach ($small as $i => $_) {
            if (isset($big[$i])) {
                $inter++;
            }
        }
        $frac = $inter / min($counts[$a], $counts[$b]);
        if ($frac >= 0.85) {
            $overlapLines[] = sprintf('%s n %s = %d (%.0f%% of the smaller) -- REVIEW', $a, $b, $inter, $frac * 100);
        }
    }
}

echo "SUBSUMPTIONS (auto-incompatible):\n";
echo $subsumptionLines === [] ? "  (none)\n" : '  ' . implode("\n  ", $subsumptionLines) . "\n";
echo "\nHEAVY OVERLAP (>=85%, not subsumption -- your call, NOT auto-stamped):\n";
echo $overlapLines === [] ? "  (none)\n" : '  ' . implode("\n  ", $overlapLines) . "\n";

// ---- write mode: stamp const INCOMPATIBLE_WITH into each class -----------
if (!$write) {
    echo "\n(report only; re-run with --write to stamp `const INCOMPATIBLE_WITH` into the classes)\n";
    return;
}

echo "\nWriting annotations:\n";
foreach ($names as $short) {
    $path = "{$dir}/{$short}.php";
    $src = (string) file_get_contents($path);
    $list = $incompat[$short];
    sort($list);

    // Strip any existing stamped const (idempotent re-runs).
    $src = preg_replace('/\n[ \t]*\/\*\* Auto-computed[^\n]*\*\/\n[ \t]*public const INCOMPATIBLE_WITH = \[[^\]]*\];\n/', "\n", $src);
    $src = preg_replace('/\n[ \t]*public const INCOMPATIBLE_WITH = \[[^\]]*\];\n/', "\n", $src);

    if ($list !== []) {
        $items = implode(', ', array_map(static fn (string $s): string => "'{$s}'", $list));
        $block = "\n    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */\n"
            . "    public const INCOMPATIBLE_WITH = [{$items}];\n";
        // Insert right after the class opening brace (which sits on its own line).
        $src = preg_replace('/((?:final )?class \w+[^{]*\{)/', "$1{$block}", $src, 1);
    }

    file_put_contents($path, $src);
    printf("  %-28s %s\n", $short, $list === [] ? '(none)' : implode(', ', $list));
}
echo "\nDone. Review with: git diff lib/Rules/SpellingRules\n";
