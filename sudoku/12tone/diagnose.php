<?php
declare(strict_types=1);

/**
 * Environment and pipeline check for staff.php.
 *
 * Prints plain text, one stage at a time, flushing as it goes - so if something
 * dies hard, the last line printed tells you exactly where it died.
 *
 *   http://.../sudoku/12tone/diagnose.php
 *   php diagnose.php
 */

require __DIR__ . '/lib/errors.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

function out(string $s = ''): void
{
    echo $s . "\n";
    flush();
}

function heading(string $s): void
{
    out('');
    out($s);
    out(str_repeat('-', strlen($s)));
}

function kv(string $k, $v): void
{
    out(sprintf('  %-24s %s', $k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v));
}

/** Run one stage, reporting failure with full context instead of dying silently. */
function step(string $name, callable $fn)
{
    echo sprintf('  %-34s ', $name . ' ...');
    flush();
    $t = microtime(true);
    try {
        $r = $fn();
        out(sprintf('ok  (%d ms)', (int) round((microtime(true) - $t) * 1000)));
        return $r;
    } catch (\Throwable $e) {
        out('FAILED');
        out('');
        out('    ' . get_class($e) . ': ' . $e->getMessage());
        out('    at ' . $e->getFile() . ':' . $e->getLine());
        out('');
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            out('    ' . $line);
        }
        out('');
        exit(1);
    }
}

out('12-tone sudoku :: diagnostics');

// --------------------------------------------------------------------- php

heading('PHP');
kv('version', PHP_VERSION);
kv('sapi', PHP_SAPI);
kv('memory_limit', ini_get('memory_limit'));
kv('max_execution_time', ini_get('max_execution_time'));
kv('display_errors', ini_get('display_errors'));
kv('error_log', ini_get('error_log') ?: '(server default)');
kv('open_basedir', ini_get('open_basedir') ?: '(none)');
kv('disable_functions', ini_get('disable_functions') ?: '(none)');
kv('exec available', function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true));
kv('user', (function_exists('posix_getpwuid') && function_exists('posix_geteuid'))
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : get_current_user());

// ------------------------------------------------------------------- files

heading('Files');
$files = [
    'lib/errors.php',
    'lib/Variant.php',
    'lib/SudokuEngine.php',
    'lib/Puzzle.php',
    'lib/StaffRenderer.php',
    'index.php',
    'staff.php',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    kv($f, is_file($path) ? number_format((float) filesize($path)) . ' bytes' : 'MISSING');
}

$cache = __DIR__ . '/cache';
kv('cache/', is_dir($cache) ? (is_writable($cache) ? 'writable' : 'NOT WRITABLE') : 'missing (will be created)');
if (!is_dir($cache)) {
    kv('cache/ creatable', @mkdir($cache, 0775, true) || is_dir($cache));
}

// ------------------------------------------------------------------ loading

heading('Loading classes');
step('require Variant.php',      static fn () => require_once __DIR__ . '/lib/Variant.php');
step('require SudokuEngine.php', static fn () => require_once __DIR__ . '/lib/SudokuEngine.php');
step('require Puzzle.php',       static fn () => require_once __DIR__ . '/lib/Puzzle.php');
step('require PuzzleStore.php',  static fn () => require_once __DIR__ . '/lib/PuzzleStore.php');
step('require StaffRenderer.php', static fn () => require_once __DIR__ . '/lib/StaffRenderer.php');
step('require PdfSupport.php',   static fn () => require_once __DIR__ . '/lib/PdfSupport.php');
step('require PdfPage.php',      static fn () => require_once __DIR__ . '/lib/PdfPage.php');
step('require PageOptions.php',  static fn () => require_once __DIR__ . '/lib/PageOptions.php');

// ---------------------------------------------------------------- lilypond

heading('Variants');
foreach (Variant::all() as $v) {
    kv($v->key, $v->sizeLabel() . ' grid, boxes ' . $v->boxLabel() . ', symbols ' . $v->alphabet);
}

heading('Caches');
StaffRenderer::useCacheDir($cache);
$store = new PuzzleStore($cache . '/puzzles');
$ps = $store->stats();
kv('puzzle cache dir', $store->dir());
kv('puzzle entries', $ps['count']);
kv('walkthrough entries', $ps['steps']);
kv('puzzle dir writable', is_dir($store->dir()) ? (is_writable($store->dir()) ? 'yes' : 'NO') : 'not created yet');
kv('puzzle cache size', number_format($ps['bytes'] / 1024, 0) . ' kB');
$eng = array_merge(glob($cache . '/*.svg') ?: [], glob($cache . '/*.pdf') ?: []);
kv('engraving files', count($eng));
kv('version memo', is_file($cache . '/.lilypond-version') ? 'present' : 'absent');

heading('LilyPond');
$bin = StaffRenderer::binary();
kv('binary', $bin);

$which = [];
$whichStatus = 1;
@exec('command -v ' . escapeshellarg($bin) . ' 2>&1', $which, $whichStatus);
kv('resolved path', $whichStatus === 0 && $which ? $which[0] : 'NOT FOUND on PATH');
kv('PATH', getenv('PATH') ?: '(empty)');

$ver = [];
$verStatus = 1;
@exec(escapeshellarg($bin) . ' --version 2>&1', $ver, $verStatus);
kv('--version exit', $verStatus);
foreach (array_slice($ver, 0, 3) as $line) {
    out('    ' . $line);
}
kv('detected version', StaffRenderer::version() ?? 'unknown');
kv('-dcrop supported', StaffRenderer::supportsCrop());

// ---------------------------------------------------------------- pipeline

heading('Pipeline');
$wasCached = $store->has(Variant::DEFAULT, 'easy', 1);
$puzzle = step('puzzle easy/seed 1 (' . ($wasCached ? 'from cache' : 'generating') . ')',
    static fn () => $store->get(Variant::DEFAULT, 'easy', 1));
kv('givens', $puzzle->givenCount());
kv('rating', $puzzle->difficultyLabel());

$errors = step('verify puzzle', static fn () => $store->errorsFor($puzzle));
kv('verification', $errors ? 'FAILED: ' . implode('; ', $errors) : 'clean');

$second = step('re-fetch same seed (cache hit)', static fn () => $store->get(Variant::DEFAULT, 'easy', 1));
kv('matches first', $second->puzzle === $puzzle->puzzle ? 'yes' : 'NO - cache is not deterministic');
kv('cached to disk', $store->has(Variant::DEFAULT, 'easy', 1) ? 'yes' : 'NO - writes are failing');

$hadSteps = $store->hasSteps($puzzle);
$walk = step('walkthrough (' . ($hadSteps ? 'from cache' : 'solving') . ')', static fn () => $store->steps($puzzle));
kv('steps', count($walk));
kv('walkthrough cached', $store->hasSteps($puzzle) ? 'yes' : 'NO - writes are failing');

foreach ($store->warnings() as $w) {
    out('  !! ' . $w);
}

$ly = step('build LilyPond source', static fn () => StaffRenderer::lilypond($puzzle));
kv('source length', number_format((float) strlen($ly)) . ' bytes');

$render = step('engrave to SVG', static fn () => StaffRenderer::render($ly, $cache, 'svg'));

heading('Engraving result');
kv('ok', $render['ok']);
kv('cached', $render['cached']);
kv('output path', $render['path'] ?? '(none)');
kv('output bytes', $render['content'] !== null ? number_format((float) strlen($render['content'])) : '0');
out('  command');
out('    ' . ($render['command'] ?: '(not run)'));

if ($render['warnings']) {
    out('  warnings');
    foreach ($render['warnings'] as $w) {
        out('    ' . $w);
    }
}

out('  lilypond output');
foreach (explode("\n", trim($render['log'])) as $line) {
    out('    ' . $line);
}

if ($render['ok'] && $render['content'] !== null) {
    heading('SVG head');
    out(substr($render['content'], 0, 600));
}

// --------------------------------------------------------------------- pdf

heading('PDF composition');
$pdfStatus = PdfSupport::status();
kv('TCPDF', $pdfStatus['tcpdf']);
kv('FPDI', $pdfStatus['fpdi']);
kv('ghostscript', $pdfStatus['ghostscript'] ?? 'NOT FOUND');
foreach ($pdfStatus['notes'] as $n) {
    out('    ' . $n);
}

$pageOk = false;
if (!$pdfStatus['ok']) {
    out('');
    foreach (explode("\n", PdfSupport::installHint()) as $line) {
        out('  ' . $line);
    }
    out('');
    out('  Ghostscript matters because the free FPDI cannot parse PDFs newer');
    out('  than 1.4; it is used to rewrite LilyPond output when needed.');
} else {
    $page = new PdfPage();
    kv('content width', sprintf('%.2f mm', $page->contentWidth()));

    $music = step('engrave at page width', static fn () => $page->engrave($puzzle, $cache, false, 20));
    if (!$music['ok']) {
        out('  lilypond output');
        foreach (explode("\n", trim($music['log'])) as $line) {
            out('    ' . $line);
        }
    } else {
        kv('music pdf', $music['path'] ?? '(none)');
        kv('music pdf version', PdfSupport::pdfVersion((string) $music['path']) ?? '?');
        $bytes = step('compose page', static fn () => $page->build($puzzle, (string) $music['path']));
        kv('page bytes', number_format((float) strlen($bytes)));
        kv('looks like a pdf', strncmp($bytes, '%PDF', 4) === 0);
        foreach ($page->notes() as $n) {
            out('    note: ' . $n);
        }
        $pageOk = strncmp($bytes, '%PDF', 4) === 0;
    }
}

out('');
if (!$render['ok']) {
    out('ENGRAVING FAILED - see the lilypond output above');
} elseif (!$pdfStatus['ok']) {
    out('SVG PIPELINE PASSED - PDF composition needs composer install');
} elseif (!$pageOk) {
    out('PDF COMPOSITION FAILED - see above');
} else {
    out('ALL STAGES PASSED');
}
out('');
