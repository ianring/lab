#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render the built series to print-ready PDFs: book01.pdf .. bookNN.pdf in a
 * FINISHED/ folder at the repo root, from the real books in build/series/.
 *
 *   php bin/build-series.php          # build the books first
 *   php print/render-series.php       # then render them
 *   php print/render-series.php --out=/some/dir
 *
 * Each book is laid out by SeriesBookPdf with the full series design (title +
 * book number, real rules and grid, the two pages of honour with a real answer
 * fingerprint, the per-book Mystery procedure, the spoiler-guarded real counts,
 * and the Capstone's address/claim pages). Run sh print/install-libs.sh once
 * first to vendor FPDF.
 */

require __DIR__ . '/lib/SeriesBookPdf.php';

use Puzzlebook2Print\SeriesBookPdf;

$root = dirname(__DIR__);
$outDir = $root . '/FINISHED';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outDir = $m[1];
    }
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$series = json_decode((string) file_get_contents($root . '/config/series.json'), true);
if (!is_array($layout) || !is_array($series)) {
    fwrite(STDERR, "config/layout.json or config/series.json failed to parse.\n");
    exit(2);
}
$print = $layout['print'] ?? [];
$total = (int) ($series['total_books'] ?? count($series['books']));

// The Mystery map (from the build) supplies each book's Mystery word, so its
// real fingerprint can be printed beside the Mystery box.
$plan = json_decode((string) @file_get_contents($root . '/build/series/plan.json'), true);
$mysteries = is_array($plan) ? ($plan['mysteries'] ?? []) : [];
$address = is_array($plan) ? [
    'acrostic_page' => $plan['acrostic_page'] ?? null,
    'acrostic_start_col' => $plan['acrostic_start_col'] ?? 2,
    'places' => $plan['places'] ?? [],
    'box_number' => $plan['box_number'] ?? null,
    'box_find' => $plan['box_find'] ?? null,
] : [];

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "could not create output dir {$outDir}\n");
    exit(2);
}

$rendered = 0;
$missing = [];
foreach ($series['books'] as $bookCfg) {
    $n = (int) $bookCfg['n'];
    $dir = sprintf('%s/build/series/book%02d', $root, $n);
    $bookPath = "{$dir}/book.json";
    $chainPath = "{$dir}/chain.json";
    if (!is_file($bookPath) || !is_file($chainPath)) {
        $missing[] = $n;
        continue;
    }
    $book = json_decode((string) file_get_contents($bookPath), true);
    $chain = json_decode((string) file_get_contents($chainPath), true);
    if (!is_array($book) || !is_array($chain)) {
        fwrite(STDERR, "book{$n}: book.json/chain.json failed to parse -- skipping.\n");
        $missing[] = $n;
        continue;
    }

    $mysteryWord = $mysteries[$n] ?? $mysteries[(string) $n] ?? null;
    $pdf = new SeriesBookPdf($n, $total, $book, $chain, $print, $mysteryWord, $address);
    $pdf->render();
    $path = sprintf('%s/book%02d.pdf', $outDir, $n);
    $pdf->Output('F', $path);
    $rendered++;
    printf("book%02d.pdf  %3d pages%s\n", $n, $pdf->PageNo(),
        $n === (int) ($series['capstone_book'] ?? 0) ? '  (Capstone)' : '');
}

if ($missing !== []) {
    fwrite(STDERR, "\nNo build for book(s): " . implode(', ', $missing)
        . " -- run `php bin/build-series.php` first.\n");
}
printf("\nRendered %d book(s) to %s at %.5g x %.5g mm.\n",
    $rendered, $outDir, $print['trim_width_mm'] ?? 139.7, $print['trim_height_mm'] ?? 215.9);
exit($missing === [] ? 0 : 1);
