<?php
declare(strict_types=1);

/**
 * Foraging table utility.
 *
 * Runs on i-a-n.ca and connects OUT to MySQL on ianring.com. No PHP executes on
 * ianring.com; only SQL crosses. Connection settings live in foraging-db.php -
 * see lib/RemoteDb.php, which also explains the SSH tunnel approach.
 *
 *   php foraging.php --test              prove the connection, then stop
 *   php foraging.php                     inspect the table, report problems
 *   php foraging.php --migrate           show the SQL that would fix the schema
 *   php foraging.php --migrate --apply   run it
 *   php foraging.php --list [--limit=N]  show rows
 *   php foraging.php --export=out.csv    dump to CSV
 *   php foraging.php --import=in.csv --apply
 *
 * Nothing is written without --apply.
 */

require __DIR__ . '/lib/RemoteDb.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const TABLE = 'foraging';

$opt = ['migrate' => false, 'apply' => false, 'list' => false, 'limit' => 25,
        'export' => '', 'import' => '', 'test' => false];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m) || !array_key_exists($m[1], $opt)) {
        exit("unknown argument $a\n");
    }
    $opt[$m[1]] = isset($m[2]) ? ($m[1] === 'limit' ? (int) $m[2] : $m[2]) : true;
}

try {
    $db = RemoteDb::connect(__DIR__);
} catch (Throwable $e) {
    exit($e->getMessage() . "\n");
}

$info = $db->rows('SELECT DATABASE() AS d, VERSION() AS v, @@hostname AS h')[0] ?? [];
echo 'connected to : ' . $db->describe . "\n";
echo "database     : {$info['d']} on {$info['h']} (MySQL {$info['v']})\n";
if (!$db->tunnelled) {
    echo "  warning: not a local connection; credentials cross the network.\n";
}
echo "\n";

if ($opt['test']) {
    echo "connection works.\n";
    exit;
}

// ------------------------------------------------------------------ inspect

$cols = $db->columns(TABLE);
if (!$cols) {
    exit('table `' . TABLE . "` not found in {$info['d']}\n");
}
$rows = (int) $db->value('SELECT COUNT(*) FROM `' . TABLE . '`');

echo "columns:\n";
foreach ($cols as $name => $c) {
    printf("  %-12s %s\n", $name, $c['type']);
}
echo "\nrows: $rows\n\n";

['lat' => $latCol, 'lng' => $lngCol] = RemoteDb::coordColumns($cols);

$problems = [];
if ($latCol === null || $lngCol === null) {
    $problems[] = 'could not find a coordinate pair; looked for lat/latitude and '
        . 'lng/lon/long/longitude.';
}
foreach (array_filter([$latCol, $lngCol]) as $c) {
    $why = RemoteDb::coordinateProblem($cols[$c]);
    if ($why !== null) {
        $problems[] = "`$c`: $why. Use DECIMAL(10,7).";
    }
}
if ($lngCol === 'long') {
    $problems[] = '`long` is a reserved word in MySQL, so every query needs backticks.';
}

if ($problems) {
    echo "problems:\n";
    foreach ($problems as $p) {
        echo "  ! $p\n";
    }
    if ($rows > 0) {
        echo "\n  NOTE: $rows row(s) already stored. Coordinates written while the columns\n"
           . "  were integers were truncated on the way in; widening the column cannot\n"
           . "  recover the lost decimals. Those points need re-entering.\n";
    }
    echo "\n";
} else {
    echo "no problems found\n\n";
}

// ------------------------------------------------------------------ migrate

if ($opt['migrate']) {
    // DECIMAL(10,7): three digits before the point for +/-180, seven after,
    // which is about a centimetre - finer than any phone GPS.
    $parts = [];
    foreach (array_filter([$latCol, $lngCol]) as $c) {
        if (!RemoteDb::holdsCoordinate($cols[$c])) {
            $parts[] = $c === 'long'
                ? 'CHANGE `long` `lng` DECIMAL(10,7) NULL'   // also drops the reserved word
                : "MODIFY `$c` DECIMAL(10,7) NULL";
        }
    }
    if ($lngCol === 'long' && !in_array('CHANGE `long` `lng` DECIMAL(10,7) NULL', $parts, true)) {
        $parts[] = 'CHANGE `long` `lng` DECIMAL(10,7) NULL';
    }
    if (!$parts) {
        exit("nothing to migrate\n");
    }

    $sql = 'ALTER TABLE `' . TABLE . "`\n  " . implode(",\n  ", $parts);
    echo "migration:\n\n$sql;\n\n";
    if (!$opt['apply']) {
        exit("dry run. re-run with --apply to execute.\n");
    }

    $db->exec($sql);
    echo "applied. columns are now:\n";
    foreach ($db->columns(TABLE) as $name => $c) {
        printf("  %-12s %s\n", $name, $c['type']);
    }
    exit;
}

$lng = $lngCol ?? 'lng';
$lat = $latCol ?? 'lat';

// --------------------------------------------------------------------- list

if ($opt['list']) {
    $out = $db->rows('SELECT id, name, species, `' . $lat . '` AS lat, `' . $lng . '` AS lng, date
                        FROM `' . TABLE . '` ORDER BY id DESC LIMIT ' . max(1, (int) $opt['limit']));
    if (!$out) {
        exit("no rows\n");
    }
    printf("%-6s %-26s %-22s %-12s %-12s %s\n", 'id', 'name', 'species', 'lat', 'lng', 'date');
    foreach ($out as $r) {
        printf("%-6s %-26s %-22s %-12s %-12s %s\n", $r['id'],
            mb_substr((string) $r['name'], 0, 26), mb_substr((string) $r['species'], 0, 22),
            (string) $r['lat'], (string) $r['lng'], (string) $r['date']);
    }
    exit;
}

// ------------------------------------------------------------ export/import

if (is_string($opt['export']) && $opt['export'] !== '') {
    $fh = fopen($opt['export'], 'w');
    fputcsv($fh, ['id', 'name', 'species', 'lat', 'lng', 'notes', 'date']);
    $n = 0;
    foreach ($db->rows('SELECT id, name, species, `' . $lat . '` AS lat, `' . $lng . '` AS lng, notes, date
                          FROM `' . TABLE . '` ORDER BY id') as $r) {
        fputcsv($fh, $r);
        $n++;
    }
    fclose($fh);
    exit("exported $n row(s) to {$opt['export']}\n");
}

if (is_string($opt['import']) && $opt['import'] !== '') {
    if (!is_readable($opt['import'])) {
        exit("cannot read {$opt['import']}\n");
    }
    $fh   = fopen($opt['import'], 'r');
    $ix   = array_flip(array_map('trim', (array) fgetcsv($fh)));
    $todo = [];
    while (($r = fgetcsv($fh)) !== false) {
        $g = static fn (string $k) => isset($ix[$k], $r[$ix[$k]]) && $r[$ix[$k]] !== '' ? $r[$ix[$k]] : null;
        $todo[] = [$g('name'), $g('species'), $g('lat'), $g('lng'), $g('notes'), $g('date')];
    }
    fclose($fh);

    echo count($todo) . " row(s) in {$opt['import']}\n";
    if (!$opt['apply']) {
        exit("dry run. re-run with --apply to write.\n");
    }
    $db->begin();
    foreach ($todo as $t) {
        $db->run('INSERT INTO `' . TABLE . '` (name, species, `' . $lat . '`, `' . $lng . '`, notes, date)
                    VALUES (?,?,?,?,?,?)', $t);
    }
    $db->commit();
    exit('imported ' . count($todo) . " row(s)\n");
}

echo "run with --migrate, --list, --export=file.csv or --import=file.csv\n";
