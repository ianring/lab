<?php

declare(strict_types=1);

/**
 * The puzzlebook step API: apply exactly one step of the chain to a
 * keep-mask and return what happened. This is the only place
 * web/index.html's JavaScript ever asks anything of -- the browser never
 * evaluates a rule itself, it only renders whatever this endpoint reports.
 * lib/Chain/Engine.php is the one implementation of what a rule does;
 * this file, bin/build.php, and ChainRunner all call it, so the rules
 * can't quietly mean something different depending on who's asking.
 *
 * Request: POST a JSON body {"step_index": <int>, "keep": <bool[]|null>}.
 * "keep" is the state BEFORE this step -- omit it (or send null) to start
 * from a fresh book. Response (eliminate steps):
 *   {keep, removed, before, after, total, pct_book, pct_remaining, final}
 *
 * Same request/response contract as v1 -- web/index.html needs no changes.
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

$bookPath = __DIR__ . '/book.json';
$chainPath = __DIR__ . '/chain.json';

if (!is_file($bookPath) || !is_file($chainPath)) {
    apiFail('book.json / chain.json not found next to api.php -- run bin/build.php first.', 500);
}

$book = json_decode((string) file_get_contents($bookPath), true);
$chain = json_decode((string) file_get_contents($chainPath), true);

if (!is_array($book) || !is_array($chain)) {
    apiFail('book.json or chain.json failed to parse.', 500);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    apiFail('Expected a JSON request body.');
}

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
