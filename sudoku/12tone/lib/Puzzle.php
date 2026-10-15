<?php
declare(strict_types=1);

/**
 * One generated puzzle, of whatever variant.
 *
 * Grids are flat arrays of n*n ints: 0 = empty, 1..n = the symbols. The offset
 * matters - in the chromatic set "0" is a legitimate symbol, so it must never
 * double as the empty sentinel.
 *
 * JSON is an export format only; toJson()/fromJson() round-trip this object for
 * anything that wants to consume puzzles from outside PHP.
 */
final class Puzzle
{
    public const FORMAT_VERSION = 2;

    public string $variantKey;
    public array $puzzle;    // n*n ints, 0 = empty
    public array $solution;  // n*n ints, all non-zero
    public array $analysis;  // label, maxTier, score, tierCap, solved, techniques
    public array $meta;      // difficulty, seed, attempt, verified, verifyErrors

    public function __construct(
        string $variantKey,
        array $puzzle,
        array $solution,
        array $analysis = [],
        array $meta = []
    ) {
        $this->variantKey = Variant::name($variantKey);
        $this->puzzle     = array_values($puzzle);
        $this->solution   = array_values($solution);
        $this->analysis   = $analysis;
        $this->meta       = $meta;
    }

    public function variant(): Variant
    {
        return Variant::get($this->variantKey);
    }

    public function size(): int
    {
        return $this->variant()->n;
    }

    // -------------------------------------------------------------- accessors

    public function cell(int $r, int $c): int
    {
        return $this->puzzle[$r * $this->size() + $c];
    }

    public function solutionCell(int $r, int $c): int
    {
        return $this->solution[$r * $this->size() + $c];
    }

    public function isGiven(int $r, int $c): bool
    {
        return $this->cell($r, $c) !== 0;
    }

    /** Display symbol for a cell, or '' when empty. */
    public function symbol(int $r, int $c): string
    {
        $v = $this->cell($r, $c);
        return $v === 0 ? '' : $this->variant()->symbol($v);
    }

    public function solutionSymbol(int $r, int $c): string
    {
        return $this->variant()->symbol($this->solutionCell($r, $c));
    }

    /** One row as ints; pass true for the solved row. */
    public function row(int $r, bool $solved = false): array
    {
        $src = $solved ? $this->solution : $this->puzzle;
        return array_slice($src, $r * $this->size(), $this->size());
    }

    public function givenCount(): int
    {
        return count(array_filter($this->puzzle, static fn (int $v): bool => $v !== 0));
    }

    public function difficultyLabel(): string
    {
        return $this->analysis['label'] ?? 'Unknown';
    }

    /** true/false once verified, null if it has never been checked. */
    public function isVerified(): ?bool
    {
        return $this->meta['verified'] ?? null;
    }

    /** @return string[] */
    public function verifyErrors(): array
    {
        return (array) ($this->meta['verifyErrors'] ?? []);
    }

    // -------------------------------------------------------------- identity

    /**
     * Content-addressed identifier, e.g. "K7M3QX9AZ2".
     *
     * Derived from the grids themselves rather than from (variant, difficulty,
     * seed), because a seed is only stable while the generator is. Change the
     * digger or the technique ladder and seed 12345 yields a different puzzle -
     * a seed-based URL would then quietly point at different content, which is
     * exactly what an immutable URL must not do. Hashing the content instead
     * means a changed generator produces a new id and every previously published
     * id still resolves to the puzzle it always named.
     *
     * Crockford base32: no I, L, O or U, so it survives being read aloud or
     * typed off a printed worksheet.
     */
    public function id(): string
    {
        $canonical = $this->variantKey
            . '|' . $this->gridToString($this->puzzle)
            . '|' . $this->gridToString($this->solution);

        return self::shortId(hash('sha256', $canonical, true));
    }

    private static function shortId(string $digest, int $chars = 10): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $n = 0;
        for ($i = 0; $i < 7; $i++) {        // 56 bits, comfortably inside a PHP int
            $n = ($n << 8) | ord($digest[$i]);
        }

        $out = '';
        for ($i = 0; $i < $chars; $i++) {   // 10 chars x 5 bits = 50 bits
            $out = $alphabet[$n & 31] . $out;
            $n >>= 5;
        }
        return $out;
    }

    // ---------------------------------------------------------- serialisation

    public function toArray(): array
    {
        $v = $this->variant();
        return [
            'version'      => self::FORMAT_VERSION,
            'id'           => $this->id(),
            'variant'      => $this->variantKey,
            'size'         => $v->n,
            'boxWidth'     => $v->boxWidth,
            'boxHeight'    => $v->boxHeight,
            'alphabet'     => $v->alphabet,
            'difficulty'   => $this->meta['difficulty'] ?? null,
            'seed'         => $this->meta['seed'] ?? null,
            'attempt'      => $this->meta['attempt'] ?? null,
            'givens'       => $this->givenCount(),
            'verified'     => $this->meta['verified'] ?? null,
            'verifyErrors' => array_values($this->verifyErrors()),
            'puzzle'       => $this->gridToString($this->puzzle),
            'solution'     => $this->gridToString($this->solution),
            'analysis'     => $this->analysis,
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags);
    }

    public static function fromArray(array $a): self
    {
        // version 1 records predate variants and were all chromatic
        $key     = Variant::name((string) ($a['variant'] ?? 'chromatic'));
        $variant = Variant::get($key);

        return new self(
            $key,
            self::decode((string) ($a['puzzle'] ?? ''), $variant),
            self::decode((string) ($a['solution'] ?? ''), $variant),
            (array) ($a['analysis'] ?? []),
            [
                'difficulty'   => $a['difficulty'] ?? null,
                'seed'         => $a['seed'] ?? null,
                'attempt'      => $a['attempt'] ?? null,
                'verified'     => $a['verified'] ?? null,
                'verifyErrors' => (array) ($a['verifyErrors'] ?? []),
            ]
        );
    }

    public static function fromJson(string $json): self
    {
        $a = json_decode($json, true);
        if (!is_array($a)) {
            throw new \InvalidArgumentException('not a valid puzzle JSON document');
        }
        return self::fromArray($a);
    }

    private function gridToString(array $g): string
    {
        $alphabet = $this->variant()->alphabet;
        $s = '';
        foreach ($g as $v) {
            $s .= $v === 0 ? '.' : $alphabet[$v - 1];
        }
        return $s;
    }

    private static function decode(string $s, Variant $variant): array
    {
        $cells = $variant->cells();
        $g = [];
        for ($i = 0; $i < $cells; $i++) {
            $ch = $s[$i] ?? '.';
            $p  = strpos($variant->alphabet, $ch);
            $g[] = ($ch === '.' || $p === false) ? 0 : $p + 1;
        }
        return $g;
    }

    /** Plain-text grid with box borders, for CLI output. */
    public function toText(bool $solved = false): string
    {
        $v    = $this->variant();
        $rule = '+' . str_repeat(str_repeat('--', $v->boxWidth) . '-+', $v->stacks()) . "\n";
        $out  = '';
        for ($r = 0; $r < $v->n; $r++) {
            if ($r % $v->boxHeight === 0) {
                $out .= $rule;
            }
            for ($c = 0; $c < $v->n; $c++) {
                $out .= ($c % $v->boxWidth === 0) ? '| ' : ' ';
                $sym = $solved ? $this->solutionSymbol($r, $c) : $this->symbol($r, $c);
                $out .= $sym === '' ? '.' : $sym;
            }
            $out .= " |\n";
        }
        return $out . $rule;
    }
}
