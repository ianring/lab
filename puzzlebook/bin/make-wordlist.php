#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds data/wordlist.txt from a system dictionary.
 *
 * This exists because the word list is a real input to the puzzle, not a
 * fixed asset: its length range changes what every rule can catch, and how
 * much supply each one has. Before this script, wordlist.txt was an
 * unexplained file with no way to tell how it had been filtered or to
 * reproduce it -- which is a bad position to be in when "add 10-letter
 * words" turns out to be a design decision rather than a chore.
 *
 * Usage:
 *
 *   php bin/make-wordlist.php                       # defaults: 3-10 letters
 *   php bin/make-wordlist.php --min=3 --max=12
 *   php bin/make-wordlist.php --source=/path/to/words
 *   php bin/make-wordlist.php --max=12 --dry-run    # just report, write nothing
 *
 * The filter is deliberately strict: lowercase a-z only, so nothing with an
 * apostrophe, hyphen, accent or capital gets in. Proper nouns are excluded
 * by the lowercase test, which is what we want -- a puzzle built on
 * structure shouldn't need you to know anyone's name.
 *
 * AFTER REGENERATING, re-run bin/check.php. Changing the word list changes
 * how much supply every rule has, so keep_fractions that fitted before may
 * not fit now (and ones that were short may now be fine). It also changes
 * the whole book: words are drawn from a different pool, so every page will
 * differ even though the build stays deterministic.
 */

$options = getopt('', ['min::', 'max::', 'source::', 'out::', 'dry-run']);

$min = (int) ($options['min'] ?? 3);
$max = (int) ($options['max'] ?? 10);
$dryRun = array_key_exists('dry-run', $options);
$root = dirname(__DIR__);
$out = $options['out'] ?? $root . '/data/wordlist.txt';

if ($min < 1 || $max < $min) {
    fwrite(STDERR, "A --min of {$min} and --max of {$max} don't make sense together.\n");
    exit(1);
}

$candidates = isset($options['source'])
    ? [$options['source']]
    : ['/usr/share/dict/words', '/usr/share/dict/web2', '/usr/dict/words'];

$source = null;
foreach ($candidates as $path) {
    if (is_readable($path)) {
        $source = $path;
        break;
    }
}

if ($source === null) {
    fwrite(STDERR, "Couldn't find a system dictionary. Looked in:\n");
    foreach ($candidates as $path) {
        fwrite(STDERR, "  {$path}\n");
    }
    fwrite(STDERR, "\nPass one with --source=/path/to/words. On Debian/Ubuntu, "
        . "`apt install wamerican` installs one at /usr/share/dict/words.\n");
    exit(1);
}

echo "Source: {$source}\n";
echo "Keeping lowercase a-z words of {$min} to {$max} letters.\n\n";

$handle = fopen($source, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open {$source}\n");
    exit(1);
}

$kept = [];
$read = 0;
$pattern = '/^[a-z]{' . $min . ',' . $max . '}$/';

while (($line = fgets($handle)) !== false) {
    $read++;
    $word = trim($line);
    if ($word !== '' && preg_match($pattern, $word) === 1) {
        $kept[$word] = true;   // keys dedupe for us
    }
}
fclose($handle);

$words = array_keys($kept);
sort($words);

// Per-length counts are the useful diagnostic: a rule's supply depends far more
// on which lengths are present than on the total.
$byLength = [];
foreach ($words as $word) {
    $len = strlen($word);
    $byLength[$len] = ($byLength[$len] ?? 0) + 1;
}
ksort($byLength);

echo 'Read ' . number_format($read) . ' lines, kept ' . number_format(count($words)) . " words.\n\n";
echo "Words by length:\n";
foreach ($byLength as $len => $count) {
    echo '  ' . str_pad((string) $len, 2) . ' letters: ' . str_pad(number_format($count), 8, ' ', STR_PAD_LEFT) . "\n";
}
echo "\n";

if ($dryRun) {
    echo "--dry-run given, so nothing was written.\n";
    exit(0);
}

if (file_exists($out)) {
    $previous = count(file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    echo "Replacing {$out} (was " . number_format($previous) . " words).\n";
}

if (file_put_contents($out, implode("\n", $words) . "\n") === false) {
    fwrite(STDERR, "Could not write {$out}\n");
    exit(1);
}

echo "Wrote {$out}\n\n";
echo "Now re-run bin/check.php: a different word list means different supply for every\n";
echo "rule, so some keep_fractions may need adjusting.\n";
