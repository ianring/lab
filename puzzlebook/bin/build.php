#!/usr/bin/env php
<?php

declare(strict_types=1);

// A full-size book is 12,000 cells laid out against a 133,000-word list, and the
// builder may try many arrangements -- more than PHP's usual 128M default allows.
// Raised here so this works out of the box rather than needing a php.ini change.
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '512M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainBuilder;
use Puzzlebook\Engine;
use Puzzlebook\Grid;
use Puzzlebook\JsonConfig;
use Puzzlebook\Solver;

$root = dirname(__DIR__);

// Loaded early (not just after build() returns, as before) so the local-retry
// heartbeat below can name which RULE a step id belongs to, not just its number.
$chain = JsonConfig::load($root . '/config/chain.json');
$chain = \Puzzlebook\ChainPlan::numberSteps($chain);
$ruleTypeByStepId = [];
foreach ($chain['steps'] as $step) {
    $ruleTypeByStepId[$step['id']] = $step['rule']['type'] ?? '?';
}

// Nothing else below prints anything until build() returns or gives up, and a single
// attempt that fails late -- after filling thousands of cells -- can take real time on
// its own. Without a heartbeat, a slow run and a HUNG one are indistinguishable from
// the terminal, AND the retry-reason tally this script's own catch block prints below
// only exists once every attempt has already failed -- which is itself a long wait at
// 20,000 possible attempts. Printing it live, every few seconds, means seeing WHY
// attempts are failing within seconds of starting, not only after the whole run gives
// up (or never, if it succeeds -- in which case there's nothing to diagnose anyway).
$buildStartedAt = microtime(true);
$lastHeartbeatAt = $buildStartedAt;
$lastFillHeartbeatAt = $buildStartedAt;
$built = ChainBuilder::build(
    $root . '/config/layout.json',
    $root . '/config/chain.json',
    $root . '/data/wordlist.txt',
    static function (int $attempt, array $retryReasons) use (&$lastHeartbeatAt, $buildStartedAt): void {
        $now = microtime(true);
        if ($now - $lastHeartbeatAt < 5.0) {
            return;
        }
        fwrite(STDERR, sprintf("  ...attempt %d, %.0fs elapsed\n", $attempt + 1, $now - $buildStartedAt));
        if ($retryReasons !== []) {
            arsort($retryReasons);
            foreach (array_slice($retryReasons, 0, 3, true) as $reason => $n) {
                fwrite(STDERR, "        {$n}x  {$reason}\n");
            }
        }
        $lastHeartbeatAt = $now;
    },
    // Covers what the heartbeat above can't: a SINGLE attempt that's slow rather than
    // stuck, which otherwise prints nothing at all until it either succeeds or throws.
    static function (int $filled, int $total) use (&$lastFillHeartbeatAt, $buildStartedAt): void {
        $now = microtime(true);
        if ($now - $lastFillHeartbeatAt < 5.0) {
            return;
        }
        $pct = $total > 0 ? (int) round($filled / $total * 100) : 100;
        fwrite(STDERR, sprintf(
            "      ...filling cell %d/%d (%d%%), %.0fs elapsed\n",
            $filled,
            $total,
            $pct,
            $now - $buildStartedAt
        ));
        $lastFillHeartbeatAt = $now;
    },
    // Fires on EVERY local retry, not throttled -- these are rare enough (a few
    // per attempt at most, by design, or MAX_LOCAL_ATTEMPTS gives up) that a
    // heartbeat-style 5-second throttle would hide most of them rather than
    // declutter anything.
    static function (int $fromIndex, ?int $fromStepId, int $toIndex, ?int $toStepId, string $reason) use ($ruleTypeByStepId): void {
        $fromLabel = $fromStepId !== null ? "step {$fromStepId} [{$ruleTypeByStepId[$fromStepId]}]" : 'an unattributed step';
        $toLabel = $toStepId !== null ? "step {$toStepId} [{$ruleTypeByStepId[$toStepId]}]" : 'the very start of construction';
        fwrite(STDERR, "    ~ local retry: {$fromLabel} failed ({$reason}) -- backtracking to {$toLabel}\n");
    }
);

// How many arrangements the builder had to try is useful to see (a number much
// above 1 means the constraints are tight and small rule changes may stop
// fitting), but it isn't part of the book, so it doesn't go into book.json.
$attempts = $built['attempts'];
$book = ['grid' => $built['grid'], 'words' => $built['words']];

if (!is_dir($root . '/build')) {
    mkdir($root . '/build', 0777, true);
}

// web/api.php (and this script's own report, below) need a plain-English
// description of each reveal step's pattern. Engine::describeReveal() is a
// pure function of the step's own data, so it's computed once, here, and
// baked into chain.json as 'pattern_text' -- the browser never needs to
// call it itself, it just displays the string.
$chainForExport = $chain;
foreach ($chainForExport['steps'] as &$step) {
    if ($step['kind'] === 'reveal') {
        $step['pattern_text'] = Engine::describeReveal($step['reveal']);
    }
}
unset($step);

$bookJson = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
// Stamped so the preview can show when it was built. Rule wording lives in this
// file, which the browser reads instead of config/chain.json -- so editing a
// rule_text and reloading the preview shows the OLD text until a rebuild. Having
// the date on screen makes that obvious instead of baffling.
$chainForExport['built_at'] = date('D j M Y, H:i');

$chainJson = json_encode($chainForExport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$jsonPath = $root . '/build/book.json';
file_put_contents($jsonPath, $bookJson);

$chainPath = $root . '/build/chain.json';
file_put_contents($chainPath, $chainJson);

$pagesPath = $root . '/build/pages.txt';
file_put_contents($pagesPath, renderPages($book));

// web/index.html is a static page that fetches these two files over plain
// HTTP -- it doesn't execute anything server-side, so the book it previews
// is this exact build, not a separate copy of the logic. Writing them
// straight into web/ means the whole web/ folder is one self-contained
// thing to copy to your server.
if (!is_dir($root . '/web')) {
    mkdir($root . '/web', 0777, true);
}
file_put_contents($root . '/web/book.json', $bookJson);
file_put_contents($root . '/web/chain.json', $chainJson);

$total = $book['grid']['pages'] * $book['grid']['rows'] * $book['grid']['cols'];
echo "Built a {$book['grid']['pages']}-page book ({$total} words) and verified the full chain end to end.\n";
echo $attempts === 1
    ? "Found a working arrangement on the first try.\n"
    : "Took {$attempts} arrangements to find one satisfying every constraint.\n";
echo "Wrote {$jsonPath}\n";
echo "Wrote {$chainPath}\n";
echo "Wrote {$pagesPath} (a plain-text proof of every page, for reading over)\n";
echo "Copied book.json and chain.json into web/ for the preview page to fetch\n\n";

// ChainBuilder::verify() already confirmed this holds together; re-running it
// through Solver here just prints the same walk stage by stage, since that's
// the only place this report is written.
$result = Solver::run($book, $chain);

// The words each step actually removes from THIS real, built book -- not the
// theoretical count bin/check.php works from before any word is chosen. Lets you
// see, concretely, a step that's doing disproportionately much of the work, and
// look for other rules whose catch turns out to be a SUBSET of it -- the same
// kind of resource collision the vowel_fraction/knight's-move incident turned out
// to be, just checked here against what actually got built instead of in theory.
$eliminatedDir = $root . '/build/eliminated';
if (!is_dir($eliminatedDir)) {
    mkdir($eliminatedDir, 0777, true);
}

$stepById = [];
foreach ($chain['steps'] as $step) {
    $stepById[$step['id']] = $step;
}

foreach ($result['eliminated'] as $stepId => $removedWords) {
    $description = Engine::ruleFromConfig($stepById[$stepId]['rule'])->describe();
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $description));
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 40);
    sort($removedWords);
    $stepFile = $eliminatedDir . '/step-' . str_pad((string) $stepId, 2, '0', STR_PAD_LEFT) . "-{$slug}.txt";
    file_put_contents($stepFile, implode("\n", $removedWords) . "\n");
}

file_put_contents($eliminatedDir . '/summary.txt', implode("\n", $result['lines']) . "\n");

echo "Wrote {$eliminatedDir}/ -- one file per step listing the words it actually\n";
echo "removed from this book, plus summary.txt with the counts printed below.\n\n";

foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

/** @param array{grid: array{pages:int,rows:int,cols:int}, words: string[]} $book */
function renderPages(array $book): string
{
    $grid = new Grid($book['grid']['pages'], $book['grid']['rows'], $book['grid']['cols']);
    $words = $book['words'];
    $colWidth = 8 + max(array_map('strlen', $words));

    $out = '';
    for ($page = 0; $page < $grid->pages; $page++) {
        $out .= '=== Page ' . ($page + 1) . " ===\n";
        for ($row = 0; $row < $grid->rows; $row++) {
            $cells = [];
            for ($col = 0; $col < $grid->cols; $col++) {
                $i = $grid->linearIndex($page, $row, $col);
                $cells[] = str_pad(($i + 1) . ':' . $words[$i], $colWidth);
            }
            $out .= rtrim(implode('', $cells)) . "\n";
        }
        $out .= "\n";
    }
    return $out;
}
