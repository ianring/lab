<?php
declare(strict_types=1);

/**
 * Small JSON endpoint for the bits of the firing record that should not cost a
 * page reload — currently the hourly temperature log.
 *
 * Every response is {"ok": bool, ...}. On failure "error" carries a sentence
 * worth showing the user; a write that fails says why, rather than looking
 * like nothing happened.
 *
 * The forms still post to firing.php when JavaScript is not running, so this
 * is an enhancement rather than the only way in.
 */

/* ------------------------------------------------------------------ */
/* Everything this endpoint says, it says in JSON — including its own  */
/* crashes. No need to go reading server logs.                         */
/* ------------------------------------------------------------------ */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');   // keep HTML error pages out of the JSON body

/** Have we already answered? Stops the shutdown check clobbering a good reply. */
function api_done(?bool $set = null): bool
{
    static $done = false;
    if ($set !== null) {
        $done = $set;
    }
    return $done;
}

/** Bail out with a JSON body, whatever half-written output is buffered. */
function api_fail(string $message, array $extra = [], int $status = 500): void
{
    api_done(true);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

/** A successful reply, flagged so the shutdown handler leaves it alone. */
function api_ok(array $payload): void
{
    api_done(true);
    json_out($payload);
}

set_exception_handler(static function ($e) {
    api_fail(get_class($e) . ': ' . $e->getMessage(), [
        'where' => basename($e->getFile()) . ' line ' . $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 6),
    ]);
});

// Warnings become exceptions so they surface; notices and deprecations are
// left alone so they cannot fail an otherwise good request.
set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    if ($severity & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

register_shutdown_function(static function () {
    if (api_done()) {
        return;
    }
    $last = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if ($last && in_array($last['type'], $fatal, true)) {
        api_fail('PHP fatal: ' . $last['message'], [
            'where' => basename($last['file']) . ' line ' . $last['line'],
        ]);
    }
});

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/cones.php';
require __DIR__ . '/lib/chart.php';
require __DIR__ . '/lib/auth.php';

// The API is as gated as the pages are — it just says so in JSON.
if (!current_user() && !(($_SERVER['REQUEST_METHOD'] === 'GET') && q('selftest') !== null)) {
    api_fail('You are signed out. Reload the page and sign in again.', ['signed_out' => true], 401);
}

/**
 * GET api.php?selftest=1 — open it in a browser to see what this endpoint
 * thinks of its own surroundings.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && q('selftest') !== null) {
    $needed = ['firing_get', 'firing_update', 'store_diagnose', 'store_last_error',
               'db', 'db_ready', 'parse_dt', 'fmt_clock', 'dt_input', 'e', 'json_out'];
    $missing = [];
    foreach ($needed as $fn) {
        if (!function_exists($fn)) {
            $missing[] = $fn;
        }
    }
    api_ok([
        'ok'            => $missing === [] && store_diagnose() === '',
        'php'           => PHP_VERSION,
        'timezone'      => date_default_timezone_get(),
        'db_configured' => db_config_loaded(),
        'db_connected'  => db() !== null,
        'db_host'       => db_config_loaded() ? DB_HOST : null,
        'db_name'       => db_config_loaded() ? DB_NAME : null,
        'db_ready'      => db_ready(),
        'db_error'      => db_error(),
        'firings'       => db_ready() ? (int)(db_value('SELECT COUNT(*) FROM firings') ?? 0) : null,
        'partial'       => is_file(__DIR__ . '/partials/temps-list.php'),
        'missing_functions' => $missing,
        'store_problem' => store_diagnose(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail('This endpoint takes POST requests. Add ?selftest=1 to check it in a browser.', [], 405);
}

$action = (string)p('action', '');
$id     = (int)p('id', 0);
$firing = firing_get($id);

if (!$firing) {
    api_fail('That firing record no longer exists.', [], 404);
}

/* ---------------- checklist ---------------- */

if ($action === 'check') {
    $item    = (string)p('item', '');
    $checked = p('checked') === '1';

    // Which visit each item belongs to, so the reply can count that visit
    // rather than the whole firing — the page only ever shows one visit.
    $itemVisits = [];
    foreach (checklist_for((string)$firing['kiln'], (string)$firing['type']) as $group) {
        foreach ($group['items'] as $listItem) {
            $itemVisits[$listItem['id']] = checklist_visit_of($group);
        }
    }
    if (!isset($itemVisits[$item])) {
        api_fail('Unknown checklist item: ' . $item, [], 400);
    }

    $checklist = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
    if ($checked) {
        $checklist[$item] = now()->format('c');
    } else {
        unset($checklist[$item]);
    }

    if (!firing_update($id, ['checklist' => $checklist])) {
        api_fail(store_last_error() ?: (store_diagnose() ?: 'The checklist could not be saved.'));
    }

    $visit = $itemVisits[$item];
    $done  = 0;
    $total = 0;
    foreach (checklist_for_visit((string)$firing['kiln'], $visit, (string)$firing['type']) as $group) {
        foreach ($group['items'] as $listItem) {
            $total++;
            $done += isset($checklist[$listItem['id']]) ? 1 : 0;
        }
    }

    api_ok(['ok' => true, 'visit' => $visit, 'done' => $done, 'total' => $total]);
}

/* ---------------- one field of the record ---------------- */

if ($action === 'save_field') {
    $name  = (string)p('name', '');
    $raw   = p('value');
    $value = is_scalar($raw) ? (string)$raw : '';
    $fields = [];

    // Sub-arrays have to be written whole, so start from what is stored.
    $actual = is_array($firing['actual'] ?? null) ? $firing['actual'] : [];

    switch ($name) {
        case 'cone':
            $fields['cone'] = trim($value);
            break;

        case 'controller_time':
            // Typed the way the kiln shows it: 12.30 is twelve and a half hours.
            $minutes = controller_time_parse($value);
            if ($value !== '' && $minutes === null) {
                api_fail('Type it the way the display shows it — 12.30 for twelve and a half hours.', [], 400);
            }
            $fields['controller_minutes'] = $minutes ?? 0;
            break;

        case 'hold_minutes':
            $fields['hold_minutes'] = max(0, (int)$value);
            break;

        case 'fired_by':
            // -1 is the "someone else" option: keep whatever name is stored and
            // wait for fired_by_name to arrive. A real id clears the name, so a
            // firing is never attributed to two people at once.
            $userId = (int)$value;
            if ($userId > 0 && !user_get($userId)) {
                api_fail('That is not anyone who has signed in.', [], 400);
            }
            $fields['fired_by'] = max(0, $userId);
            if ($userId > 0) {
                $fields['fired_by_name'] = '';
            }
            break;

        case 'fired_by_name':
            $fields['fired_by_name'] = trim($value);
            if (trim($value) !== '') {
                $fields['fired_by'] = 0;
            }
            break;

        case 'shelves':
        case 'maintenance':
        case 'notes':
            $fields[$name] = trim($value);
            break;

        case 'manual_stop':
            $actual['manual_stop'] = $value === '1';
            $fields['actual'] = $actual;
            break;

        case 'actual_start':
        case 'actual_end':
            $actual[$name === 'actual_start' ? 'start_at' : 'end_at'] = dt_store(parse_dt($value));
            $fields['actual'] = $actual;
            break;

        case 'cone_pos':
            $index = (int)p('index', -1);
            $pack  = cone_pack_for((string)($firing['cone'] ?? ''));
            if (!isset($pack[$index])) {
                api_fail('That cone is not in this pack — reload the page.', [], 409);
            }
            $recorded = is_array($firing['cone_pack'] ?? null) ? $firing['cone_pack'] : [];
            $rebuilt  = [];
            foreach ($pack as $slot => $entry) {
                $rebuilt[] = [
                    'cone' => $entry['cone'],
                    'role' => $entry['role'],
                    'pos'  => cone_position_clamp(
                        $slot === $index ? (int)$value : (int)($recorded[$slot]['pos'] ?? 0)
                    ),
                ];
            }
            $fields['cone_pack'] = $rebuilt;
            break;

        default:
            api_fail('That field cannot be saved this way: ' . $name, [], 400);
    }

    if (!firing_update($id, $fields)) {
        api_fail(store_last_error() ?: (store_diagnose() ?: 'That change could not be saved.'));
    }

    api_ok([
        'ok'    => true,
        'field' => $name,
        'at'    => ltrim(now()->format('g:i a'), '0'),
    ]);
}

/* ---------------- temperatures ---------------- */

$temps = is_array($firing['temps'] ?? null) ? $firing['temps'] : [];

$sort = static function (array $list): array {
    usort($list, static function ($a, $b) {
        return strcmp((string)($a['at'] ?? ''), (string)($b['at'] ?? ''));
    });
    return array_values($list);
};

switch ($action) {
    case 'temp_add':
        $value = p('temp_c');
        if ($value === null || trim((string)$value) === '') {
            api_fail('Enter a temperature first.', [], 400);
        }
        $at = parse_dt((string)p('temp_at', '')) ?? now();
        $temps[] = ['at' => $at->format('c'), 'c' => (float)$value];
        $temps = $sort($temps);
        break;

    case 'temp_update':
        $index = (int)p('index', -1);
        if (!isset($temps[$index])) {
            api_fail('That reading is no longer in the log — reload the page.', [], 409);
        }
        $value = p('temp_c');
        if ($value !== null && trim((string)$value) !== '') {
            $temps[$index]['c'] = (float)$value;
        }
        $at = parse_dt((string)p('temp_at', ''));
        if ($at) {
            $temps[$index]['at'] = $at->format('c');
        }
        $temps = $sort($temps);
        break;

    case 'temp_delete':
        $index = (int)p('index', -1);
        if (!isset($temps[$index])) {
            api_fail('That reading is no longer in the log — reload the page.', [], 409);
        }
        unset($temps[$index]);
        $temps = array_values($temps);
        break;

    default:
        api_fail('Unknown action: ' . $action, [], 400);
}

if (!firing_update($id, ['temps' => $temps])) {
    api_fail(store_last_error() ?: (store_diagnose() ?: 'The firing log could not be saved.'));
}

// Render from what is actually stored, not from what we hoped we stored.
$saved    = firing_get($id);
$temps    = is_array($saved['temps'] ?? null) ? $saved['temps'] : [];
$firingId = $id;

ob_start();
require __DIR__ . '/partials/temps-list.php';
$html = (string)ob_get_clean();

$chartCone = (string)($saved['cone'] ?? '');
ob_start();
require __DIR__ . '/partials/temps-chart.php';
$chart = (string)ob_get_clean();

api_ok(['ok' => true, 'html' => $html, 'chart' => $chart, 'count' => count($temps)]);
