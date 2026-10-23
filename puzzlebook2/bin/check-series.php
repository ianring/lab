#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Walk the Mystery chain on the REAL built grids -- the reader's exact
 * procedure, by machine, for every book.
 *
 *   php bin/build-series.php   # build first
 *   php bin/check-series.php   # then verify the chain resolves
 *
 * Book 1 (root): page = checksum of its answer (A=1..Z=26), row = letter
 * count, column = vowel count; the word there must be Book 1's Mystery.
 * Books 2..N: find THIS book's answer and the PREVIOUS book's Mystery in this
 * book; add their positions coordinate-by-coordinate (wrapping); the word at
 * that cell must be this book's Mystery.
 */

$root = dirname(__DIR__);
$dir = $root . '/build/series';

$plan = json_decode((string) @file_get_contents("{$dir}/plan.json"), true);
if (!is_array($plan)) {
    fwrite(STDERR, "build/series/plan.json not found -- run bin/build-series.php first.\n");
    exit(2);
}
$pages = (int) $plan['pages'];
$rows = (int) $plan['rows'];
$cols = (int) $plan['cols'];
$perPage = $rows * $cols;
$answers = $plan['answers'];
$mysteries = $plan['mysteries'];
$total = (int) $plan['total_books'];

$linear = static fn (int $p, int $r, int $c): int => $p * $perPage + $r * $cols + $c;
$coords = static function (int $cell) use ($perPage, $cols): array {
    $rem = $cell % $perPage;
    return [intdiv($cell, $perPage), intdiv($rem, $cols), $rem % $cols];
};
$checksum = static function (string $w): int {
    $s = 0;
    foreach (str_split(strtolower($w)) as $c) {
        if ($c >= 'a' && $c <= 'z') {
            $s += ord($c) - 96;
        }
    }
    return $s;
};
$letters = static fn (string $w): int => strlen((string) preg_replace('/[^a-z]/', '', strtolower($w)));
$vowels = static fn (string $w): int => (int) preg_match_all('/[aeiou]/', strtolower($w));

$books = [];
for ($n = 1; $n <= $total; $n++) {
    $path = sprintf('%s/book%02d/book.json', $dir, $n);
    $book = json_decode((string) @file_get_contents($path), true);
    if (!is_array($book)) {
        fwrite(STDERR, "missing/invalid {$path} -- build the series first.\n");
        exit(2);
    }
    $books[$n] = array_map('strtolower', $book['words']);
}

$findUnique = static function (int $n, string $word) use ($books): ?int {
    $hits = array_keys($books[$n], strtolower($word), true);
    return count($hits) === 1 ? $hits[0] : null;
};

echo "Walking the Mystery chain across {$total} books:\n\n";
$fail = 0;
for ($n = 1; $n <= $total; $n++) {
    $want = strtolower((string) $mysteries[$n]);
    $answer = strtolower((string) $answers[$n]);

    if ($n === 1) {
        $cell = $linear($checksum($answer) - 1, $letters($answer) - 1, $vowels($answer) - 1);
        $got = $books[1][$cell] ?? '?';
        $ok = $got === $want;
        $fail += $ok ? 0 : 1;
        printf("  Book 1 (root): checksum/len/vowels of '%s' -> %-8s  expect %-8s  %s\n",
            strtoupper($answer), strtoupper($got), strtoupper($want), $ok ? 'OK' : 'FAIL');
        continue;
    }

    $aPos = $findUnique($n, $answer);
    $mPos = $findUnique($n, (string) $mysteries[$n - 1]);
    if ($aPos === null || $mPos === null) {
        $fail++;
        printf("  Book %2d: FAIL -- answer or previous Mystery not found uniquely in this book\n", $n);
        continue;
    }
    [$ap, $ar, $ac] = $coords($aPos);
    [$mp, $mr, $mc] = $coords($mPos);
    $cell = $linear(($ap + $mp) % $pages, ($ar + $mr) % $rows, ($ac + $mc) % $cols);
    $got = $books[$n][$cell] ?? '?';
    $ok = $got === $want;
    $fail += $ok ? 0 : 1;
    printf("  Book %2d: pos(%s)+pos(%s) -> %-8s  expect %-8s  %s\n",
        $n, strtoupper($answer), strtoupper((string) $mysteries[$n - 1]), strtoupper($got), strtoupper($want), $ok ? 'OK' : 'FAIL');
}

// ---- mailing-address acrostics ----
$start = (int) ($plan['acrostic_start_col'] ?? 2);
$checkAcrostic = static function (string $label, int $bookNo, int $page, string $target) use ($books, $linear, $cols, $rows, $start, &$fail): void {
    $target = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $target));
    $spelled = '';
    $len = min(strlen($target), $rows);
    for ($r = 0; $r < $len; $r++) {
        $col = ($start + $r) % $cols;
        $spelled .= strtoupper($books[$bookNo][$linear($page, $r, $col)][0] ?? '?');
    }
    $ok = $spelled === $target;
    $fail += $ok ? 0 : 1;
    printf("  %-20s bk%-2d p%-3d  %-28s %s\n", $label, $bookNo, $page + 1, $spelled, $ok ? 'OK' : "FAIL (want {$target})");
};

echo "\nMailing-address acrostics:\n";
if (isset($plan['addressee'], $plan['acrostic_page'])) {
    $checkAcrostic('addressee', 1, (int) $plan['acrostic_page'], (string) $plan['addressee']);
}
foreach (($plan['places'] ?? []) as $component => $info) {
    $checkAcrostic($component, (int) $info['book'], (int) $info['page'], (string) $info['string']);
}

// ---- PO box number ----
if (isset($plan['box_find'], $plan['box_number'])) {
    $bf = $plan['box_find'];
    $hits = array_keys($books[(int) $bf['book']], strtolower((string) $bf['word']), true);
    $boxOk = count($hits) === 1;
    $onPage = $boxOk ? intdiv($hits[0], $perPage) + 1 : -1;
    $numOk = $boxOk && $onPage === (int) $plan['box_number'];
    $fail += $numOk ? 0 : 1;
    echo "\nPO box number (find the Capstone Mystery, its page is the box):\n";
    printf("  '%s' in bk%d -> page %s (expect box %d)  %s\n",
        strtoupper((string) $bf['word']), (int) $bf['book'], $boxOk ? (string) $onPage : '?',
        (int) $plan['box_number'], $numOk ? 'OK' : 'FAIL');
}

echo "\n" . ($fail === 0
    ? "PASS -- all {$total} Mystery links resolve, the address acrostics read, and the PO box number checks out.\n"
    : "FAIL -- {$fail} check(s) broken.\n");
exit($fail === 0 ? 0 : 1);
