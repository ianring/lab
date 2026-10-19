<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * Disk cache for the expensive, dictionary-derived indexes a few rules need
 * -- "which words have an anagram", "which words have a shorter word hiding
 * inside them" -- so bin/check.php and bin/build.php don't rebuild them from
 * the full 133,000-word pool on every single run.
 *
 * WHY THIS EXISTS. An in-memory cache (an instance property on the rule
 * object) already avoids rebuilding an index once PER ATTEMPT -- a build can
 * try thousands. But check.php and build.php are separate PHP processes,
 * invoked over and over while a chain is being tuned, and an in-memory cache
 * dies with the process. Without this, the SAME index gets rebuilt in full
 * every single time anyone runs bin/check.php, which is supposed to be the
 * fast feedback loop.
 *
 * WHAT'S NOT CACHED HERE, ON PURPOSE. ChainBuilder's per-word "which spelling
 * rules does this fail" bitmask table is NOT disk-cached, even though it's
 * also rebuilt every run. That table's content depends on the exact ordered
 * set of spelling rules in config/chain.json -- precisely what changes most
 * often while a chain is being tuned -- so it would be invalidated almost
 * every time anyway, for a computation cheap enough (one pass, bitwise ops)
 * that the disk I/O likely costs more than it saves. The indexes here are
 * different: they depend only on the word list and on ONE rule's own
 * parameters, which change far less often, and they're expensive enough
 * (enumerating substrings of every word, for the containment rule) to be
 * worth persisting.
 *
 * A cache file is invalidated automatically if the word list changes (its
 * content is hashed into the cache key), if the rule's own parameters change
 * (the caller folds those into the key too, usually via describe()), OR if
 * the PHP that defines the rule's own matching logic changes -- $label is
 * always a class name, so pathFor() reflects on it and hashes the source file
 * of that class and every ancestor it inherits from. Editing a rule's
 * failsWord()/apply() logic changes nothing about its describe() text, so
 * without this a stale index could silently survive a real behavior change;
 * with it, the file's own content is part of the key, same as the word list.
 * A stale file is simply never looked up again -- nothing has to notice or
 * clean it up, though data/cache/ can be deleted wholesale at any time;
 * everything in it is reconstructible.
 */
final class WordIndexCache
{
    private static ?string $dir = null;

    public static function directory(): string
    {
        return self::$dir ??= dirname(__DIR__) . '/data/cache';
    }

    /**
     * Returns the cached array for ($label, $paramsKey, $wordPoolHash) if one exists
     * on disk; otherwise calls $compute(), saves the result, and returns it. $label
     * doubles as the class whose source defines the cached behavior -- see codeHashFor().
     *
     * @param callable(): array $compute
     * @return array
     */
    public static function remember(string $label, string $paramsKey, string $wordPoolHash, callable $compute): array
    {
        $path = self::pathFor($label, $paramsKey, $wordPoolHash);

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $cached = $raw === false ? null : json_decode($raw, true);
            if (is_array($cached)) {
                return $cached;
            }
            // Unreadable or corrupt (an interrupted write, a hand edit) -- fall
            // through and rebuild rather than fail the whole build over a cache file.
        }

        $result = $compute();
        self::write($path, $result);
        return $result;
    }

    /**
     * A hash of the source behind $className: its own file, every class it extends, and
     * any traits those classes use. Interfaces are skipped -- they declare signatures,
     * not behavior, so they have nothing to invalidate over. Walking the ancestry matters
     * because the rules that use this cache lean on shared base-class logic
     * (PageSignaturePairRule, SpellingRule); hashing only the leaf class would miss an
     * edit to the logic it actually inherited.
     */
    public static function codeHashFor(string $className): string
    {
        if (!class_exists($className)) {
            return '';
        }

        $files = [];
        $class = $className;
        while ($class !== false) {
            $ref = new \ReflectionClass($class);
            $file = $ref->getFileName();
            if ($file !== false) {
                $files[$file] = true;
            }
            foreach ($ref->getTraitNames() as $trait) {
                $traitFile = (new \ReflectionClass($trait))->getFileName();
                if ($traitFile !== false) {
                    $files[$traitFile] = true;
                }
            }
            $class = get_parent_class($class);
        }

        // Sorted so the hash doesn't depend on reflection's traversal order.
        $paths = array_keys($files);
        sort($paths);

        $combined = '';
        foreach ($paths as $path) {
            $combined .= md5_file($path);
        }
        return substr(md5($combined), 0, 16);
    }

    private static function pathFor(string $label, string $paramsKey, string $wordPoolHash): string
    {
        $safeLabel = preg_replace('/[^a-z0-9_-]/i', '_', $label);
        $codeHash = self::codeHashFor($label);
        $key = substr(md5($paramsKey . '|' . $wordPoolHash . '|' . $codeHash), 0, 16);
        return self::directory() . "/{$safeLabel}-{$key}.json";
    }

    private static function write(string $path, array $result): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return; // Couldn't create the cache directory -- proceed without caching.
        }

        // Write to a temp file in the same directory and rename into place, so a
        // process killed mid-write (or two build.php runs overlapping) can never
        // leave a half-written file that a later run would load as if it were valid.
        // Same directory matters: rename() is only atomic within one filesystem.
        // Plain JSON, not PHP -- this is pure machine-generated data, nothing in it
        // is ever meant to be hand-read as code.
        $tmp = $path . '.' . getmypid() . '.tmp';
        $written = @file_put_contents($tmp, json_encode($result, JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
