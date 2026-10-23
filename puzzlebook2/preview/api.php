<?php

declare(strict_types=1);

/**
 * The puzzlebook step API for the SERIES preview. Serves any of the built
 * books in build/series/bookNN/ and applies one chain step at a time. The
 * browser never evaluates a rule itself -- it renders whatever this endpoint
 * reports, and lib/Chain/Engine.php is the one implementation of what a rule
 * does (shared with bin/build.php and ChainRunner), so the rules can't mean
 * something different depending on who's asking.
 *
 * Three request shapes:
 *   GET  ?list=1            -> {books: [1,2,...]}            which books are built
 *   GET  ?book=N            -> {book:{...}, chain:{...}}      that book's data
 *   POST {book:N, step_index, keep}                          apply one step
 *     "keep" is the state BEFORE this step -- omit/null to start fresh.
 *     Response (eliminate): {keep, removed, before, after, total, pct_book,
 *                            pct_remaining, final}
 *
 * With no book number it falls back to book.json/chain.json beside this file
 * (the old single-book preview), so a plain bin/build.php still previews.
 */

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\Engine;

header('Content-Type: application/json; charset=utf-8');

function apiFail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

$root = dirname(__DIR__);

// ---- list mode: which series books are built --------------------------------
if (isset($_GET['list'])) {
    $books = [];
    foreach (glob($root . '/build/series/book*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('#book0*(\d+)$#', $dir, $m) && is_file("{$dir}/book.json")) {
            $books[] = (int) $m[1];
        }
    }
    sort($books);
    echo json_encode(['books' => $books, 'legacy' => is_file(__DIR__ . '/book.json')]);
    exit;
}

// ---- resolve which book's data to use --------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = null;
if ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiFail('Expected a JSON request body.');
    }
}

$bookNo = null;
if (isset($_GET['book'])) {
    $bookNo = (int) $_GET['book'];
} elseif (is_array($input) && isset($input['book'])) {
    $bookNo = (int) $input['book'];
}

if ($bookNo !== null) {
    $dir = sprintf('%s/build/series/book%02d', $root, $bookNo);
    $bookPath = "{$dir}/book.json";
    $chainPath = "{$dir}/chain.json";
    if (!is_file($bookPath) || !is_file($chainPath)) {
        apiFail("No build for book {$bookNo} -- run bin/build-series.php first.", 404);
    }
} else {
    $bookPath = __DIR__ . '/book.json';
    $chainPath = __DIR__ . '/chain.json';
    if (!is_file($bookPath) || !is_file($chainPath)) {
        apiFail('No book selected and no book.json beside api.php -- run bin/build-series.php.', 404);
    }
}

$book = json_decode((string) file_get_contents($bookPath), true);
$chain = json_decode((string) file_get_contents($chainPath), true);
if (!is_array($book) || !is_array($chain)) {
    apiFail('book.json or chain.json failed to parse.', 500);
}

// ---- data mode: hand the browser a book + its chain ------------------------
if ($method === 'GET') {
    echo json_encode(['book' => $book, 'chain' => $chain]);
    exit;
}

// ---- step mode: apply exactly one step -------------------------------------
$steps = $chain['steps'];
$stepIndex = $input['step_index'] ?? null;
if (!is_int($stepIndex) || $stepIndex < 0 || $stepIndex >= count($steps)) {
    apiFail('Missing or out-of-range step_index.');
}

$words = $book['words'];
$total = count($words);
$spec = new BoardSpec($book['grid']['pages'], $book['grid']['rows'], $book['grid']['cols']);

$keepInput = $input['keep'] ?? null;
if ($keepInput === null) {
    $keepBefore = array_fill(0, $total, true);
} else {
    if (!is_array($keepInput) || count($keepInput) !== $total) {
        apiFail('keep must be a boolean array with one entry per word.');
    }
    $keepBefore = array_map(static fn ($v): bool => (bool) $v, array_values($keepInput));
}

$step = $steps[$stepIndex];
if (($step['kind'] ?? '') !== 'eliminate') {
    apiFail("Step kind '{$step['kind']}' is not supported by the v2 engine yet (reveals are out).");
}

$before = Engine::countSurvivors($keepBefore);
$keepAfter = Engine::applyRule($words, $spec, $keepBefore, $step['rule']);
$after = Engine::countSurvivors($keepAfter);

$removed = [];
foreach ($keepBefore as $i => $wasKept) {
    if ($wasKept && !$keepAfter[$i]) {
        $removed[] = $i;
    }
}

$response = [
    'step_index' => $stepIndex,
    'kind' => $step['kind'],
    'keep' => $keepAfter,
    'removed' => $removed,
    'before' => $before,
    'after' => $after,
    'total' => $total,
    'pct_book' => $total > 0 ? count($removed) / $total * 100 : 0.0,
    'pct_remaining' => $before > 0 ? count($removed) / $before * 100 : 0.0,
    'final' => null,
];

if (!empty($step['final'])) {
    $survivors = Engine::survivingIndices($keepAfter);
    $survivorWords = array_map(static fn (int $i): string => $words[$i], $survivors);
    $response['final'] = [
        'ok' => count($survivors) === 1 && $survivorWords[0] === $chain['answer'],
        'survivor_indices' => $survivors,
        'survivor_words' => $survivorWords,
        'expected_answer' => $chain['answer'],
    ];
}

echo json_encode($response);
