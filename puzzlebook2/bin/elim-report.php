#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cross-book elimination report: for every built book, how many words each rule
 * crosses out -- the same numbers the books print -- so you can spot rules that
 * absorb too much.
 *
 *   php bin/elim-report.php            # top absorbers per book + rule-type ranking
 *   php bin/elim-report.php --full     # every rule of every book
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$full = in_array('--full', $args, true);
$matrix = in_array('--matrix', $args, true);
$positions = in_array('--positions', $args, true);
$dir = $root . '/build/series';

$books = [];              // book => [ [type, removes], ... ]
$byType = [];             // type => list of removes across books
for ($n = 1; $n <= 40; $n++) {
    $path = sprintf('%s/book%02d/chain.json', $dir, $n);
    if (!is_file($path)) {
        continue;
    }
    $chain = json_decode((string) file_get_contents($path), true);
    $rules = [];
    foreach ($chain['steps'] ?? [] as $s) {
        if (empty($s['printed'])) {
            continue;
        }
        $type = preg_replace('/Rule$/', '', $s['rule']['type'] ?? '?');
        $removes = (int) ($s['removes'] ?? 0);
        $rules[] = [$type, $removes];
        $byType[$type][] = $removes;
    }
    $books[$n] = $rules;
}

printf("%d books, board eliminations should total 12,499 each.\n\n", count($books));

if ($matrix) {
    // rules down the side, books across the top; each cell = words eliminated.
    $bookNums = array_keys($books);
    sort($bookNums);
    $grid = [];                        // type => [bookNum => removes]
    foreach ($books as $n => $rules) {
        foreach ($rules as [$t, $r]) {
            $grid[$t][$n] = $r;
        }
    }
    uksort($grid, static fn ($a, $b) => max($grid[$b]) <=> max($grid[$a]));  // biggest absorbers first

    printf("%-26s", 'rule \\ book');
    foreach ($bookNums as $n) {
        printf("%5d", $n);
    }
    printf("%7s\n", 'max');
    echo str_repeat('-', 26 + 5 * count($bookNums) + 7) . "\n";
    foreach ($grid as $type => $row) {
        printf("%-26s", substr($type, 0, 26));
        foreach ($bookNums as $n) {
            printf("%5s", $row[$n] ?? '.');
        }
        printf("%7d\n", max($row));
    }
    return;
}

if ($positions) {
    // rows = chain position (rule 1, 2, 3 ...), columns = books, cell = words
    // eliminated at that position -- shows the cascade shape, whatever rule sits
    // there in each book.
    $bookNums = array_keys($books);
    sort($bookNums);
    $maxPos = 0;
    foreach ($books as $rules) {
        $maxPos = max($maxPos, count($rules));
    }
    printf("%-10s", 'position');
    foreach ($bookNums as $n) {
        printf("%5d", $n);
    }
    echo "\n" . str_repeat('-', 10 + 5 * count($bookNums)) . "\n";
    for ($i = 0; $i < $maxPos; $i++) {
        printf("rule %-5d", $i + 1);
        foreach ($bookNums as $n) {
            printf("%5s", $books[$n][$i][1] ?? '.');
        }
        echo "\n";
    }
    echo "\n(the last three rows are the absorber, then the final sweep; the BOOM reaction book has one extra row.)\n";
    return;
}

if ($full) {
    foreach ($books as $n => $rules) {
        echo "== book $n ==\n";
        foreach ($rules as $i => [$t, $r]) {
            printf("  %2d. %-28s %5d\n", $i + 1, $t, $r);
        }
        echo "\n";
    }
    return;
}

// ---- per-book: total + the biggest absorbers -----------------------------
echo "PER BOOK -- total eliminated, then the 4 biggest absorbers (rule:count, % of board):\n\n";
printf("%-5s %6s   %s\n", 'book', 'total', 'biggest absorbers');
foreach ($books as $n => $rules) {
    usort($rules, static fn ($a, $b) => $b[1] <=> $a[1]);
    $total = array_sum(array_column($rules, 1));
    $top = [];
    foreach (array_slice($rules, 0, 4) as [$t, $r]) {
        $top[] = sprintf('%s:%d(%d%%)', $t, $r, (int) round($r / 125));
    }
    printf("%-5d %6d   %s\n", $n, $total, implode('  ', $top));
}

// ---- rule types ranked by how much they absorb ---------------------------
echo "\nRULE TYPES, ranked by their LARGEST single-book elimination (max / avg / #books):\n\n";
$rows = [];
foreach ($byType as $t => $list) {
    $rows[] = [$t, max($list), (int) round(array_sum($list) / count($list)), count($list)];
}
usort($rows, static fn ($a, $b) => $b[1] <=> $a[1]);
printf("%-28s %6s %6s %6s\n", 'rule', 'max', 'avg', 'books');
foreach ($rows as [$t, $mx, $av, $cnt]) {
    printf("%-28s %6d %6d %6d\n", $t, $mx, $av, $cnt);
}
