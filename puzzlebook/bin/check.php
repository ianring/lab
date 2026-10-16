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

// A full-size book is 12,000 cells laid out against a 133,000-word list, which needs
// more than PHP's usual 128M default. Raised here so this works out of the box rather
// than needing a php.ini change -- left alone if the limit is already unlimited.
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '512M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainBuilder;
use Puzzlebook\ChainPlan;
use Puzzlebook\Engine;
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

// What the chain actually DOES, in reading order, so a dozen-plus rules stay legible
// without cross-referencing config/chain.php. Each rule describes itself.
echo "\nThe chain, in the order a reader works through it:\n";
foreach ($chain['steps'] as $step) {
    $id = str_pad((string) $step['id'], 2);
    if (($step['kind'] ?? '') === 'reveal') {
        echo "  {$id} REVEAL  " . Engine::describeReveal($step['reveal']) . "\n";
        echo "            -> \"" . $step['expected'] . "\"\n";
        continue;
    }
    $rule = Engine::ruleFromConfig($step['rule']);
    $marker = !empty($step['final']) ? 'FINAL ' : (empty($step['printed']) ? 'HIDDEN' : '      ');
    echo "  {$id} {$marker}  crosses out " . $rule->describe() . "\n";
}

echo "\nConstruction order (which rule gets to choose its cells first):\n";
foreach ($plan->constructorsByPriority() as [$stepId, $rule, $priority]) {
    $kind = $rule instanceof SpellingRule ? 'spelling, takes any cell' : 'positional, needs specific cells';
    echo "  priority " . str_pad((string) $priority, 3) . " step " . str_pad((string) $stepId, 2)
        . " -- {$kind}\n";
}
echo "\n";

// -------------------------------------------- 2. reserved words survive the rules

echo "Reserved words (the answer plus every revealed word) vs. the spelling rules:\n";
foreach (array_merge([$answer], $revealWords) as $word) {
    $killedBy = [];
    foreach ($spellingRules as $index => $rule) {
        if ($rule->failsWord($word)) {
            $killedBy[] = '#' . ($index + 1) . ' (' . $rule->describe() . ')';
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

// A reserved word doesn't have to be in the word list -- it's placed directly at its
// cell rather than looked up -- but it's worth knowing when one isn't, because it will
// then be the only word in the book that didn't come from the pool. Usually that's
// invisible; it's only awkward if the word also stands out in some other way (being
// longer than anything else, say).
//
// Checked with one streaming pass rather than by building an index: a hash table keyed
// on 133,000 strings costs several megabytes, and we only have a dozen words to look up.
$wanted = array_fill_keys(array_merge([$answer], $revealWords), false);
$scan = fopen($wordlistPath, 'r');
if ($scan !== false) {
    while (($line = fgets($scan)) !== false) {
        $word = trim($line);
        if (array_key_exists($word, $wanted)) {
            $wanted[$word] = true;
        }
    }
    fclose($scan);
}
$notInPool = array_keys(array_filter($wanted, static fn (bool $found): bool => !$found));
if ($notInPool !== []) {
    echo "\n  note: " . implode(', ', $notInPool) . ' not in the word list. '
        . (count($notInPool) === 1 ? 'It' : 'They') . " will still be placed, but won't\n"
        . "        appear anywhere else in the book. Fine unless they look conspicuous.\n";
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

// Lay the book out for real -- no words chosen, just which rule claims which cell and
// what shape each cell's word must be. Everything below is read off this, so the
// numbers are what the build will really do rather than what the fractions imply.
$canvas = null;
$layoutError = null;
for ($try = 0; $try < 25 && $canvas === null; $try++) {
    try {
        $canvas = ChainBuilder::layOutCells($try, $grid, $plan, $chain);
    } catch (Throwable $e) {
        $layoutError = $e->getMessage();
    }
}

if ($canvas === null) {
    echo "\nBLOCKED: the cells couldn't be laid out in 25 attempts, before any words were chosen.\n";
    echo "         last reason: {$layoutError}\n";
    exit(1);
}

echo "\nPacing -- cells each rule actually claims in a real layout:\n";
$claims = $canvas->claimsByStep();
$runningLeft = $size;
foreach ($claims as $stepId => $claimed) {
    $runningLeft -= $claimed;
    $pct = $size > 0 ? round($claimed / $size * 100, 1) : 0.0;
    $sized = $plan->hasBudget($stepId) ? '' : '  [sizes itself]';
    echo '  step ' . str_pad((string) $stepId, 2) . ': removes ' . str_pad((string) $claimed, 5)
        . " ({$pct}% of the book), leaving {$runningLeft}{$sized}\n";
}
echo "  still standing for the reveal: " . $canvas->survivorCount() . "\n\n";

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
//
// Done with one pass per word rather than by rebuilding the whole list per rule: each
// word is tested against the rules in order and we record where it first fails, so this
// needs a dozen counters instead of a dozen copies of a 133,000-word array.
echo "The \"passes every rule so far\" pool, rule by rule -- this is the ceiling on chain length:\n";

$eligible = [];
foreach ($words as $w) {
    if ($excluded === '' || $w[0] !== $excluded) {
        $eligible[] = $w;
    }
}
unset($words);   // the filtered list is the only one we need from here on

$diesAt = array_fill(0, $spellingCount, 0);
$survivesEverything = 0;
foreach ($eligible as $w) {
    $failedAt = null;
    for ($j = 0; $j < $spellingCount; $j++) {
        if ($spellingRules[$j]->failsWord($w)) {
            $failedAt = $j;
            break;
        }
    }
    if ($failedAt === null) {
        $survivesEverything++;
    } else {
        $diesAt[$failedAt]++;
    }
}

$stillStanding = count($eligible);
echo '  before any rule: ' . $stillStanding . " words\n";
for ($j = 0; $j < $spellingCount; $j++) {
    $stillStanding -= $diesAt[$j];
    echo '  after rule #' . ($j + 1) . ': ' . $stillStanding
        . " words survive (it can remove at most {$diesAt[$j]}) -- " . $spellingRules[$j]->describe() . "\n";
}
echo "  -> {$survivesEverything} words pass all {$spellingCount} rules. Everything still standing at the\n";
echo "     reveal has to come from that pool, and it shrinks every time a rule is added.\n\n";

// Lay the book out for real -- no words chosen, just which rule claims which cell and
// what shape each cell's word must be -- then read the demands straight off it. Doing
// it this way means EVERY rule gets checked, positional ones included: a rule can't be
// left out, because the demand being tested is the one the rule itself declared.
echo "Word supply -- every demand the rules actually make, read from a real layout:\n";

$stepNames = [];
foreach ($chain['steps'] as $step) {
    if (($step['kind'] ?? '') === 'eliminate') {
        $stepNames[$step['id']] = Engine::ruleFromConfig($step['rule'])->describe();
    }
}

foreach ($canvas->demandsByLabel() as $label => $demand) {
    $cellPlan = $demand['plan'];
    $needed = $demand['cells'];
    $prefix = $cellPlan->survivesPrefix();
    $steps = $demand['steps'] === [] ? '-' : implode(',', $demand['steps']);

    // Cells holding a predetermined word -- the answer, the revealed phrase -- have no
    // supply requirement at all: the word is assigned straight to its cell and never
    // looked up. They also can't be counted as a group, because each one wants a
    // DIFFERENT specific word while the group carries a single representative plan;
    // testing that plan against the list asks "how many words equal 'eliminate'?" and
    // compares the answer, 1, against all 13 cells. Whether these words are in the list
    // is reported separately, further up.
    if ($cellPlan->literalWord() !== null) {
        echo '  ok      ' . str_pad($label, 14) . 'needs ' . str_pad((string) $needed, 5)
            . "set in advance, placed directly (no supply needed)\n";
        continue;
    }

    // A plan whose shape depends on a neighbour's word (the right half of a
    // deliberate pair) can't be counted in isolation -- there's no neighbour yet.
    // Say so rather than reporting a misleading zero.
    if ($cellPlan->allowsLeftMatch()) {
        echo '  ok      ' . str_pad($label, 14) . "needs " . str_pad((string) $needed, 5)
            . " depends on its neighbour's word (checked during the build)\n";
        continue;
    }

    $available = 0;
    foreach ($eligible as $w) {
        if ($survivesThrough($w, $prefix) && $cellPlan->shapeAllows($w, [])) {
            $available++;
        }
    }

    $verdict = $available >= $needed ? 'ok     ' : 'BLOCKED';
    echo "  {$verdict} " . str_pad($label, 14) . 'needs ' . str_pad((string) $needed, 5)
        . 'has ' . str_pad((string) $available, 7)
        . "(step {$steps}: " . ($stepNames[$demand['steps'][0] ?? 0] ?? 'reserved words') . ")\n";

    if ($available < $needed) {
        $stepId = $demand['steps'][0] ?? null;
        $fractionNow = null;
        foreach ($chain['steps'] as $step) {
            if (($step['id'] ?? null) === $stepId) {
                $fractionNow = $step['construction']['keep_fraction'] ?? null;
            }
        }
        $advice = $fractionNow === null
            ? 'This rule sizes itself from its own geometry, so reduce what it asks for by narrowing '
                . 'its parameters, or give it more pages to work with.'
            : "Raise step {$stepId}'s keep_fraction above {$fractionNow} so it asks for fewer.";
        $problems[] = "The '{$label}' cells need {$needed} words of a shape the list only has "
            . "{$available} of. {$advice}";
    }
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
