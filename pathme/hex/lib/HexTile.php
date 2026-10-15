<?php
declare(strict_types=1);

/**
 * The combinatorial model of a hexagonal tile.
 *
 * A SHAPE is the set of edges it touches - a 6-bit mask, so 64 possible shapes,
 * falling into 14 classes once rotation is taken out. A TILE assigns every edge
 * to exactly one shape, so a tile is a set partition of the six edges: 203 of
 * them (Bell number B6), 43 up to rotation, 37 up to rotation and mirror.
 *
 * Shapes are drawn as filled regions bounded by circular arcs, not as strokes.
 * Where two shapes interleave around the rim their regions overlap; the overlap
 * simply merges, so there is no over/under state and no extra tile variants.
 * Overlapping tiles are legal but unusual, and can be filtered out.
 */
final class HexTile
{
    public const EDGES = 6;

    /**
     * The 14 shape classes, keyed by cyclic gap signature - the distances
     * between consecutive edges of the shape around the rim, canonicalised over
     * rotation. Crook is the only chiral one, so 14 up to rotation, 13 up to
     * rotation and mirror.
     */
    public const SHAPES = [
        '-'           => ['name' => 'Island',       'edges' => 0, 'note' => 'free-floating, touches nothing'],
        '6'           => ['name' => 'Terminal',     'edges' => 1, 'note' => 'enters and stops'],
        '1,5'         => ['name' => 'Sharp turn',   'edges' => 2, 'note' => 'adjacent, 60 degrees'],
        '2,4'         => ['name' => 'Wide turn',    'edges' => 2, 'note' => 'two apart, 120 degrees'],
        '3,3'         => ['name' => 'Straight',     'edges' => 2, 'note' => 'opposite, straight through'],
        '1,1,4'       => ['name' => 'Fan',          'edges' => 3, 'note' => 'three consecutive'],
        '1,2,3'       => ['name' => 'Crook',        'edges' => 3, 'note' => 'chiral'],
        '1,3,2'       => ['name' => 'Crook mirror', 'edges' => 3, 'note' => 'the other hand'],
        '2,2,2'       => ['name' => 'Trefoil',      'edges' => 3, 'note' => 'symmetric three-way'],
        '1,1,1,3'     => ['name' => 'Broad fan',    'edges' => 4, 'note' => 'four consecutive'],
        '1,1,2,2'     => ['name' => 'Kite',         'edges' => 4, 'note' => 'three plus one'],
        '1,2,1,2'     => ['name' => 'Bowtie',       'edges' => 4, 'note' => 'two opposite pairs'],
        '1,1,1,1,2'   => ['name' => 'Horseshoe',    'edges' => 5, 'note' => 'all but one'],
        '1,1,1,1,1,1' => ['name' => 'Rosette',      'edges' => 6, 'note' => 'all six'],
    ];

    /** @var int[][] blocks of edge indices */
    public array $blocks;

    public function __construct(array $blocks)
    {
        $blocks = array_map(static function (array $b): array {
            sort($b);
            return $b;
        }, $blocks);
        usort($blocks, static fn (array $x, array $y): int => ($x[0] ?? 99) <=> ($y[0] ?? 99));
        $this->blocks = array_values($blocks);
    }

    // ------------------------------------------------------------ enumeration

    /** All 203 set partitions of the six edges. */
    public static function partitions(): array
    {
        return self::partitionsOf(range(0, self::EDGES - 1));
    }

    private static function partitionsOf(array $elems): array
    {
        if (!$elems) {
            return [[]];
        }
        $first = array_shift($elems);
        $out   = [];
        for ($k = 0; $k <= count($elems); $k++) {
            foreach (self::choose($elems, $k) as $others) {
                $remaining = array_values(array_diff($elems, $others));
                foreach (self::partitionsOf($remaining) as $rest) {
                    $out[] = array_merge([array_merge([$first], $others)], $rest);
                }
            }
        }
        return $out;
    }

    private static function choose(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        $n   = count($items);
        for ($i = 0; $i <= $n - $k; $i++) {
            foreach (self::choose(array_slice($items, $i + 1), $k - 1) as $tail) {
                $out[] = array_merge([$items[$i]], $tail);
            }
        }
        return $out;
    }

    /** @return self[] every tile */
    public static function enumerate(): array
    {
        return array_map(static fn (array $b): self => new self($b), self::partitions());
    }

    // -------------------------------------------------------- shape classes

    /** Cyclic gap signature of a shape, canonicalised over rotation. */
    public static function gapSignature(array $block): string
    {
        sort($block);
        $n = count($block);
        if ($n === 0) {
            return '-';
        }
        if ($n === 1) {
            return (string) self::EDGES;
        }

        $gaps = [];
        for ($i = 0; $i < $n; $i++) {
            $d = ($block[($i + 1) % $n] - $block[$i] + self::EDGES) % self::EDGES;
            $gaps[] = $d === 0 ? self::EDGES : $d;
        }

        $best = null;
        for ($r = 0; $r < $n; $r++) {
            $cand = implode(',', array_merge(array_slice($gaps, $r), array_slice($gaps, 0, $r)));
            if ($best === null || strcmp($cand, $best) < 0) {
                $best = $cand;
            }
        }
        return (string) $best;
    }

    public static function shapeName(array $block): string
    {
        $sig = self::gapSignature($block);
        return self::SHAPES[$sig]['name'] ?? $sig;
    }

    /** Six-bit mask of the edges a shape touches. */
    public static function mask(array $block): int
    {
        $m = 0;
        foreach ($block as $e) {
            $m |= 1 << $e;
        }
        return $m;
    }

    public function shapeNames(): array
    {
        return array_map([self::class, 'shapeName'], $this->blocks);
    }

    // ------------------------------------------------------------- overlaps

    /**
     * Two shapes overlap when their edges interleave around the rim: neither
     * can be drawn without stepping on the other. Legal, but unusual - the
     * gallery can filter on it.
     */
    public static function interleave(array $a, array $b): bool
    {
        if (count($a) < 2 || count($b) < 2) {
            return false;
        }
        sort($a);
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $lo  = $a[$i];
            $hi  = $a[($i + 1) % $n];
            $gap = [];
            for ($s = 1; $s < (($hi - $lo) % self::EDGES + self::EDGES) % self::EDGES; $s++) {
                $gap[] = ($lo + $s) % self::EDGES;
            }
            if (!array_diff($b, $gap)) {
                return false;
            }
        }
        return true;
    }

    /** @return int[][] pairs of block indices whose regions overlap */
    public function overlaps(): array
    {
        $out = [];
        $n   = count($this->blocks);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (self::interleave($this->blocks[$i], $this->blocks[$j])) {
                    $out[] = [$i, $j];
                }
            }
        }
        return $out;
    }

    public function hasOverlap(): bool
    {
        return $this->overlaps() !== [];
    }

    // -------------------------------------------------------------- identity

    /** Stable id, e.g. "012-345". Order-independent. */
    public function id(): string
    {
        $names = array_map(static fn (array $b): string => implode('', $b), $this->blocks);
        sort($names);
        return implode('-', $names);
    }

    /** Rebuild a tile from an id like "012-345". */
    public static function fromId(string $id): self
    {
        $blocks = [];
        foreach (explode('-', trim($id)) as $chunk) {
            $chunk = preg_replace('/[^0-5]/', '', $chunk) ?? '';
            if ($chunk === '') {
                continue;
            }
            $blocks[] = array_map('intval', str_split($chunk));
        }

        $seen = [];
        foreach ($blocks as $b) {
            foreach ($b as $e) {
                if (isset($seen[$e])) {
                    throw new \InvalidArgumentException("edge $e appears twice in id '$id'");
                }
                $seen[$e] = true;
            }
        }
        if (count($seen) !== self::EDGES) {
            throw new \InvalidArgumentException("id '$id' does not cover all six edges");
        }
        return new self($blocks);
    }

    /** All 12 orientations: six rotations, each optionally mirrored. */
    public function orientations(bool $withMirror = true): array
    {
        $out = [];
        for ($r = 0; $r < self::EDGES; $r++) {
            $out[] = $this->transform(static fn (int $e): int => ($e + $r) % self::EDGES);
            if ($withMirror) {
                $out[] = $this->transform(
                    static fn (int $e): int => (($r - $e) % self::EDGES + self::EDGES) % self::EDGES
                );
            }
        }
        return $out;
    }

    public function label(): string
    {
        $parts = array_map(static fn (array $b): string => '{' . implode(',', $b) . '}', $this->blocks);
        return implode('', $parts);
    }

    public function transform(callable $f): self
    {
        return new self(array_map(static fn (array $b): array => array_map($f, $b), $this->blocks));
    }

    /** Smallest id over the six rotations, optionally twelve with mirrors. */
    public function canonical(bool $withReflection = false): string
    {
        $best = null;
        for ($r = 0; $r < self::EDGES; $r++) {
            $ids = [$this->transform(static fn (int $e): int => ($e + $r) % self::EDGES)->id()];
            if ($withReflection) {
                $ids[] = $this->transform(
                    static fn (int $e): int => (($r - $e) % self::EDGES + self::EDGES) % self::EDGES
                )->id();
            }
            foreach ($ids as $id) {
                if ($best === null || strcmp($id, $best) < 0) {
                    $best = $id;
                }
            }
        }
        return (string) $best;
    }
}
