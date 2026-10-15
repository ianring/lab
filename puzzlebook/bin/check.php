#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-flight check on a rule set, WITHOUT building a book.
 *
 * The point is iteration speed: edit config/chain.php, run this, and find out
 * whether the rule set can work -- and if not, what's short and by how much --
 * in about a second, instead of discovering it from a stack trace partway
 * through a full build.
 *
 * What it checks:
 *
 *   1. Is the chain's SHAPE buildable? Every eliminate step needs a rule that
 *      knows how to construct itself, one reveal, one final step.
 *   2. Do the answer and every revealed word SURVIVE all the spelling rules?
 *      The classic authoring trap -- a rule added later silently kills a word
 *      an earlier reveal depends on.
 *   3. Does the DICTIONARY hold enough words of each shape the build needs?
 *      This is the ceiling that decides whether a big chain is possible at
 *      all, because the "passes every rule" pool shrinks multiplicatively
 *      with each rule added.
 *   4. Does the ARITHMETIC work -- enough survivors for the reveal's spacing,
 *      enough room for the pacing the keep_fractions ask for?
 *
 * A clean run doesn't guarantee a build: cell geometry is decided per attempt,
 * and the builder may need several tries. But every failure reported here is a
 * certain one, named in terms of the knob that fixes it.
 *
 * Exit 0 means no blockers; 1 means at least one.
 */

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainPlan;
use Puzzlebook\Grid;
use Puzzlebook\Rules\ConstructsCells;
use Puzzlebook\Rules\SpellingRule;

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
    $plan = ChainPlan::fromConfig($chain);
} catch (Throwable $e) {
    echo "BLOCKED: the chain's shape isn't buildable.\n  " . $e->getMessage() . "\n";
    exit(1);
}

$spellingRules = $plan->spellingRules();
$spellingCount = count($spellingRules);
$revealWords = $plan->revealWords();
$revealN = $plan->revealInterval();
$answer = $chain['answer'];

echo 'Chain: ' . count($chain['steps']) . " steps -- {$spellingCount} spelling rules, "
    . count($plan->constructorsByPriority()) . " rules that build their own cells, 1 reveal, 1 final rule.\n";

echo "\nConstruction order (which rule gets to choose its cells first):\n";
foreach ($plan->constructorsByPriority() as [$stepId, $rule, $priority]) {
    $kind = $rule instanceof SpellingRule ? 'spelling, takes any cell' : 'positional, needs specific cells';
    $short = (new ReflectionClass($rule))->getShortName();
    echo "  priority {$priority}: step {$stepId} ({$short}) -- {$kind}\n";
}
echo "\n";

// -------------------------------------------- 2. reserved words survive the rules

echo "Reserved words (the answer plus every revealed word) vs. the spelling rules:\n";
foreach (array_merge([$answer], $revealWords) as $word) {
    $killedBy = [];
    foreach ($spellingRules as $index => $rule) {
        if ($rule->failsWord($word)) {
            $killedBy[] = '#' . ($index + 1) . ' (' . (new ReflectionClass($rule))->getShortName() . ')';
        }
    }
    if ($killedBy === []) {
        echo "  ok      {$word}\n";
    } else {
        $which = implode(', ', $killedBy);
        echo "  BLOCKED {$word}  <- crossed out by spelling rule {$which}\n";
        $problems[] = "'{$word}' must survive every spelling rule, but rule {$which} removes it. "
            . 'Reword it, or loosen that rule.';
    }
}

try {
    $plan->validateReservedWords($answer);
} catch (Throwable $e) {
    $problems[] = $e->getMessage();
}
echo "\n";

// ------------------------------------------------------------ 3. arithmetic

$slotsNeeded = $revealN * count($revealWords);
$targetSurvivors = $slotsNeeded + 8;

echo "The reveal reads every {$revealN}th survivor, " . count($revealWords) . " words, so at least "
    . "{$slotsNeeded} words must still be standing when it's read; the builder aims for {$targetSurvivors}.\n";

if ($targetSurvivors > $size) {
    $problems[] = "The reveal needs {$targetSurvivors} surviving words but the book is only {$size} cells.";
}

$available = $size - $targetSurvivors;
try {
    $budgets = $plan->budgetsFor($available, $size);
} catch (Throwable $e) {
    echo "\nBLOCKED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nPacing -- how many cells each rule claims (the last keep_fraction step absorbs the slack):\n";
$runningLeft = $size;
foreach ($budgets as $stepId => $budget) {
    $runningLeft -= $budget;
    $pct = $size > 0 ? round($budget / $size * 100, 1) : 0.0;
    echo "  step {$stepId}: claims {$budget} cells ({$pct}% of the book)\n";
    if ($budget < 0) {
        $problems[] = "Step {$stepId} would have to claim {$budget} cells (a negative number): the other "
            . 'keep_fractions already account for more of the book than is left. Raise one of them.';
    }
}
echo "  survivors left standing for the reveal: {$targetSurvivors}\n\n";

// ------------------------------------------------- 4. dictionary supply

$words = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($words === false) {
    echo "BLOCKED: could not read {$wordlistPath}\n";
    exit(1);
}
$excluded = $layout['filler_excluded_initial'] ?? '';
echo 'Word list: ' . count($words) . " words";
echo $excluded !== '' ? " (filler skips those starting with '{$excluded}').\n\n" : ".\n\n";

$survivesThrough = static function (string $word, int $through) use ($spellingRules): bool {
    for ($j = 0; $j < $through; $j++) {
        if ($spellingRules[$j]->failsWord($word)) {
            return false;
        }
    }
    return true;
};

// The pool that passes EVERY rule is the one that shrinks multiplicatively as rules
// are added, and it's the hard ceiling on how big a chain can get. Show it shrinking.
echo "The \"passes every rule so far\" pool, rule by rule -- this is the ceiling on chain length:\n";
$poolAfter = [];
$eligible = [];
foreach ($words as $w) {
    if ($excluded !== '' && $w[0] === $excluded) {
        continue;
    }
    $eligible[] = $w;
}
$running = $eligible;
echo '  before any rule: ' . count($running) . " words\n";
for ($j = 0; $j < $spellingCount; $j++) {
    $next = [];
    foreach ($running as $w) {
        if (!$spellingRules[$j]->failsWord($w)) {
            $next[] = $w;
        }
    }
    $died = count($running) - count($next);
    $running = $next;
    $poolAfter[$j] = count($running);
    $short = (new ReflectionClass($spellingRules[$j]))->getShortName();
    echo "  after rule #" . ($j + 1) . " ({$short}): " . count($running)
        . " words survive (that rule can remove at most {$died})\n";
}
$survivesEverything = count($running);
echo "\n";

echo "Word supply -- what each role needs vs. what the list can provide:\n";

// Each spelling rule can only ever remove words it actually matches, from among
// those that got past the earlier rules.
$spellingStepIds = [];
foreach ($plan->constructorsByPriority() as [$stepId, $rule]) {
    if ($rule instanceof SpellingRule) {
        $spellingStepIds[$stepId] = $rule;
    }
}

$ruleIndex = 0;
foreach ($chain['steps'] as $step) {
    if (($step['kind'] ?? '') !== 'eliminate' || !empty($step['final'])) {
        continue;
    }
    $stepId = $step['id'];
    if (!isset($spellingStepIds[$stepId])) {
        continue;
    }
    $rule = $spellingStepIds[$stepId];
    $needed = $budgets[$stepId] ?? 0;

    $availableForStep = 0;
    foreach ($eligible as $w) {
        if ($survivesThrough($w, $ruleIndex) && $rule->failsWord($w)) {
            $availableForStep++;
        }
    }
    $verdict = $availableForStep >= $needed ? 'ok     ' : 'BLOCKED';
    echo "  {$verdict} step {$stepId}: needs {$needed} words that fail it, has {$availableForStep}\n";
    if ($availableForStep < $needed) {
        $problems[] = "Step {$stepId} needs {$needed} words that pass every earlier rule and fail it, but the "
            . "list only has {$availableForStep}. Raise that step's keep_fraction so it removes fewer, or "
            . 'move it earlier in the chain where more words are still eligible.';
    }
    $ruleIndex++;
}

// Everything that has to be standing at the reveal comes from the survives-everything
// pool, as do the deliberate pairs (which survive the rules before their own step).
$verdict = $survivesEverything >= $targetSurvivors ? 'ok     ' : 'BLOCKED';
echo "  {$verdict} survivors: needs {$targetSurvivors} words that pass every rule, has {$survivesEverything}\n";
if ($survivesEverything < $targetSurvivors) {
    $problems[] = "{$targetSurvivors} words have to pass every spelling rule to still be standing at the "
        . "reveal, but only {$survivesEverything} in the list can. Loosen a rule, or use fewer of them.";
}

echo "\n";

// -------------------------------------------------------------- verdict

if ($problems === []) {
    echo "No blockers found. Shape, reserved words, arithmetic and word supply all check out --\n";
    echo "run bin/build.php to place the words and verify the real book.\n";
    exit(0);
}

echo 'Found ' . count($problems) . " blocker(s):\n\n";
foreach ($problems as $n => $p) {
    echo '  ' . ($n + 1) . ". {$p}\n\n";
}
exit(1);
