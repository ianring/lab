#!/usr/bin/env php
<?php

declare(strict_types=1);

if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '2048M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Chain\Engine;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Feasibility\AutoTuner;
use Puzzlebook2\Output\BookWriter;
use Puzzlebook2\Output\ChainRunner;
use Puzzlebook2\Rules\SpellingRuleBase;
use Puzzlebook2\Series\ChainVariant;
use Puzzlebook2\Series\SeriesPlanner;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\Rng;
use Puzzlebook2\Solver\Solver;
use Puzzlebook2\Solver\SolveOutcome;

/**
 * Build the whole series with the Mystery chain wired in (Phase 3).
 *
 *   php bin/build-series.php              # all 21 books, full 100 pages
 *   php bin/build-series.php --only=1,2   # just these books
 *   php bin/build-series.php --pages=25   # smaller board, fast iteration
 *
 * SeriesPlanner decides every planted cell up front; each book is then one
 * solve. A book that fails (a placement the solver thrashes on) is retried
 * with fresh seeds before being given up on. Writes build/series/bookNN/ and
 * build/series/plan.json (the Mystery map, for bin/check-series.php).
 */

$root = dirname(__DIR__);
$only = null;
$pagesOverride = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--only=([\d,]+)$/', $arg, $m)) {
        $only = array_map('intval', explode(',', $m[1]));
    } elseif (preg_match('/^--pages=(\d+)$/', $arg, $m)) {
        $pagesOverride = (int) $m[1];
    }
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$series = json_decode((string) file_get_contents($root . '/config/series.json'), true);
if (!is_array($layout) || !is_array($series)) {
    fwrite(STDERR, "config/layout.json or config/series.json failed to parse.\n");
    exit(2);
}
$chainPath = $root . '/' . ($series['chain'] ?? 'config/chain.json');
$chainConfig = json_decode((string) file_get_contents($chainPath), true);
if (!is_array($chainConfig)) {
    fwrite(STDERR, "chain file {$chainPath} failed to parse.\n");
    exit(2);
}

$pages = $pagesOverride ?? $layout['grid']['pages'];
$spec = new BoardSpec($pages, $layout['grid']['rows'], $layout['grid']['cols']);
$dictionary = Dictionary::loadFromFile($root . '/data/wordlist.txt');
$print = $layout['print'] ?? [];
$capstone = (int) ($series['capstone_book'] ?? 0);
$total = (int) ($series['total_books'] ?? count($series['books']));

// ---- pick 21 DISTINCT answers, each surviving its OWN book's rules ----------
// Books now differ (a different rule subset each), so every answer must survive
// its own book. Book 1 additionally needs letter-sum <= pages (its Mystery page).
$checksumOf = static fn (string $w): int => array_sum(array_map(static fn ($c): int => ord($c) - 96, str_split($w)));
$chains = ChainVariant::composeSeries($total, $capstone);
$survivorsFor = static function (array $chain) use ($dictionary): array {
    $spelling = [];
    $final = null;
    foreach ($chain['steps'] as $step) {
        $rule = Engine::ruleFromConfig($step['rule']);
        if ($rule instanceof SpellingRuleBase) {
            if (!empty($step['final'])) {
                $final = $rule;
            } else {
                $spelling[] = $rule;
            }
        }
    }
    $out = [];
    foreach ($dictionary->all() as $w) {
        if ($final !== null && $final->failsWord($w)) {
            continue;
        }
        foreach ($spelling as $r) {
            if ($r->failsWord($w)) {
                continue 2;
            }
        }
        $out[] = $w;
    }
    return $out;
};

$answers = [];
$used = [];
foreach (range(1, $total) as $n) {   // Book 1 first naturally; order doesn't matter for distinctness
    $surv = Rng::shuffle($survivorsFor($chains[$n]), 4242 + $n);
    $picked = null;
    foreach ($surv as $w) {
        if (isset($used[$w])) {
            continue;
        }
        if ($n === 1) {
            $cs = $checksumOf($w);
            $vow = preg_match_all('/[aeiou]/', $w);
            if (!($cs >= 1 && $cs <= $spec->pages && strlen($w) <= $spec->rows && $vow >= 1 && $vow <= $spec->cols)) {
                continue;
            }
        }
        $picked = $w;
        break;
    }
    if ($picked === null) {
        fwrite(STDERR, "Book {$n}: no distinct surviving answer found" . ($n === 1 ? " (needs letter-sum <= {$spec->pages})" : '') . ".\n");
        exit(2);
    }
    $answers[$n] = $picked;
    $used[$picked] = true;
}

// ---- plan the whole series (Mystery chain) before solving anything --------
$planner = new SeriesPlanner($answers, $dictionary, $spec, $total, 777, ChainVariant::REACTION_BOOK);
$plan = $planner->plan();

printf(
    "Series build: %d books, %d pages x %d x %d = %d cells.  Mystery chain wired.\n"
    . "First answers: %s ...  First Mysteries: %s ...\n\n",
    $total, $spec->pages, $spec->rows, $spec->cols, $spec->size(),
    implode(', ', array_slice(array_values($answers), 0, 4)),
    implode(', ', array_slice(array_values($plan['mysteries']), 0, 4)),
);

// Persist the Mystery map for the validator / inspection.
$outRoot = $root . '/build/series';
if (!is_dir($outRoot)) {
    mkdir($outRoot, 0777, true);
}
file_put_contents($outRoot . '/plan.json', json_encode([
    'total_books' => $total,
    'pages' => $spec->pages,
    'rows' => $spec->rows,
    'cols' => $spec->cols,
    'answers' => $plan['answers'],
    'mysteries' => $plan['mysteries'],
    'addressee' => $plan['addressee'],
    'acrostic_page' => $plan['acrostic_page'],
    'acrostic_start_col' => $plan['acrostic_start_col'],
    'places' => $plan['places'],
    'box_number' => $plan['box_number'],
    'box_find' => $plan['box_find'],
    'reaction_book' => $plan['reaction_book'] ?? null,
    'bombs' => $plan['bombs'] ?? [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// The reaction book (if any) builds from a BOOM-free dictionary so no stray
// trigger word can appear incidentally; its real BOOM triggers are planted.
$boomFree = new Dictionary(array_values(array_filter(
    $dictionary->all(),
    static fn (string $w): bool => !str_contains($w, 'boom'),
)));

$seriesStart = microtime(true);
$results = [];

foreach ($series['books'] as $bookCfg) {
    $n = (int) $bookCfg['n'];
    if ($only !== null && !in_array($n, $only, true)) {
        continue;
    }
    $baseSeed = (int) $bookCfg['seed'];
    $answerCell = $plan['books'][$n]['answer_cell'];
    $plants = $plan['books'][$n]['plants'];
    $bookDict = $n === ChainVariant::REACTION_BOOK ? $boomFree : $dictionary;
    $label = $n === $capstone ? "Book {$n} (Capstone)" : "Book {$n}";

    // This book's own chain: the Capstone gets the borrowed diagonal, every
    // other book a middle rotated by its number. AutoTuner rebalances it for
    // that order before the solve ("the software does the intelligent work").
    $composed = $chains[$n];
    $composed['answer'] = $answers[$n];
    $tuned = AutoTuner::tune($composed, $spec, $bookDict, $baseSeed);
    $thisChain = $tuned['chain'];
    $types = [];
    foreach ($thisChain['steps'] as $s) {
        if (!empty($s['printed'])) {
            $types[] = preg_replace('/Rule$/', '', $s['rule']['type']);
        }
    }
    fwrite(STDERR, sprintf("%s: %d rules [%s, ...], %d auto-tune change(s)\n",
        $label, count($types), implode(', ', array_slice($types, 0, 6)), count($tuned['changes'])));

    $built = false;
    foreach ([$baseSeed, $baseSeed + 1000, $baseSeed + 2000] as $attempt => $seed) {
        $started = microtime(true);
        fwrite(STDERR, "--- {$label}, seed {$seed}" . ($attempt > 0 ? " (retry {$attempt})" : '') . " ---\n");

        $board = new Board($spec);
        $planObj = ChainPlan::load($thisChain, $board, $bookDict, $seed, $plants, $answerCell);
        if ($planObj->problems() !== []) {
            $results[$n] = ['error' => 'plan: ' . $planObj->problems()[0]];
            fwrite(STDERR, "  SKIPPED -- plan has problems:\n");
            foreach ($planObj->problems() as $prob) {
                fwrite(STDERR, "    - {$prob}\n");
            }
            break; // a plan problem is deterministic; retrying the seed won't help
        }

        $planObj->registerGlobalConstraints();
        $queue = new ReservationQueue();
        $planObj->fillQueue($queue);

        $lastBeat = microtime(true);
        $solver = new Solver(static function (int $a, int $t, int $s, int $bt) use (&$lastBeat, $started): void {
            $now = microtime(true);
            if ($now - $lastBeat < 5.0) {
                return;
            }
            fwrite(STDERR, sprintf("    ...%d/%d cells (%d%%), %d backtracks, %.0fs\n",
                $a, $t, (int) round($a / $t * 100), $bt, $now - $started));
            $lastBeat = $now;
        });

        $result = $solver->solve($board, $queue);
        if ($result->outcome !== SolveOutcome::Success) {
            fwrite(STDERR, "  solve {$result->outcome->name} -- retrying with a fresh seed\n");
            unset($board, $planObj, $queue, $solver, $result);
            continue;
        }
        $verification = ChainRunner::run($result->board, $planObj);
        if (!$verification['ok']) {
            fwrite(STDERR, "  verification failed -- retrying with a fresh seed\n");
            unset($board, $planObj, $queue, $solver, $result);
            continue;
        }

        [$bookJson, $chainJson] = BookWriter::encode($result->board, $planObj, $verification, $print);
        $dir = sprintf('%s/book%02d', $outRoot, $n);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("{$dir}/book.json", $bookJson);
        file_put_contents("{$dir}/chain.json", $chainJson);

        $secs = microtime(true) - $started;
        $results[$n] = ['answer' => $planObj->answer(), 'secs' => $secs, 'backtracks' => $result->backtracks, 'seed' => $seed];
        fwrite(STDERR, sprintf("  OK: '%s' survives, %d backtracks, %.1fs\n", $planObj->answer(), $result->backtracks, $secs));
        $built = true;
        unset($board, $planObj, $queue, $solver, $result, $verification, $bookJson, $chainJson);
        gc_collect_cycles();
        break;
    }
    if (!$built && !isset($results[$n])) {
        $results[$n] = ['error' => 'all seed attempts failed to solve/verify'];
    }
}

echo "\n" . str_repeat('-', 56) . "\n";
echo "Series build summary:\n";
$failed = 0;
foreach ($series['books'] as $bookCfg) {
    $n = (int) $bookCfg['n'];
    if (!isset($results[$n])) {
        continue;
    }
    $r = $results[$n];
    $tag = $n === $capstone ? ' (Capstone)' : '';
    if (isset($r['error'])) {
        $failed++;
        printf("  book%02d%s  FAILED  %s\n", $n, $tag, $r['error']);
    } else {
        printf("  book%02d%s  %-10s seed %-5d %4d backtracks  %5.1fs\n",
            $n, $tag, "'{$r['answer']}'", $r['seed'], $r['backtracks'], $r['secs']);
    }
}
printf("\n%d built, %d failed, %.0fs total.\n", count($results) - $failed, $failed, microtime(true) - $seriesStart);
echo "Output: build/series/bookNN/{book,chain}.json + build/series/plan.json\n";
echo "Verify the Mystery chain: php bin/check-series.php" . ($pagesOverride ? " --pages={$pagesOverride}" : '') . "\n";
exit($failed > 0 ? 1 : 0);
