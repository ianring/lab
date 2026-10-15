<?php
declare(strict_types=1);

/**
 * Pre-build the caches so page rendering is instant while you iterate on layout.
 *
 *   php warm.php --difficulty=hard --count=20
 *   php warm.php --difficulty=expert --from=1000 --count=50 --solved
 *   php warm.php --seeds=1,7,42 --difficulty=medium
 *   php warm.php --stats
 *   php warm.php --clear
 *
 * Options
 *   --difficulty=NAME  easy | medium | hard | expert | extreme   (default medium)
 *   --from=N           first seed                                (default 1)
 *   --count=N          how many consecutive seeds                (default 10)
 *   --seeds=A,B,C      explicit seed list, overrides from/count
 *   --size=N           staff size                                (default 20)
 *   --clef=NAME        treble | alto | tenor | bass              (default treble)
 *   --variant=NAME     chromatic | diatonic                      (default chromatic)
 *   --spelling=NAME    sharps | flats | mixed                    (default sharps)
 *   --range=MODE       octave | full  (full = whole staff, +/-1 ledger)
 *   --solved           also engrave the solution for each seed
 *   --pdf              also engrave PDF alongside SVG
 *   --no-engrave       generate puzzles only, skip LilyPond
 *   --no-steps         skip the solve walkthrough that index.php displays
 *   --stats            report cache contents and exit
 *   --clear            empty both caches and exit
 */

require __DIR__ . '/lib/errors.php';
require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';
require __DIR__ . '/lib/PuzzleStore.php';
require __DIR__ . '/lib/StaffRenderer.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "warm.php is a CLI tool.\n");
    exit(1);
}

$CACHE   = __DIR__ . '/cache';
$PUZZLES = $CACHE . '/puzzles';
StaffRenderer::useCacheDir($CACHE);

$opts = [
    'difficulty' => 'medium',
    'from'       => 1,
    'count'      => 10,
    'seeds'      => '',
    'size'       => StaffRenderer::DEFAULT_STAFF_SIZE,
    'clef'       => StaffRenderer::DEFAULT_CLEF,
    'variant'    => Variant::DEFAULT,
    'spelling'   => 'sharps',
    'range'      => StaffRenderer::DEFAULT_RANGE,
    'solved'     => false,
    'pdf'        => false,
    'engrave'    => true,
    'steps'      => true,
    'stats'      => false,
    'clear'      => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "unrecognised argument $arg\n");
        exit(1);
    }
    $key = $m[1];
    $val = $m[2] ?? '1';
    if ($key === 'no-engrave') {
        $opts['engrave'] = false;
        continue;
    }
    if ($key === 'no-steps') {
        $opts['steps'] = false;
        continue;
    }
    if (!array_key_exists($key, $opts)) {
        fwrite(STDERR, "unknown option --$key\n");
        exit(1);
    }
    if (in_array($key, ['difficulty', 'seeds', 'clef', 'variant', 'spelling', 'range'], true)) {
        $opts[$key] = $val;
    } elseif (in_array($key, ['solved', 'pdf', 'steps', 'stats', 'clear'], true)) {
        $opts[$key] = (bool) $val;
    } else {
        $opts[$key] = (int) $val;
    }
}

$store = new PuzzleStore($PUZZLES);

function human(int $bytes): string
{
    return $bytes > 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format($bytes / 1024, 0) . ' kB';
}

/** Everything LilyPond leaves in the render cache. */
function engravings(string $cache): array
{
    $files = [];
    foreach (['svg', 'pdf', 'ly'] as $ext) {
        $files = array_merge($files, glob($cache . '/*.' . $ext) ?: []);
    }
    return $files;
}

// ------------------------------------------------------------------- stats

if ($opts['stats']) {
    $ps = $store->stats();
    printf("puzzle cache   %s\n", $store->dir());
    printf("  puzzles      %d\n", $ps['count']);
    printf("  walkthroughs %d\n", $ps['steps']);
    printf("  size         %s\n", human($ps['bytes']));
    printf("  newest       %s\n", $ps['newest'] ? date('Y-m-d H:i:s', $ps['newest']) : '-');

    $eng = engravings($CACHE);
    $bytes = 0;
    foreach ($eng as $f) {
        $bytes += (int) filesize($f);
    }
    printf("\nengraving cache %s\n", $CACHE);
    printf("  files        %d\n", count($eng));
    printf("  size         %s\n", human($bytes));
    printf("  lilypond     %s\n", StaffRenderer::version() ?? 'unknown');
    exit(0);
}

// ------------------------------------------------------------------- clear

if ($opts['clear']) {
    $n = $store->clear();   // *.json covers walkthrough sidecars too
    $m = 0;
    foreach (engravings($CACHE) as $f) {
        if (@unlink($f)) {
            $m++;
        }
    }
    @unlink($CACHE . '/.lilypond-version');
    printf("cleared %d puzzles and %d engraving files\n", $n, $m);
    exit(0);
}

// ------------------------------------------------------------------ warming

$opts['variant'] = Variant::name((string) $opts['variant']);
$variant = Variant::get((string) $opts['variant']);

if (!isset(SudokuEngine::DIFFICULTIES[$opts['difficulty']])) {
    fwrite(STDERR, "unknown difficulty {$opts['difficulty']}\n");
    exit(1);
}

$seeds = [];
if ($opts['seeds'] !== '') {
    foreach (explode(',', $opts['seeds']) as $sv) {
        $sv = trim($sv);
        if ($sv !== '') {
            $seeds[] = (int) $sv;
        }
    }
} else {
    for ($i = 0; $i < max(1, (int) $opts['count']); $i++) {
        $seeds[] = (int) $opts['from'] + $i;
    }
}

if ($opts['engrave'] && StaffRenderer::version() === null) {
    fwrite(STDERR, "warning: lilypond not found (" . StaffRenderer::binary() . "); engraving will fail\n");
}

$views = [false];
if ($opts['solved']) {
    $views[] = true;
}
$formats = ['svg'];
if ($opts['pdf']) {
    $formats[] = 'pdf';
}

$opts['clef'] = StaffRenderer::clefName((string) $opts['clef']);

printf(
    "warming %d seed(s) of %s %s, staff size %d%s%s\n\n",
    count($seeds),
    $variant->sizeLabel(),
    $opts['difficulty'],
    $opts['size'],
    $opts['solved'] ? ', with solutions' : '',
    $opts['engrave'] ? '' : ', puzzles only'
);

$genNew = $genHit = $engNew = $engHit = $failed = 0;
$t0 = microtime(true);

foreach ($seeds as $i => $seed) {
    $hit = $store->has($opts['variant'], $opts['difficulty'], $seed);
    $tp  = microtime(true);
    $puzzle = $store->get($opts['variant'], $opts['difficulty'], $seed);
    $genMs  = (microtime(true) - $tp) * 1000;
    $hit ? $genHit++ : $genNew++;

    $errors = $puzzle->verifyErrors();
    $note   = $errors ? ' VERIFY FAILED: ' . implode('; ', $errors) : '';

    printf(
        "  %3d/%d  seed %-8d %-8s givens %3d  tier %d  puzzle %6.0f ms %s%s",
        $i + 1,
        count($seeds),
        $seed,
        $puzzle->difficultyLabel(),
        $puzzle->givenCount(),
        $puzzle->analysis['maxTier'] ?? 0,
        $genMs,
        $hit ? 'cached' : 'built ',
        $note
    );

    if ($opts['steps']) {
        $sw = microtime(true);
        $had = $store->hasSteps($puzzle);
        $store->steps($puzzle);
        printf(' | walk %5.0f ms%s', (microtime(true) - $sw) * 1000, $had ? '*' : '');
    }

    if (!$opts['engrave']) {
        echo "\n";
        continue;
    }

    foreach ($views as $solved) {
        $ly = StaffRenderer::lilypond(
            $puzzle,
            $solved,
            (int) $opts['size'],
            null,
            (string) $opts['clef'],
            (string) $opts['spelling'],
            StaffRenderer::rangeMode((string) $opts['range'], $variant)
        );
        foreach ($formats as $fmt) {
            $te = microtime(true);
            $r  = StaffRenderer::render($ly, $CACHE, $fmt);
            $ms = (microtime(true) - $te) * 1000;
            if (!$r['ok']) {
                $failed++;
                printf("\n      %s %s FAILED\n%s\n", $solved ? 'solution' : 'puzzle', $fmt, $r['log']);
                continue;
            }
            $r['cached'] ? $engHit++ : $engNew++;
            printf(' | %s%s %5.0f ms%s', $solved ? 's-' : '', $fmt, $ms, $r['cached'] ? '*' : '');
        }
    }
    echo "\n";
}

$elapsed = microtime(true) - $t0;

echo "\n";
printf("puzzles    %d built, %d already cached\n", $genNew, $genHit);
printf("engravings %d built, %d already cached, %d failed\n", $engNew, $engHit, $failed);
printf("elapsed    %.1fs (%.2fs per seed)\n", $elapsed, $elapsed / max(1, count($seeds)));
echo "\n* = served from cache\n";

exit($failed === 0 ? 0 : 1);
