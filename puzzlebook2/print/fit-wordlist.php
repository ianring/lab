#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Filter the master word list down to words that TYPESET: everything in
 * data/wordlist.txt must fit one grid column at the print design's font
 * size, uppercase, so the builder can never choose a word the renderer
 * would have to reject. The master list is data/wordlist-full.txt and is
 * never modified; re-run this after any change to the trim, margins, grid
 * font, or column count.
 *
 *   php print/fit-wordlist.php
 */

require __DIR__ . '/lib/BookPdf.php';

use Puzzlebook2Print\BookPdf;

$root = dirname(__DIR__);
$full = $root . '/data/wordlist-full.txt';
$out = $root . '/data/wordlist.txt';

if (!is_file($full)) {
    fwrite(STDERR, "data/wordlist-full.txt not found -- the unfiltered master list must live there.\n");
    exit(2);
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$cols = (int) $layout['grid']['cols'];

$pdf = new BookPdf('measuring', $layout['print'] ?? []);
$limit = $pdf->columnLimitMm($cols);
$pdf->AddPage();
$pdf->useGridFont();

$kept = [];
$dropped = 0;
foreach (array_filter(array_map('trim', file($full))) as $word) {
    if ($pdf->GetStringWidth(strtoupper($word)) <= $limit) {
        $kept[] = $word;
    } else {
        $dropped++;
    }
}

file_put_contents($out, implode("\n", $kept) . "\n");
printf(
    "Kept %d words, dropped %d too wide for a %d-column grid at %.1fpt caps (limit %.2fmm).\nWrote %s\n",
    count($kept),
    $dropped,
    $cols,
    $pdf->gridFontPt(),
    $limit,
    $out,
);
