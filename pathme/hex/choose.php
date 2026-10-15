<?php
declare(strict_types=1);

/**
 * Records an in/out decision for one tile.
 *
 *   POST id=<tile id>&state=in|out|clear   -> {"ok":true,...}
 *   GET  ?selftest=1                       -> environment report, open it directly
 *
 * Everything answers HTTP 200, including failures. A 500 lets the web server
 * swap in its own error page and throw away the explanation, which is exactly
 * the "500 with no useful body" you just hit. Callers check ok, not the status.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

const STORE = __DIR__ . '/chosen.json';

/** Always answer with JSON, even when dying. */
function reply(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 200);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    reply([
        'ok'    => false,
        'error' => get_class($e) . ': ' . $e->getMessage(),
        'at'    => $e->getFile() . ':' . $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 200);
    }
    echo json_encode([
        'ok'    => false,
        'error' => 'Fatal: ' . $e['message'],
        'at'    => $e['file'] . ':' . $e['line'],
    ], JSON_PRETTY_PRINT);
});

// ------------------------------------------------------------------ storage

function load_choices(): array
{
    if (!is_file(STORE)) {
        return [];
    }
    $raw = @file_get_contents(STORE);
    if ($raw === false) {
        throw new RuntimeException('cannot read ' . STORE . ' - check permissions');
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function save_choices(array $data): array
{
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) {
        return ['ok' => false, 'why' => 'json_encode failed: ' . json_last_error_msg()];
    }

    $dir = dirname(STORE);
    if (!is_writable($dir) && !is_writable(STORE)) {
        return ['ok' => false, 'why' => "directory $dir is not writable by "
            . (function_exists('get_current_user') ? get_current_user() : 'the web user')];
    }

    $fh = @fopen(STORE, 'c+');
    if ($fh === false) {
        $err = error_get_last();
        return ['ok' => false, 'why' => 'cannot open ' . STORE . ': ' . ($err['message'] ?? 'unknown')];
    }

    $ok = false;
    if (flock($fh, LOCK_EX)) {          // a burst of clicks must not interleave
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, $json) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
    } else {
        fclose($fh);
        return ['ok' => false, 'why' => 'could not lock ' . STORE];
    }
    fclose($fh);

    return $ok ? ['ok' => true] : ['ok' => false, 'why' => 'write to ' . STORE . ' failed'];
}

// ----------------------------------------------------------------- selftest

if (isset($_GET['selftest'])) {
    $dir = dirname(STORE);
    reply([
        'ok'             => true,
        'php'            => PHP_VERSION,
        'sapi'           => PHP_SAPI,
        'user'           => function_exists('get_current_user') ? get_current_user() : '?',
        'store'          => STORE,
        'store_exists'   => is_file(STORE),
        'store_writable' => is_file(STORE) ? is_writable(STORE) : null,
        'dir_writable'   => is_writable($dir),
        'entries'        => count(load_choices()),
        'write_probe'    => save_choices(load_choices()),
    ]);
}

// -------------------------------------------------------------------- write

$id    = (string) ($_POST['id'] ?? '');
$state = (string) ($_POST['state'] ?? '');

if ($id === '' || !in_array($state, ['in', 'out', 'clear'], true)) {
    reply(['ok' => false, 'error' => 'need id and state=in|out|clear',
           'got' => ['id' => $id, 'state' => $state]]);
}

$data = load_choices();
if ($state === 'clear') {
    unset($data[$id]);
} else {
    $data[$id] = $state;
}

$saved = save_choices($data);
if (!$saved['ok']) {
    reply(['ok' => false, 'error' => $saved['why']]);
}

reply([
    'ok'     => true,
    'state'  => $state,
    'counts' => [
        'in'  => count(array_filter($data, static fn ($v): bool => $v === 'in')),
        'out' => count(array_filter($data, static fn ($v): bool => $v === 'out')),
    ],
]);
