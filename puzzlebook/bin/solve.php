#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\Solver;

$root = dirname(__DIR__);
$bookPath = $root . '/build/book.json';

if (!is_file($bookPath)) {
    fwrite(STDERR, "No {$bookPath} found -- run bin/build.php first.\n");
    exit(1);
}

$book = json_decode(file_get_contents($bookPath), true);
$chain = require $root . '/config/chain.php';

$result = Solver::run($book, $chain);

foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

echo "\n" . ($result['ok'] ? 'OK: the chain holds together end to end.' : 'FAILED: see mismatches above.') . "\n";

exit($result['ok'] ? 0 : 1);
