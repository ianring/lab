#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-flight check on a rule set, WITHOUT building a book.
 *
 * The point is iteration speed: edit config/chain.php, run this, and find out
 * whether the rule set can work -- and if not, which specific thing is short
 * and by how much -- in about a second, instead of discovering it from a stack
 * trace partway through a full build.
 *
 * It answers the questions that actually go wrong in practice:
 *
 *   1. Is the chain's SHAPE buildable? One spelling step or more, one pair
 *      step, one reveal, one knight's-move step, one final step.
 *   2. Do the answer and every revealed word SURVIVE all the spelling rules?
 *      This is the classic authoring trap -- a rule added later silently kills
 *      a word an earlier reveal depends on ("knight" vs. a
 *      three-consonants-in-a-row rule).
 *   3. Does the DICTIONARY contain enough words of each shape the build needs:
 *      filler that dies at each step, launch-pad keys, and words that survive
 *      everything?
 *   4. Does the ARITHMETIC work out -- enough survivors for the reveal's
 *      spacing, enough doomed side-by-side slots for the pairs?
 *
 * A clean run here doesn't guarantee a build will succeed: where the keys land
 * is geometric, and the builder may need several attempts to find an
 * arrangement where no key sits in a slot a revealed word needs. But every
 * failure this script reports is a certain one, named in terms of the knob
 * that fixes it.
 *
 * Exit code 0 means nothing fatal was found; 1 means at least one blocker.
 */

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainBuilder;
use Puzzlebook\Grid;

$root = dirname(__DIR__);
$layout = require $root . '/config/layout.php';
$chain = require $root . '/config/chain.php';
$wordlistPath = $root . '/data/wordlist.txt';

/** @var string[] $problems */
$problems = [];

echo "Checking config/chain.php against config/layout.php and the word list.\n\n";

// ---------------------------------------------------------------- 1. shape

$grid = new Grid($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);
$size = $grid->size();
echo "Grid: {$grid->pages} pages x {$grid->rows} rows x {$grid->cols} cols = {$size} words.\n";

try {
    [$spellingSteps, $spellingStepsBeforePair, $pairStep, $revealStep, $keyStep, $finalStep]
        = ChainBuilder::parseChain($chain);
} catch (Throwable $e) {
    echo "BLOCKED: the chain's shape isn't buildable.\n  " . $e->getMessage() . "\n";
    exit(1);
}

$spellingRules = [];
foreach ($spellingSteps as $step) {
    try {
        $spellingRules[] = ChainBuilder::spellingRuleFor($step['rule']);
    } catch (Throwable $e) {
        echo "BLOCKED: step {$step['id']} -- " . $e->getMessage() . "\n";
        exit(1);
    }
}
$spellingCount = count($spellingRules);

try {
    $finalRule = ChainBuilder::spellingRuleFor($finalStep['rule']);
} catch (Throwable $e) {
    echo "BLOCKED: the final step -- " . $e->getMessage() . "\n";
    exit(1);
}

echo 'Chain: ' . count($chain['steps']) . " steps -- {$spellingCount} spelling rules, 1 pair rule, "
    . "1 reveal, 1 knight's-move rule, 1 final rule.\n\n";

// -------------------------------------------- 2. reserved words survive the rules

$answer = $chain['answer'];
$revealWords = explode(' ', $revealStep['expected']);
$revealN = $revealStep['reveal']['params']['n'];
$keyLength = $keyStep['rule']['params']['length'];
$keyEnding = $finalStep['rule']['params']['ending'] ?? '?';

echo "Reserved words (the answer plus every revealed word) vs. the spelling rules:\n";
foreach (array_merge([$answer], $revealWords) as $word) {
    $killedBy = [];
    foreach ($spellingSteps as $index => $step) {
        if ($spellingRules[$index]->failsWord($word)) {
            $killedBy[] = $step['id'];
        }
    }
    if ($killedBy === []) {
        echo "  ok      {$word}\n";
    } else {
        $ids = implode(', ', $killedBy);
        echo "  BLOCKED {$word}  <- crossed out by step {$ids}\n";
        $problems[] = "'{$word}' must survive every spelling rule, but step {$ids} removes it. "
            . 'Reword it, or loosen that rule.';
    }
}

$repeats = array_keys(array_filter(array_count_values($revealWords), static fn (int $n): bool => $n > 1));
if ($repeats !== []) {
    $problems[] = 'The revealed phrase repeats ' . implode(', ', $repeats) . ', but each word can only be '
        . 'placed in the book once. Reword the phrase so every word in it is different.';
}

$declared = $revealStep['reveal']['params']['count'] ?? null;
if ($declared !== null && $declared !== count($revealWords)) {
    $problems[] = "Step {$revealStep['id']} declares count={$declared} but 'expected' splits into "
        . count($revealWords) . ' words.';
}

if ($finalRule->failsWord($answer)) {
    $problems[] = "The answer '{$answer}' is crossed out by the final step {$finalStep['id']}, so nothing "
        . 'would be left standing. Pick an answer that rule does not match.';
}
$finalLength = $finalStep['rule']['params']['length'] ?? null;
if ($finalLength !== null && $finalLength !== $keyLength) {
    $problems[] = "Step {$keyStep['id']} launches from {$keyLength}-letter words but the final step clears "
        . "{$finalLength}-letter ones -- they must match, or the launch pads are never removed.";
}
echo "\n";

// ------------------------------------------------------------ 3. arithmetic

// The surviving count is set by where the keys land, not by keep_fraction, so what
// matters here is only that it can reach far enough for the reveal, and that the
// pacing arithmetic doesn't go negative.
$slotsNeeded = $revealN * count($revealWords);
$targetAlive = $slotsNeeded + 8;

echo "The reveal reads every {$revealN}th survivor, " . count($revealWords) . " words, so at least "
    . "{$slotsNeeded} words must survive to step {$revealStep['id']}.\n";
echo "The builder aims to keep about {$targetAlive} alive by placing {$keyLength}-letter launch-pad keys "
    . "and letting each one doom its knight's-move neighbours.\n\n";

[$counts, $nPairs] = ChainBuilder::planCounts($size, $spellingSteps, $pairStep);
$doomed = $size - $targetAlive;
$pacingTotal = array_sum($counts) + 2 * $nPairs;
$slack = $doomed - $pacingTotal;
$lastId = $spellingSteps[$spellingCount - 1]['id'];

echo "Pacing from the keep_fractions (the last spelling step absorbs the difference):\n";
$remaining = $size;
foreach ($spellingSteps as $step) {
    $removed = $counts[$step['id']];
    if ($step['id'] === $lastId) {
        $removed += $slack;
    }
    $remaining -= $removed;
    $pct = round($removed / $size * 100, 1);
    $note = $step['id'] === $lastId && $slack !== 0
        ? ' (includes ' . ($slack > 0 ? '+' : '') . "{$slack} of slack)"
        : '';
    echo "  step {$step['id']}: removes {$removed} ({$pct}% of the book), leaving {$remaining}{$note}\n";
}
$remaining -= 2 * $nPairs;
echo "  step {$pairStep['id']}: removes " . (2 * $nPairs) . " as {$nPairs} pairs, leaving {$remaining}\n";
echo "  step {$revealStep['id']}: reveal (removes nothing)\n";
echo "  step {$keyStep['id']}: the knight's-move rule clears everything but the keys and the answer\n";
echo "  step {$finalStep['id']}: clears the keys -- must leave exactly 1\n\n";

if ($counts[$lastId] + $slack < 0) {
    $problems[] = "Step {$lastId} would have to remove " . ($counts[$lastId] + $slack) . ' words (a negative '
        . 'number): the earlier keep_fractions already remove more than the grid has to spare. Raise an '
        . 'earlier keep_fraction so fewer words die before it.';
}

$pairSlots = $grid->pages * $grid->rows * count(array_filter([0, 2, 4], static fn (int $c): bool => $c + 1 < $grid->cols));
if ($nPairs > $pairSlots) {
    $problems[] = "Step {$pairStep['id']} needs {$nPairs} side-by-side pairs but the grid only has {$pairSlots} "
        . "pair slots. Raise that step's keep_fraction, or make the grid bigger.";
}

if ($targetAlive > $size) {
    $problems[] = "The reveal needs {$targetAlive} surviving words but the whole book is only {$size} cells.";
}

// ------------------------------------------------- 4. dictionary supply

$words = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($words === false) {
    echo "BLOCKED: could not read {$wordlistPath}\n";
    exit(1);
}
$excluded = $layout['filler_excluded_initial'] ?? '';
echo 'Word list: ' . count($words) . " words.\n\n";

$survivesThrough = static function (string $word, int $through) use ($spellingRules): bool {
    for ($j = 0; $j < $through; $j++) {
        if ($spellingRules[$j]->failsWord($word)) {
            return false;
        }
    }
    return true;
};

echo "Word supply -- what each role needs vs. what the list has:\n";

foreach ($spellingSteps as $index => $step) {
    $needed = $counts[$step['id']] + ($step['id'] === $lastId ? $slack : 0);
    $needed = max(0, $needed);
    $available = 0;
    foreach ($words as $w) {
        if ($excluded !== '' && $w[0] === $excluded) {
            continue;
        }
        if ($survivesThrough($w, $index) && $spellingRules[$index]->failsWord($w)) {
            $available++;
        }
    }
    $verdict = $available >= $needed ? 'ok     ' : 'BLOCKED';
    echo "  {$verdict} step {$step['id']} filler: needs {$needed}, has {$available}\n";
    if ($available < $needed) {
        $problems[] = "Step {$step['id']} needs {$needed} words that pass every earlier rule and fail it; the "
            . "list has {$available}. Raise that step's keep_fraction so it removes fewer words.";
    }
}

// Pair words only have to survive the rules BEFORE the pair step.
$pairNeeded = $nPairs;
$pairAvailable = 0;
foreach ($words as $w) {
    if ($excluded !== '' && $w[0] === $excluded) {
        continue;
    }
    if ($survivesThrough($w, $spellingStepsBeforePair)) {
        $pairAvailable++;
    }
}
$verdict = $pairAvailable >= $pairNeeded * 2 ? 'ok     ' : 'BLOCKED';
echo "  {$verdict} pair words: needs " . ($pairNeeded * 2) . ", has {$pairAvailable}\n";
if ($pairAvailable < $pairNeeded * 2) {
    $problems[] = "The pair step needs " . ($pairNeeded * 2) . ' words surviving the rules before it; the list '
        . "has {$pairAvailable}.";
}

// Keys: exactly $keyLength letters, ending in $keyEnding, surviving every spelling rule.
$keysAvailable = 0;
foreach ($words as $w) {
    if ($excluded !== '' && $w[0] === $excluded) {
        continue;
    }
    if (strlen($w) !== $keyLength || substr($w, -1) !== $keyEnding) {
        continue;
    }
    if ($survivesThrough($w, $spellingCount)) {
        $keysAvailable++;
    }
}
// Each key dooms up to 8 neighbours, so the count needed is modest; be generous.
$keysNeeded = (int) ceil($targetAlive / 6);
$verdict = $keysAvailable >= $keysNeeded ? 'ok     ' : 'BLOCKED';
echo "  {$verdict} launch-pad keys: needs around {$keysNeeded}, has {$keysAvailable} "
    . "({$keyLength}-letter words ending in '{$keyEnding}' that survive every rule)\n";
if ($keysAvailable < $keysNeeded) {
    $problems[] = "The knight's-move rule needs around {$keysNeeded} launch-pad words ({$keyLength} letters, "
        . "ending in '{$keyEnding}', surviving every spelling rule), but the list only has {$keysAvailable}. "
        . 'Loosen a spelling rule, or change the key shape in the final step.';
}

// Survivors: everything alive at the knight's-move step that isn't a key.
$survivorsNeeded = $targetAlive;
$survivorsAvailable = 0;
foreach ($words as $w) {
    if ($excluded !== '' && $w[0] === $excluded) {
        continue;
    }
    if ($survivesThrough($w, $spellingCount)) {
        $survivorsAvailable++;
    }
}
$verdict = $survivorsAvailable >= $survivorsNeeded ? 'ok     ' : 'BLOCKED';
echo "  {$verdict} survivors: needs {$survivorsNeeded}, has {$survivorsAvailable} "
    . "(words passing every spelling rule)\n";
if ($survivorsAvailable < $survivorsNeeded) {
    $problems[] = "{$survivorsNeeded} words must survive every spelling rule, but only {$survivorsAvailable} in "
        . 'the list can. Loosen a rule.';
}

echo "\n";

// -------------------------------------------------------------- verdict

if ($problems === []) {
    echo "No blockers found. The chain's shape, its reserved words, its arithmetic and the word supply all\n";
    echo "check out -- run bin/build.php to place the words and verify the real book.\n";
    exit(0);
}

echo 'Found ' . count($problems) . " blocker(s):\n\n";
foreach ($problems as $n => $p) {
    echo '  ' . ($n + 1) . ". {$p}\n\n";
}
exit(1);
