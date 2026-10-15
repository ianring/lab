<?php
declare(strict_types=1);

/**
 * JSON for the map. Reads the `foraging` table.
 *
 *   api.php?facets=1                     the species list, with counts
 *   api.php?species=genus:Malus          every apple and crabapple
 *   api.php?species=Black Cherry         one species
 *   api.php?species=A,B                  several
 *   api.php?...&n=&s=&e=&w=              narrowed to a viewport
 *
 * The table has no genus column - genus is only implicit in the latin name that
 * begins each notes field. So `genus:X` matches on that prefix, which lets one
 * select box offer both groupings and individual species.
 *
 * Always answers HTTP 200; failures come back as ok:false so the UI can show
 * them instead of leaving a blank map.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/lib/RemoteDb.php';

const TABLE    = 'foraging';
const MAX_ROWS = 4000;

function reply(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 200);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    reply(['ok' => false, 'error' => $e->getMessage()]);
});

try {
    $db = RemoteDb::connect(__DIR__);
} catch (Throwable $e) {
    reply(['ok' => false, 'error' => $e->getMessage()
        . "  (if this works from the shell but not the browser, the web user probably"
        . " cannot read /root/dbconn.php - put credentials in foraging-db.php instead)"]);
}

$cols = $db->columns(TABLE);
if (!$cols) {
    reply(['ok' => false, 'error' => 'table `' . TABLE . '` not found']);
}
['lat' => $latCol, 'lng' => $lngCol] = RemoteDb::coordColumns($cols);
if ($latCol === null || $lngCol === null) {
    reply(['ok' => false, 'error' => 'no coordinate columns found on `' . TABLE . '`']);
}
$LAT = '`' . $latCol . '`';
$LNG = '`' . $lngCol . '`';

$rowCount = (int) $db->value('SELECT COUNT(*) FROM `' . TABLE . '`');
if ($rowCount === 0) {
    reply(['ok' => false, 'error' => 'the foraging table is empty - run: php import.php --apply']);
}

// ------------------------------------------------------------------- facets

if (isset($_GET['facets'])) {
    $species = $db->rows(
        'SELECT species, SUBSTRING_INDEX(TRIM(notes), \' \', 1) AS genus, COUNT(*) AS n
           FROM `' . TABLE . '`
          WHERE species IS NOT NULL AND species <> \'\'
          GROUP BY species, genus
          ORDER BY species'
    );

    // fold the per-genus totals, ignoring anything that is not a latin name
    $groups = [];
    $flat   = [];
    foreach ($species as $r) {
        $g = rtrim((string) $r['genus'], '.,');
        $isGenus = (bool) preg_match('/^[A-Z][a-z]{2,}$/', $g);
        $flat[$r['species']] = ($flat[$r['species']] ?? 0) + (int) $r['n'];
        if ($isGenus) {
            $groups[$g] = ($groups[$g] ?? 0) + (int) $r['n'];
        }
    }
    ksort($flat);
    arsort($groups);

    reply([
        'ok'      => true,
        'total'   => $rowCount,
        'groups'  => array_map(
            static fn ($g, $n): array => ['genus' => $g, 'n' => $n],
            array_keys($groups),
            $groups
        ),
        'species' => array_map(
            static fn ($s, $n): array => ['species' => $s, 'n' => $n],
            array_keys($flat),
            $flat
        ),
    ]);
}

// -------------------------------------------------------------------- query

$where  = [$LAT . ' IS NOT NULL AND ' . $LNG . ' IS NOT NULL'];
$params = [];

$sel = trim((string) ($_GET['species'] ?? ''));
if ($sel !== '') {
    if (str_starts_with($sel, 'genus:')) {
        $where[]  = 'notes LIKE ?';
        $params[] = substr($sel, 6) . ' %';
    } else {
        $names = array_values(array_filter(array_map('trim', explode(',', $sel))));
        if ($names) {
            $where[]  = 'species IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            $params   = array_merge($params, $names);
        }
    }
}

$total = (int) $db->value('SELECT COUNT(*) FROM `' . TABLE . '` WHERE ' . implode(' AND ', $where), $params);

$bounds = $db->rows(
    'SELECT MIN(' . $LAT . ') AS s, MAX(' . $LAT . ') AS n,
            MIN(' . $LNG . ') AS w, MAX(' . $LNG . ') AS e
       FROM `' . TABLE . '` WHERE ' . implode(' AND ', $where),
    $params
)[0] ?? null;

if (isset($_GET['n'], $_GET['s'], $_GET['e'], $_GET['w'])) {
    $where[]  = $LAT . ' BETWEEN ? AND ? AND ' . $LNG . ' BETWEEN ? AND ?';
    $params[] = (float) $_GET['s'];
    $params[] = (float) $_GET['n'];
    $params[] = (float) $_GET['w'];
    $params[] = (float) $_GET['e'];
}

$limit = max(1, min(MAX_ROWS, (int) ($_GET['limit'] ?? MAX_ROWS)));

$trees = $db->rows(
    'SELECT id, name, species, notes, date, ' . $LAT . ' AS lat, ' . $LNG . ' AS lng,
            CASE WHEN notes LIKE \'%[kt:%\' THEN \'Kitchener\'
                 WHEN notes LIKE \'%[wl:%\' THEN \'Waterloo\'
                 ELSE NULL END AS source
       FROM `' . TABLE . '`
      WHERE ' . implode(' AND ', $where) . '
      LIMIT ' . $limit,
    $params
);

reply([
    'ok'     => true,
    'total'  => $total,
    'shown'  => count($trees),
    'capped' => count($trees) >= $limit,
    'bounds' => $bounds,
    'trees'  => $trees,
]);
