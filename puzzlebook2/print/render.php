#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render the built book as a print-ready PDF at mass-market paperback trim
 * (4.25" x 6.87" / 108 x 175 mm): front matter and the numbered rules
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
$title = 'Dragon Hunt';

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

$pdf = new BookPdf($title);
$pdf->render($book, $chain);
$pdf->Output('F', $outPath);

$pages = $book['grid']['pages'];
printf(
    "Wrote %s -- %d PDF pages (front matter + rules + %d word pages) at 108 x 175 mm.\n",
    $outPath,
    $pdf->PageNo(),
    $pages,
);
