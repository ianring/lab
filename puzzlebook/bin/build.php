#!/usr/bin/env php
<?php

declare(strict_types=1);

// A full-size book is 12,000 cells laid out against a 133,000-word list, and the
// builder may try many arrangements -- more than PHP's usual 128M default allows.
// Raised here so this works out of the box rather than needing a php.ini change.
if ((int) ini_get('memory_limit') !== -1) {
    ini_set('memory_limit', '512M');
}

require __DIR__ . '/../lib/bootstrap.php';

use Puzzlebook\ChainBuilder;
use Puzzlebook\Engine;
use Puzzlebook\Grid;
use Puzzlebook\Solver;

$root = dirname(__DIR__);

$built = ChainBuilder::build(
    $root . '/config/layout.php',
    $root . '/config/chain.php',
    $root . '/data/wordlist.txt'
);
$chain = require $root . '/config/chain.php';

// How many arrangements the builder had to try is useful to see (a number much
// above 1 means the constraints are tight and small rule changes may stop
// fitting), but it isn't part of the book, so it doesn't go into book.json.
$attempts = $built['attempts'];
$book = ['grid' => $built['grid'], 'words' => $built['words']];

if (!is_dir($root . '/build')) {
    mkdir($root . '/build', 0777, true);
}

// web/api.php (and this script's own report, below) need a plain-English
// description of each reveal step's pattern. Engine::describeReveal() is a
// pure function of the step's own data, so it's computed once, here, and
// baked into chain.json as 'pattern_text' -- the browser never needs to
// call it itself, it just displays the string.
$chainForExport = $chain;
foreach ($chainForExport['steps'] as &$step) {
    if ($step['kind'] === 'reveal') {
        $step['pattern_text'] = Engine::describeReveal($step['reveal']);
    }
}
unset($step);

$bookJson = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$chainJson = json_encode($chainForExport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$jsonPath = $root . '/build/book.json';
file_put_contents($jsonPath, $bookJson);

$chainPath = $root . '/build/chain.json';
file_put_contents($chainPath, $chainJson);

$pagesPath = $root . '/build/pages.txt';
file_put_contents($pagesPath, renderPages($book));

// web/index.html is a static page that fetches these two files over plain
// HTTP -- it doesn't execute anything server-side, so the book it previews
// is this exact build, not a separate copy of the logic. Writing them
// straight into web/ means the whole web/ folder is one self-contained
// thing to copy to your server.
if (!is_dir($root . '/web')) {
    mkdir($root . '/web', 0777, true);
}
file_put_contents($root . '/web/book.json', $bookJson);
file_put_contents($root . '/web/chain.json', $chainJson);

$total = $book['grid']['pages'] * $book['grid']['rows'] * $book['grid']['cols'];
echo "Built a {$book['grid']['pages']}-page book ({$total} words) and verified the full chain end to end.\n";
echo $attempts === 1
    ? "Found a working arrangement on the first try.\n"
    : "Took {$attempts} arrangements to find one satisfying every constraint.\n";
echo "Wrote {$jsonPath}\n";
echo "Wrote {$chainPath}\n";
echo "Wrote {$pagesPath} (a plain-text proof of every page, for reading over)\n";
echo "Copied book.json and chain.json into web/ for the preview page to fetch\n\n";

// ChainBuilder::verify() already confirmed this holds together; re-running it
// through Solver here just prints the same walk stage by stage, since that's
// the only place this report is written.
$result = Solver::run($book, $chain);
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

/** @param array{grid: array{pages:int,rows:int,cols:int}, words: string[]} $book */
function renderPages(array $book): string
{
    $grid = new Grid($book['grid']['pages'], $book['grid']['rows'], $book['grid']['cols']);
    $words = $book['words'];
    $colWidth = 8 + max(array_map('strlen', $words));

    $out = '';
    for ($page = 0; $page < $grid->pages; $page++) {
        $out .= '=== Page ' . ($page + 1) . " ===\n";
        for ($row = 0; $row < $grid->rows; $row++) {
            $cells = [];
            for ($col = 0; $col < $grid->cols; $col++) {
                $i = $grid->linearIndex($page, $row, $col);
                $cells[] = str_pad(($i + 1) . ':' . $words[$i], $colWidth);
            }
            $out .= rtrim(implode('', $cells)) . "\n";
        }
        $out .= "\n";
    }
    return $out;
}
