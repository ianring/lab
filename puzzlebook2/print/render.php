#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render the built book as a print-ready PDF at mass-market paperback trim
 * (5.25" x 7" / 133.35 x 177.8 mm): front matter and the numbered rules
 * first, then one PDF page per book page of words.
 *
 *   php print/render.php                 # build/book.json -> build/book.pdf
 *   php print/render.php --title="..."   # override the title page text
 *   php print/render.php --out=/path.pdf
 *
 * Run sh print/install-libs.sh once first (vendors FPDF into print/libs/).
 */

require __DIR__ . '/lib/BookPdf.php';

use Puzzlebook2Print\BookPdf;

$root = dirname(__DIR__);
$bookPath = $root . '/build/book.json';
$chainPath = $root . '/build/chain.json';
$outPath = $root . '/build/book.pdf';
$title = 'Eliminoid';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outPath = $m[1];
    } elseif (preg_match('/^--title=(.+)$/', $arg, $m)) {
        $title = $m[1];
    } elseif (preg_match('/^--book=(.+)$/', $arg, $m)) {
        $bookPath = $m[1];
    } elseif (preg_match('/^--chain=(.+)$/', $arg, $m)) {
        $chainPath = $m[1];
    }
}

if (!is_file($bookPath) || !is_file($chainPath)) {
    fwrite(STDERR, "book.json / chain.json not found (looked in {$bookPath}) -- run bin/build.php first.\n");
    exit(2);
}

$book = json_decode((string) file_get_contents($bookPath), true);
$chain = json_decode((string) file_get_contents($chainPath), true);
if (!is_array($book) || !is_array($chain)) {
    fwrite(STDERR, "book.json or chain.json failed to parse.\n");
    exit(2);
}

$expected = $book['grid']['pages'] * $book['grid']['rows'] * $book['grid']['cols'];
if (count($book['words']) !== $expected) {
    fwrite(STDERR, sprintf(
        "book.json is inconsistent: grid says %d cells (%dx%dx%d) but it holds %d words. "
        . "Re-run bin/build.php rather than editing book.json by hand.\n",
        $expected,
        $book['grid']['pages'],
        $book['grid']['rows'],
        $book['grid']['cols'],
        count($book['words']),
    ));
    exit(2);
}

$layout = json_decode((string) file_get_contents($root . '/config/layout.json'), true);
$pdf = new BookPdf($title, $layout['print'] ?? []);
$pdf->render($book, $chain);
$pdf->Output('F', $outPath);

$pages = $book['grid']['pages'];
$print = $layout['print'] ?? [];
printf(
    "Wrote %s -- %d PDF pages (front matter + rules + %d word pages) at %.5g x %.5g mm.\n",
    $outPath,
    $pdf->PageNo(),
    $pages,
    $print['trim_width_mm'] ?? 133.35,
    $print['trim_height_mm'] ?? 177.8,
);
