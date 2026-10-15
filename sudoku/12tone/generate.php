<?php
declare(strict_types=1);

/**
 * CLI generator / verifier for 12x12 sudoku (4x3 boxes, symbols 0-9 A B).
 *
 *   php generate.php --difficulty=hard --count=5
 *   php generate.php --difficulty=expert --seed=12345 --out=puzzles
 *   php generate.php --difficulty=hard --explain
 *   php generate.php --selftest=25
 *
 * Every emitted puzzle is run through SudokuEngine::verify() first; a failure is
 * fatal, so nothing unverified ever reaches disk.
 */

require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "generate.php is a CLI tool; use index.php in the browser.\n");
    exit(1);
}

$opts = [
    'difficulty' => 'medium',
    'variant'    => Variant::DEFAULT,
    'count'      => 1,
    'seed'       => null,
    'out'        => null,
    'selftest'   => 0,
    'quiet'      => false,
    'explain'    => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $key = $m[1];
        $val = $m[2] ?? '1';
        if (!array_key_exists($key, $opts)) {
            fwrite(STDERR, "unknown option --$key\n");
            exit(1);
        }
        $opts[$key] = in_array($key, ['difficulty', 'out', 'variant'], true) ? $val : (int) $val;
    }
}
$opts['quiet']   = (bool) $opts['quiet'];
$opts['variant'] = Variant::name((string) $opts['variant']);
$engine = SudokuEngine::forVariant((string) $opts['variant']);
$opts['explain'] = (bool) $opts['explain'];

// ------------------------------------------------------------------ self test

if ($opts['selftest'] > 0) {
    $n      = (int) $opts['selftest'];
    $levels = array_keys(SudokuEngine::DIFFICULTIES);
    $hist   = [];
    $fails  = 0;
    $t0     = microtime(true);

    for ($i = 0; $i < $n; $i++) {
        $level  = $levels[$i % count($levels)];
        $puzzle = $engine->generatePuzzle($level);
        $errors = $engine->verify($puzzle);

        $label = $puzzle->difficultyLabel();
        $key   = "$level -> $label";
        $hist[$key] = ($hist[$key] ?? 0) + 1;

        if ($errors) {
            $fails++;
            fwrite(STDERR, sprintf(
                "FAIL seed=%s level=%s: %s\n",
                $puzzle->meta['seed'],
                $level,
                implode('; ', $errors)
            ));
        }
        if (!$opts['quiet']) {
            printf(
                "%3d/%d  %-8s givens=%3d  tier=%d  score=%-5d %-8s %s\n",
                $i + 1,
                $n,
                $level,
                $puzzle->givenCount(),
                $puzzle->analysis['maxTier'],
                $puzzle->analysis['score'],
                $label,
                $errors ? 'FAIL' : 'ok'
            );
        }
    }

    $elapsed = microtime(true) - $t0;
    echo "\n--- self test ---\n";
    ksort($hist);
    foreach ($hist as $k => $v) {
        printf("  %-24s %d\n", $k, $v);
    }
    printf("  %d puzzles, %d failures, %.1fs (%.2fs each)\n", $n, $fails, $elapsed, $elapsed / max(1, $n));
    exit($fails === 0 ? 0 : 1);
}

// ----------------------------------------------------------------- generation

if ($opts['out'] !== null && !is_dir($opts['out']) && !mkdir($opts['out'], 0775, true)) {
    fwrite(STDERR, "cannot create output directory {$opts['out']}\n");
    exit(1);
}

for ($i = 0; $i < max(1, (int) $opts['count']); $i++) {
    $seed   = $opts['seed'] === null ? null : (int) $opts['seed'] + $i;
    $puzzle = $engine->generatePuzzle($opts['difficulty'], $seed);
    $errors = $engine->verify($puzzle);

    if ($errors) {
        fwrite(STDERR, "verification failed: " . implode('; ', $errors) . "\n");
        exit(1);
    }

    if ($opts['out'] !== null) {
        $file = sprintf(
            '%s/%s-%s-%d.json',
            rtrim($opts['out'], '/'),
            $opts['variant'],
            $opts['difficulty'],
            $puzzle->meta['seed']
        );
        file_put_contents($file, $puzzle->toJson());
        echo "wrote $file\n";
    } else {
        echo $puzzle->toText();
        printf(
            "difficulty=%s label=%s givens=%d tier=%d score=%d seed=%d\n",
            $puzzle->meta['difficulty'],
            $puzzle->difficultyLabel(),
            $puzzle->givenCount(),
            $puzzle->analysis['maxTier'],
            $puzzle->analysis['score'],
            $puzzle->meta['seed']
        );
        foreach ($puzzle->analysis['techniques'] as $name => $uses) {
            printf("  %-22s %d\n", $name, $uses);
        }

        if ($opts['explain']) {
            $labels = SudokuEngine::techniqueLabels();
            echo "\nsolve walkthrough:\n";
            $step = 0;
            foreach ($engine->explain($puzzle)['steps'] as $st) {
                $label = $labels[$st['key']]['label'] ?? $st['key'];
                if ($st['type'] === 'place') {
                    printf(
                        "  %3d. %-6s = %s   %-20s %s\n",
                        ++$step,
                        $engine->cellName($st['cell']),
                        $engine->symbolOf($st['value']),
                        $label,
                        $st['detail']
                    );
                } else {
                    $parts = [];
                    foreach (array_slice($st['elims'], 0, 6) as $el) {
                        $parts[] = $engine->cellName($el['cell']) . '-' . $engine->symbolsOf($el['mask']);
                    }
                    $extra = count($st['elims']) - 6;
                    printf(
                        "       %-10s %-20s %s\n",
                        implode(' ', $parts) . ($extra > 0 ? " +$extra" : ''),
                        $label,
                        $st['detail']
                    );
                }
            }
        }
        echo "\n";
    }
}
