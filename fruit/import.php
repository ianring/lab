<?php
declare(strict_types=1);

/**
 * Loads forageable trees from the city inventories into the `foraging` table.
 *
 * Runs on i-a-n.ca, writes to MySQL on ianring.com.
 *
 *   php import.php                       dry run over every *-inventory.csv found
 *   php import.php --apply               load them
 *   php import.php --file=waterloo-inventory.csv --apply
 *   php import.php --set=nuts --apply
 *   php import.php --genus=Malus --apply
 *
 * The city is detected from the CSV header, and each city's quirks - UTM versus
 * degrees, inverted common names, a genus column or none - are handled in
 * lib/Source.php.
 *
 * Re-running is safe. Each row carries a marker in its notes, [kt:…] for
 * Kitchener and [wl:…] for Waterloo, and markers already in the table are
 * skipped. The prefixes matter: both cities number their OBJECTIDs from 1, so
 * an unprefixed marker would make one city's trees mask the other's.
 */

require __DIR__ . '/lib/RemoteDb.php';
require __DIR__ . '/lib/Utm.php';
require __DIR__ . '/lib/Source.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const TABLE = 'foraging';

const SETS = [
    'fruit' => ['Malus' => 'apples & crabapples', 'Prunus' => 'cherries & plums',
                'Pyrus' => 'pears', 'Morus' => 'mulberries',
                'Amelanchier' => 'serviceberries', 'Crataegus' => 'hawthorn',
                'Sambucus' => 'elderberry', 'Asimina' => 'pawpaw'],
    'nuts'  => ['Juglans' => 'walnut & butternut', 'Carya' => 'hickory',
                'Corylus' => 'hazelnut', 'Castanea' => 'chestnut',
                'Ginkgo' => 'ginkgo nuts', 'Quercus' => 'acorns'],
    'other' => ['Tilia' => 'linden leaves', 'Celtis' => 'hackberry',
                'Rhus' => 'sumac', 'Gleditsia' => 'honey locust pods'],
];

// ------------------------------------------------------------------ options

$opt = ['file' => '', 'set' => 'fruit', 'genus' => '', 'apply' => false,
        'truncate' => false, 'batch' => 200, 'anystatus' => false];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m) || !array_key_exists($m[1], $opt)) {
        exit("unknown argument $a\n");
    }
    $opt[$m[1]] = isset($m[2]) ? ($m[1] === 'batch' ? (int) $m[2] : $m[2]) : true;
}

if ($opt['genus'] !== '') {
    $wanted = [$opt['genus'] => $opt['genus']];
} elseif ($opt['set'] === 'all') {
    $wanted = array_merge(SETS['fruit'], SETS['nuts'], SETS['other']);
} elseif (isset(SETS[$opt['set']])) {
    $wanted = SETS[$opt['set']];
} else {
    exit("unknown --set={$opt['set']}; try fruit, nuts, other, all\n");
}

$files = $opt['file'] !== ''
    ? [(string) $opt['file']]
    : (glob(__DIR__ . '/*-inventory.csv') ?: []);
if (!$files) {
    exit("no *-inventory.csv found; pass --file=\n");
}

echo 'genera : ' . implode(', ', array_keys($wanted)) . "\n";
echo 'files  : ' . implode(', ', array_map('basename', $files)) . "\n";

$db = RemoteDb::connect(__DIR__);
echo 'database: ' . $db->describe . "\n\n";

// -------------------------------------------------- check the table is usable

$cols = $db->columns(TABLE);
if (!$cols) {
    exit('table `' . TABLE . "` not found\n");
}
['lat' => $latCol, 'lng' => $lngCol] = RemoteDb::coordColumns($cols);
if ($latCol === null || $lngCol === null) {
    exit("no coordinate columns found\n");
}
foreach ([$latCol, $lngCol] as $c) {
    $why = RemoteDb::coordinateProblem($cols[$c]);
    if ($why !== null) {
        exit("`$c`: $why.\nFix it first:  php foraging.php --migrate --apply\n");
    }
}

// markers already present, so a re-run tops up instead of duplicating
$seen = [];
foreach ($db->rows(
    'SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(notes, \'[\', -1), \']\', 1) AS marker
       FROM `' . TABLE . '` WHERE notes LIKE \'%[%:%]%\''
) as $r) {
    $seen[(string) $r['marker']] = true;
}
printf("already imported: %d\n\n", count($seen));

// ------------------------------------------------------------------- read

$rows = [];
$tally = [];

foreach ($files as $path) {
    if (!is_readable($path)) {
        echo basename($path) . ": cannot read, skipped\n";
        continue;
    }
    $fh = fopen($path, 'r');
    $head = fgetcsv($fh);
    if ($head === false) {
        fclose($fh);
        continue;
    }
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
    $head    = array_map('trim', $head);

    $key = Source::detect($head);
    if ($key === null) {
        echo basename($path) . ": header not recognised, skipped\n";
        echo '  columns: ' . implode(', ', $head) . "\n";
        fclose($fh);
        continue;
    }
    $src = Source::get($key);
    $ix  = array_flip($head);

    $stat = ['read' => 0, 'genus' => 0, 'status' => 0, 'seen' => 0, 'coords' => 0, 'take' => 0];
    $byGenus = [];

    while (($r = fgetcsv($fh)) !== false) {
        $stat['read']++;
        $get = static function (string $k) use ($r, $ix): string {
            return isset($ix[$k], $r[$ix[$k]]) ? trim((string) $r[$ix[$k]]) : '';
        };

        if (!$opt['anystatus'] && !in_array($get($src['statusCol']), $src['alive'], true)) {
            $stat['status']++;
            continue;
        }

        $m = ($src['map'])($get);
        if (!$m) {
            $stat['coords']++;
            continue;
        }
        if (!isset($wanted[$m['genus']])) {
            $stat['genus']++;
            continue;
        }
        if (!Utm::plausible($m['lat'], $m['lng'])) {
            $stat['coords']++;
            continue;
        }

        $marker = $src['prefix'] . ':' . $m['id'];
        if (isset($seen[$marker])) {
            $stat['seen']++;
            continue;
        }
        $seen[$marker] = true;      // also guards duplicates within one run

        $bits   = $m['bits'];
        $bits[] = '[' . $marker . ']';

        $rows[] = [
            $m['name'] !== '' ? mb_substr($m['name'], 0, 200) : null,
            mb_substr(Source::tidyName($m['species']), 0, 200) ?: null,
            $m['lat'],
            $m['lng'],
            mb_substr(implode('. ', $bits), 0, 2000),
        ];
        $stat['take']++;
        $byGenus[$m['genus']] = ($byGenus[$m['genus']] ?? 0) + 1;
    }
    fclose($fh);

    $tally[$src['label']] = [$stat, $byGenus];
}

foreach ($tally as $label => [$stat, $byGenus]) {
    printf("%s: %d rows read\n", $label, $stat['read']);
    printf("   wrong status  %7d\n", $stat['status']);
    printf("   wrong genus   %7d\n", $stat['genus']);
    printf("   already there %7d\n", $stat['seen']);
    printf("   bad coords    %7d\n", $stat['coords']);
    printf("   to import     %7d\n", $stat['take']);
    ksort($byGenus);
    foreach ($byGenus as $g => $n) {
        printf("      %-14s %5d   %s\n", $g, $n, $wanted[$g] ?? '');
    }
    echo "\n";
}

printf("total to import: %d\n\n", count($rows));

if ($rows) {
    echo "sample:\n";
    foreach (array_slice($rows, 0, 3) as $s) {
        printf("  %-26s %-22s %.5f, %.5f\n     %s\n",
            (string) $s[0], (string) $s[1], $s[2], $s[3], (string) $s[4]);
    }
    echo "\n";
}

if (!$opt['apply']) {
    exit("dry run. re-run with --apply to write.\n");
}

// ------------------------------------------------------------------ write

if ($opt['truncate']) {
    $db->exec('TRUNCATE TABLE `' . TABLE . '`');
    echo "table truncated\n";
}

$sql = 'INSERT INTO `' . TABLE . '` (name, species, `' . $latCol . '`, `' . $lngCol . '`, notes) VALUES ';
$written = 0;
$t0 = microtime(true);

$db->begin();
foreach (array_chunk($rows, max(1, (int) $opt['batch'])) as $chunk) {
    $args = [];
    foreach ($chunk as $row) {
        array_push($args, ...$row);
    }
    $db->run($sql . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)')), $args);
    $written += count($chunk);
    printf("\r  written %6d / %d", $written, count($rows));
}
$db->commit();

printf("\r  written %6d / %d\n\n", $written, count($rows));
printf("elapsed %.1fs\n", microtime(true) - $t0);
printf("table now holds %d rows\n", (int) $db->value('SELECT COUNT(*) FROM `' . TABLE . '`'));
