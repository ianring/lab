<?php
declare(strict_types=1);

/**
 * Disk cache for generated puzzles, keyed by variant + difficulty + seed.
 *
 * Generation is deterministic: mt_srand($seed) drives every shuffle and dig, so
 * a given (variant, difficulty, seed) always produces the same puzzle. That
 * makes the cache a pure memo - reading a hit is identical to recomputing it,
 * just far cheaper.
 *
 * Verification results ride along in the record. SudokuEngine::verify() runs a
 * brute-force solution count and a full logical solve, far too expensive to
 * repeat on every page load; it only needs to happen once, at generation.
 */
final class PuzzleStore
{
    private string $dir;

    /** Problems that would otherwise be invisible, e.g. an unwritable cache. */
    private array $warnings = [];

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        return @mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }

    private static function slug(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $s) ?: 'unknown');
    }

    public function path(string $variant, string $difficulty, int $seed): string
    {
        return $this->dir . '/' . self::slug($variant) . '-' . self::slug($difficulty) . '-' . $seed . '.json';
    }

    public function has(string $variant, string $difficulty, int $seed): bool
    {
        $p = $this->path($variant, $difficulty, $seed);
        return is_file($p) && filesize($p) > 0;
    }

    public function load(string $variant, string $difficulty, int $seed): ?Puzzle
    {
        $path = $this->path($variant, $difficulty, $seed);
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }
        try {
            $puzzle = Puzzle::fromJson($json);
        } catch (\Throwable $e) {
            @unlink($path);              // corrupt entry: drop it and regenerate
            return null;
        }
        // Cheap sanity check so a truncated write can never pass as a puzzle.
        $expected = $puzzle->variant()->cells();
        if (count($puzzle->solution) !== $expected || in_array(0, $puzzle->solution, true)) {
            @unlink($path);
            return null;
        }
        return $puzzle;
    }

    public function save(Puzzle $p): bool
    {
        return $this->writeJson(
            $this->path($p->variantKey, (string) ($p->meta['difficulty'] ?? 'unknown'), (int) ($p->meta['seed'] ?? 0)),
            $p->toJson()
        );
    }

    /**
     * Write to a temp file and rename, so a crash mid-write cannot leave a
     * half-written record that later reads as valid. A failure here means every
     * request recomputes from scratch, which is the most likely cause of
     * "I added caching and it is still slow" - so it is recorded, never silent.
     */
    private function writeJson(string $path, string $json): bool
    {
        if (!$this->ensureDir()) {
            $this->warnings[] = "Cache directory {$this->dir} could not be created - "
                . 'every request is recomputing from scratch.';
            return false;
        }
        if (!is_writable($this->dir)) {
            $this->warnings[] = "Cache directory {$this->dir} is not writable by "
                . (function_exists('get_current_user') ? get_current_user() : 'this user')
                . ' - every request is recomputing from scratch.';
            return false;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            $this->warnings[] = "Could not write $tmp - results are not being cached.";
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $this->warnings[] = "Could not move $tmp into place - results are not being cached.";
            return false;
        }
        return true;
    }

    /**
     * Fetch a puzzle, generating and caching it only if it is not already on
     * disk. A null seed always generates, then caches under its own seed.
     */
    public function get(string $variant, string $difficulty, ?int $seed = null, bool $verify = true): Puzzle
    {
        $variant = Variant::name($variant);

        if ($seed !== null) {
            $cached = $this->load($variant, $difficulty, $seed);
            if ($cached !== null) {
                return $cached;
            }
        }

        $engine = SudokuEngine::forVariant($variant);
        $puzzle = $engine->generatePuzzle($difficulty, $seed);

        if ($verify) {
            $errors = $engine->verify($puzzle);
            $puzzle->meta['verified']     = $errors === [];
            $puzzle->meta['verifyErrors'] = $errors;
        }

        $this->save($puzzle);
        return $puzzle;
    }

    /**
     * Verification errors, using the stored result when the puzzle came from
     * cache and only doing the expensive work when it did not.
     */
    public function errorsFor(Puzzle $p): array
    {
        if (array_key_exists('verified', $p->meta) && $p->meta['verified'] !== null) {
            return (array) ($p->meta['verifyErrors'] ?? []);
        }
        $errors = SudokuEngine::forVariant($p->variantKey)->verify($p);
        $p->meta['verified']     = $errors === [];
        $p->meta['verifyErrors'] = $errors;
        return $errors;
    }

    // ------------------------------------------------------------ walkthrough

    private function stepsPath(Puzzle $p): string
    {
        return $this->dir . '/' . self::slug($p->variantKey)
            . '-' . self::slug((string) ($p->meta['difficulty'] ?? 'unknown'))
            . '-' . (int) ($p->meta['seed'] ?? 0) . '.steps.json';
    }

    /**
     * The full inference walkthrough, memoised. This is a complete traced solve
     * - as expensive as one pass of the solver - so it must not run on every
     * page load to render a table that never changes for a given seed.
     */
    public function steps(Puzzle $p): array
    {
        $path = $this->stepsPath($p);
        if (is_file($path)) {
            $data = json_decode((string) @file_get_contents($path), true);
            if (is_array($data) && isset($data['steps']) && is_array($data['steps'])) {
                return $data['steps'];
            }
            @unlink($path);
        }

        $steps = SudokuEngine::forVariant($p->variantKey)->explain($p)['steps'];
        $this->writeJson($path, (string) json_encode(['steps' => $steps]));
        return $steps;
    }

    public function hasSteps(Puzzle $p): bool
    {
        return is_file($this->stepsPath($p));
    }

    // ------------------------------------------------------------------ admin

    /** @return array{count:int,steps:int,bytes:int,newest:?int} */
    public function stats(): array
    {
        $files  = glob($this->dir . '/*.json') ?: [];
        $bytes  = 0;
        $newest = null;
        $count  = 0;
        $steps  = 0;
        foreach ($files as $f) {
            $bytes += (int) filesize($f);
            if (substr($f, -11) === '.steps.json') {
                $steps++;
            } else {
                $count++;
            }
            $m = (int) filemtime($f);
            if ($newest === null || $m > $newest) {
                $newest = $m;
            }
        }
        return ['count' => $count, 'steps' => $steps, 'bytes' => $bytes, 'newest' => $newest];
    }

    public function clear(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }
}
