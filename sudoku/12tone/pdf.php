<?php
declare(strict_types=1);

/**
 * One puzzle composed onto a US Letter portrait PDF page.
 *
 *   pdf.php?difficulty=hard&seed=12345
 *   pdf.php?difficulty=hard&seed=12345&title=Tone+Row+No.+4&pageNumber=7
 *   pdf.php?...&variant=diatonic    chromatic (12x12) | diatonic (8x8)
 *   pdf.php?...&clef=bass           treble | alto | tenor | bass
 *   pdf.php?...&spelling=flats      sharps | flats | mixed
 *   pdf.php?...&clef=random         a different clef on every staff
 *   pdf.php?...&range=full          use the whole staff, +/-1 ledger line
 *   pdf.php?...&solved=1            the solution page
 *   pdf.php?...&download=1          as an attachment
 *
 * Layout parameters are listed in PageOptions::FIELDS. Use layout.php to fiddle
 * with them interactively.
 */

require __DIR__ . '/lib/errors.php';
require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';
require __DIR__ . '/lib/PuzzleStore.php';
require __DIR__ . '/lib/StaffRenderer.php';
require __DIR__ . '/lib/PdfSupport.php';
require __DIR__ . '/lib/PdfPage.php';
require __DIR__ . '/lib/PageOptions.php';
require __DIR__ . '/lib/PuzzleLink.php';

@set_time_limit(240);

const CACHE_DIR = __DIR__ . '/cache';

/** Plain text, HTTP 200, so nothing swallows the explanation. */
function bail(string $message): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 200);
    }
    echo $message . "\n";
    exit;
}

$variant    = Variant::get((string) ($_GET['variant'] ?? Variant::DEFAULT));
$difficulty = (string) ($_GET['difficulty'] ?? 'medium');
if (!isset(SudokuEngine::DIFFICULTIES[$difficulty])) {
    $difficulty = 'medium';
}
$seed      = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : null;
$solved    = !empty($_GET['solved']);
$staffSize = max(8, min(24, (int) ($_GET['size'] ?? StaffRenderer::DEFAULT_STAFF_SIZE)));
$clef      = StaffRenderer::clefName((string) ($_GET['clef'] ?? StaffRenderer::DEFAULT_CLEF));
$spelling  = isset(Variant::SPELLINGS[(string) ($_GET['spelling'] ?? '')]) ? (string) $_GET['spelling'] : 'sharps';
$rangeMode = StaffRenderer::rangeMode((string) ($_GET['range'] ?? StaffRenderer::DEFAULT_RANGE), $variant);

if (!PdfSupport::available()) {
    bail("TCPDF and FPDI are not installed.\n\n" . PdfSupport::installHint());
}

StaffRenderer::useCacheDir(CACHE_DIR);
$store  = new PuzzleStore(CACHE_DIR . '/puzzles');
$puzzle = $store->get($variant->key, $difficulty, $seed);

$page = new PdfPage(PageOptions::fromQuery($_GET));

$engraved = $page->engrave($puzzle, CACHE_DIR, $solved, $staffSize, $clef, $spelling, $rangeMode);
if (!$engraved['ok']) {
    bail("LilyPond could not engrave the score.\n\ncommand: {$engraved['command']}\n\n{$engraved['log']}");
}

// A PDF response must be clean binary. errors.php deliberately turns on
// display_errors, and TCPDF emits deprecation notices on newer PHP - printed
// into the response those would corrupt the file. So capture anything the
// build prints, keep it out of the PDF, and expose it under ?debug=1 rather
// than discarding it silently.
$prevLevel = error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();
try {
    $bytes = $page->build($puzzle, (string) $engraved['path']);
    $stray = (string) ob_get_clean();
} catch (\Throwable $e) {
    $stray = (string) ob_get_clean();
    error_reporting($prevLevel);
    bail($e->getMessage() . ($stray !== '' ? "\n\n--- output during composition ---\n" . $stray : ''));
}
error_reporting($prevLevel);

if (!empty($_GET['debug'])) {
    bail(
        'composition produced ' . strlen($bytes) . " bytes of PDF\n"
        . 'ghostscript: ' . (PdfSupport::ghostscript() ?? 'not found') . "\n"
        . 'music pdf version: ' . (PdfSupport::pdfVersion((string) $engraved['path']) ?? '?') . "\n"
        . ($page->notes() ? "\nnotes:\n  " . implode("\n  ", $page->notes()) . "\n" : '')
        . "\nstray output during composition:\n" . ($stray !== '' ? $stray : '(none)')
    );
}

$name = sprintf(
    '%s-%s-%d%s.pdf',
    $variant->key,
    $difficulty,
    (int) $puzzle->meta['seed'],
    $solved ? '-solution' : ''
);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($bytes));
header(
    'Content-Disposition: ' . (empty($_GET['download']) ? 'inline' : 'attachment')
    . '; filename="' . $name . '"'
);
echo $bytes;
