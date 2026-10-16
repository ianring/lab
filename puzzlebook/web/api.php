<?php

declare(strict_types=1);

/**
 * The puzzlebook step API: apply exactly one step of the chain to a
 * keep-mask and return what happened. This is the only place
 * web/index.html's JavaScript ever asks anything of -- the browser never
 * evaluates a rule itself, it only renders whatever this endpoint reports.
 * lib/Engine.php is the one implementation of what a rule or a reveal does;
 * this file, bin/build.php, and bin/solve.php all call it, so the rules
 * can't quietly mean something different depending on who's asking.
 *
 * Request: POST a JSON body {"step_index": <int>, "keep": <bool[]|null>}.
 * "keep" is the state BEFORE this step -- omit it (or send null) to start
 * from a fresh book, everything still in play. The response shape depends
 * on the step's kind:
 *
 *   eliminate: {keep, removed, before, after, total, pct_book,
 *               pct_remaining, final}
 *   reveal:    {pattern, text, cells, matches_expected, expected, error}
 */

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\Engine;
use Puzzlebook\Grid;

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
$grid = new Grid($book['grid']['pages'], $book['grid']['rows'], $book['grid']['cols']);

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
$response = ['step_index' => $stepIndex, 'kind' => $step['kind']];

if ($step['kind'] === 'eliminate') {
    $before = Engine::countSurvivors($keepBefore);
    $keepAfter = Engine::applyRule($words, $grid, $keepBefore, $step['rule']);
    $after = Engine::countSurvivors($keepAfter);

    $removed = [];
    foreach ($keepBefore as $i => $wasKept) {
        if ($wasKept && !$keepAfter[$i]) {
            $removed[] = $i;
        }
    }

    $response['keep'] = $keepAfter;
    $response['removed'] = $removed;
    $response['before'] = $before;
    $response['after'] = $after;
    $response['total'] = $total;
    $response['pct_book'] = $total > 0 ? count($removed) / $total * 100 : 0.0;
    $response['pct_remaining'] = $before > 0 ? count($removed) / $before * 100 : 0.0;

    $response['final'] = null;
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
} else {
    // $step['kind'] === 'reveal'
    $response['pattern'] = Engine::describeReveal($step['reveal']);
    try {
        $result = Engine::computeReveal($words, $grid, $keepBefore, $step['reveal']);
        $response['text'] = $result['text'];
        $response['cells'] = $result['cells'];
        $response['error'] = null;
        $response['matches_expected'] = $result['text'] === $step['expected'];
    } catch (\RuntimeException $e) {
        $response['text'] = null;
        $response['cells'] = [];
        $response['error'] = $e->getMessage();
        $response['matches_expected'] = false;
    }
    $response['expected'] = $step['expected'];
}

echo json_encode($response);
