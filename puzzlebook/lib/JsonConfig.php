<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * Loads a config file -- layout, clues, or an active chain -- from JSON
 * rather than a PHP `return [...]` file. Config is DATA, not code: nothing
 * in config/*.json is ever meant to be executed, only read, and JSON makes
 * that honest in the file format itself rather than just by convention.
 *
 * The one thing a PHP config file could do that JSON can't is carry inline
 * comments explaining WHY a value was chosen. That documentation didn't go
 * away -- see config/chain.md for the chain's own ordering philosophy and
 * conventions, and each step's own "note" field (ignored by every reader of
 * this data, same as 'printed' or 'final') for anything step-specific.
 */
final class JsonConfig
{
    /** @return array<mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read config file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException(
                "Config file isn't valid JSON (or isn't an object/array) at top level: {$path}"
            );
        }
        return $data;
    }

    /**
     * Writes $data as pretty-printed JSON, atomically -- a temp file in the same
     * directory, then renamed into place, so a process killed mid-write (or two
     * runs overlapping) can never leave a reader looking at a half-written file.
     */
    public static function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory for: {$path}");
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Could not encode data as JSON for: {$path}");
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Could not write: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not move {$tmp} into place at {$path}");
        }
    }
}
