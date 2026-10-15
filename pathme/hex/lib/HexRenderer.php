<?php
declare(strict_types=1);

/**
 * Draws a HexTile as filled regions bounded by circular arcs.
 *
 * The construction, which is what makes tiles join up:
 *
 *   Each edge carries a PORT - a segment of fixed width centred on the edge
 *   midpoint. A shape's boundary runs along its ports and is joined between
 *   them by circular arcs.
 *
 *   The arc joining edge i to edge j is centred where the two edge LINES meet.
 *   The radius then lies along each edge, so the arc leaves both edges at a
 *   right angle - which is exactly the condition for a band to continue
 *   smoothly into the neighbouring tile. It also fixes the radii:
 *
 *     adjacent edges   centre is their shared vertex, sweeping 120 degrees
 *     two apart        centre sits outside the tile at sqrt(3)R, sweeping 60
 *     opposite         the edge lines are parallel, so the band is straight
 *
 * Overlapping shapes simply merge - same fill, no over/under.
 */
final class HexRenderer
{
    public array $o;

    public const DEFAULTS = [
        'radius'   => 100.0,   // circumradius; for a regular hexagon this is also the side
        'port'     => 0.60,    // port width as a fraction of the side
        'padding'  => 3.0,
        'bg'       => '#191614',
        'fill'     => '#a8804f',
        'stroke'   => '#191614',   // a seam the colour of the ground
        'strokeW'  => 2.5,
        'overRule' => 'small',     // small | large - which shape sits on top
        'rim'      => '#4a4038',
        'showRim'  => true,
    ];

    /** Ready-made colourways: [background, shape fill, seam]. */
    public const PALETTES = [
        'stone'  => ['#191614', '#a8804f', '#191614', 'Stone'],
        'ink'    => ['#f2eee6', '#201c18', '#f2eee6', 'Ink on paper'],
        'slate'  => ['#1b2227', '#8fa6b2', '#1b2227', 'Slate'],
        'rust'   => ['#1e1411', '#b5563a', '#1e1411', 'Rust'],
        'moss'   => ['#131811', '#829463', '#131811', 'Moss'],
        'bone'   => ['#2a2622', '#e6ddcc', '#2a2622', 'Bone'],
        'outline'=> ['#191614', '#a8804f', '#e6ddcc', 'Lit seam'],
    ];

    public function __construct(array $opts = [])
    {
        $this->o = array_merge(self::DEFAULTS, $opts);
    }

    /**
     * A #rrggbb from user input, or the fallback. Anything not a plain hex
     * colour is rejected rather than interpolated into the SVG, since these
     * values land straight in a fill attribute.
     */
    public static function colour($value, string $fallback): string
    {
        $v = is_string($value) ? trim($value) : '';
        if ($v !== '' && $v[0] !== '#') {
            $v = '#' . $v;
        }
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? $v : $fallback;
    }

    /** Resolve bg/fill/stroke from a query string, honouring ?palette=. */
    public static function coloursFrom(array $q): array
    {
        $d = self::DEFAULTS;
        $base = [$d['bg'], $d['fill'], $d['stroke']];

        $name = (string) ($q['palette'] ?? '');
        if (isset(self::PALETTES[$name])) {
            $base = array_slice(self::PALETTES[$name], 0, 3);
        }

        return [
            'bg'     => self::colour($q['bg']   ?? null, $base[0]),
            'fill'   => self::colour($q['fill'] ?? null, $base[1]),
            'stroke' => self::colour($q['ink']  ?? null, $base[2]),
        ];
    }

    private function R(): float
    {
        return (float) $this->o['radius'];
    }

    /** Half the port width, in length units along an edge. */
    private function h(): float
    {
        return $this->R() * (float) $this->o['port'] / 2;
    }

    /** Vertex k. Angles are measured in SVG space, y downwards. */
    private function vtx(int $k): array
    {
        // NB the modulo must be fully parenthesised: * and % bind equally and
        // left-to-right, so 60.0 * (...) % 6 would reduce the whole product.
        $step = ((($k % 6) + 6) % 6);
        $a    = deg2rad(30.0 + 60.0 * $step);
        return [$this->R() * cos($a), $this->R() * sin($a)];
    }

    private function edgeMid(int $k): array
    {
        $a = $this->vtx($k);
        $b = $this->vtx($k + 1);
        return [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    }

    private function edgeUnit(int $k): array
    {
        $a = $this->vtx($k);
        $b = $this->vtx($k + 1);
        $l = hypot($b[0] - $a[0], $b[1] - $a[1]);
        return [($b[0] - $a[0]) / $l, ($b[1] - $a[1]) / $l];
    }

    /** Port endpoints of edge k: [A near vertex k, B near vertex k+1]. */
    private function port(int $k): array
    {
        $m = $this->edgeMid($k);
        $u = $this->edgeUnit($k);
        $h = $this->h();
        return [
            [$m[0] - $h * $u[0], $m[1] - $h * $u[1]],
            [$m[0] + $h * $u[0], $m[1] + $h * $u[1]],
        ];
    }

    /** Where the lines carrying edges k and j meet, or null if parallel. */
    private function lineMeet(int $k, int $j): ?array
    {
        $p = $this->vtx($k);
        $d = $this->edgeUnit($k);
        $q = $this->vtx($j);
        $e = $this->edgeUnit($j);

        $den = $d[0] * $e[1] - $d[1] * $e[0];
        if (abs($den) < 1e-9) {
            return null;
        }
        $t = (($q[0] - $p[0]) * $e[1] - ($q[1] - $p[1]) * $e[0]) / $den;
        return [$p[0] + $t * $d[0], $p[1] + $t * $d[1]];
    }

    private function insideHex(array $p): bool
    {
        $in = false;
        for ($i = 0, $j = 5; $i < 6; $j = $i++) {
            $a = $this->vtx($i);
            $b = $this->vtx($j);
            if ((($a[1] > $p[1]) !== ($b[1] > $p[1]))
                && ($p[0] < ($b[0] - $a[0]) * ($p[1] - $a[1]) / ($b[1] - $a[1]) + $a[0])) {
                $in = !$in;
            }
        }
        return $in;
    }

    private static function fmt(array $p): string
    {
        return round($p[0], 3) . ',' . round($p[1], 3);
    }

    /**
     * SVG arc command from the current point to $Q about centre $C, choosing
     * the sweep that stays inside the tile.
     */
    private function arc(array $C, array $P, array $Q): string
    {
        $r  = hypot($P[0] - $C[0], $P[1] - $C[1]);
        $a0 = atan2($P[1] - $C[1], $P[0] - $C[0]);
        $a1 = atan2($Q[1] - $C[1], $Q[0] - $C[0]);

        $pos = fmod($a1 - $a0 + 2 * M_PI, 2 * M_PI);   // counter-clockwise in SVG terms
        $neg = $pos - 2 * M_PI;

        $best = $pos;
        foreach ([$pos, $neg] as $d) {
            $mid = [$C[0] + $r * cos($a0 + $d / 2), $C[1] + $r * sin($a0 + $d / 2)];
            if ($this->insideHex($mid)) {
                $best = $d;
                break;
            }
        }

        $large = abs($best) > M_PI ? 1 : 0;
        $sweep = $best > 0 ? 1 : 0;

        return 'A' . round($r, 3) . ',' . round($r, 3) . ' 0 ' . $large . ' ' . $sweep . ' ' . self::fmt($Q);
    }

    /**
     * Terminal cap: a perfect semicircle standing on the edge.
     *
     * The port itself is the diameter, so the centre is the edge midpoint and
     * the radius is half the port width. There is nothing to tune here - the
     * shape follows from the port, and a terminal is exactly a half-disc.
     */
    private function cap(int $k, array $B, array $A): string
    {
        return $this->arc($this->edgeMid($k), $B, $A);
    }

    /** Closed path for one shape. */
    public function shapePath(array $block): string
    {
        sort($block);
        $n = count($block);
        if ($n === 0) {
            return '';
        }

        if ($n === 1) {
            [$A, $B] = $this->port($block[0]);
            return 'M' . self::fmt($A) . ' L' . self::fmt($B) . ' ' . $this->cap($block[0], $B, $A) . ' Z';
        }

        $d = '';
        foreach ($block as $i => $k) {
            $j = $block[($i + 1) % $n];
            [$A, $B]   = $this->port($k);
            [$Aj, ]    = $this->port($j);

            $d .= ($i === 0 ? 'M' . self::fmt($A) . ' ' : '') . 'L' . self::fmt($B) . ' ';

            $C = $this->lineMeet($k, $j);
            $d .= ($C === null ? 'L' . self::fmt($Aj) : $this->arc($C, $B, $Aj)) . ' ';
        }
        return trim($d) . ' Z';
    }

    /**
     * The shape's boundary MINUS its ports: just the arcs.
     *
     * Stroking the ports would draw a seam along the tile edge, and the band is
     * supposed to run straight into the neighbouring tile there. So the outline
     * is emitted as one open sub-path per arc and the ports stay bare.
     *
     * @return string[]
     */
    public function shapeArcs(array $block): array
    {
        sort($block);
        $n = count($block);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            [$A, $B] = $this->port($block[0]);
            return ['M' . self::fmt($B) . ' ' . $this->cap($block[0], $B, $A)];
        }

        $out = [];
        foreach ($block as $i => $k) {
            $j = $block[($i + 1) % $n];
            [, $B]  = $this->port($k);
            [$Aj, ] = $this->port($j);
            $C = $this->lineMeet($k, $j);
            $out[] = 'M' . self::fmt($B) . ' '
                . ($C === null ? 'L' . self::fmt($Aj) : $this->arc($C, $B, $Aj));
        }
        return $out;
    }

    /**
     * Bottom-to-top draw order.
     *
     * The rule has to be ROTATION-INVARIANT, or the six rotations of a tile
     * would not look like rotations of one another. That rules out anything
     * keyed on absolute edge position, so shapes are ranked by how many edges
     * they touch, then by shape class. The final tie-break on lowest edge index
     * is not rotation-invariant, but it only fires between two shapes of the
     * same class - three Straights, say - where no invariant rule can choose,
     * because the tile maps onto itself under rotation.
     *
     * @return int[] block indices, first drawn first
     */
    public function drawOrder(HexTile $tile): array
    {
        $idx = array_keys($tile->blocks);
        $big = ((string) $this->o['overRule']) === 'large';

        usort($idx, function (int $a, int $b) use ($tile, $big): int {
            $ba = $tile->blocks[$a];
            $bb = $tile->blocks[$b];
            $c  = count($ba) <=> count($bb);
            if ($c !== 0) {
                return $big ? $c : -$c;          // 'small' on top => big drawn first
            }
            $c = strcmp(HexTile::gapSignature($ba), HexTile::gapSignature($bb));
            if ($c !== 0) {
                return $c;
            }
            return ($ba[0] ?? 0) <=> ($bb[0] ?? 0);
        });

        return $idx;
    }

    private function hexPoints(): string
    {
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $out[] = self::fmt($this->vtx($i));
        }
        return implode(' ', $out);
    }

    /**
     * The shapes alone, centred on the origin - no wrapper, no background.
     * Used both by svg() and by the tiler, which places many of these on one
     * plane and paints a single background behind the lot.
     */
    public function body(HexTile $tile): string
    {
        $svg = '';
        $sw = (float) $this->o['strokeW'];
        foreach ($this->drawOrder($tile) as $bi) {
            $block = $tile->blocks[$bi];
            $p = $this->shapePath($block);
            if ($p === '') {
                continue;
            }
            // fill then outline, shape by shape, so a shape higher in the order
            // buries the outline of anything it overlaps
            $svg .= '<path d="' . $p . '" fill="' . $this->o['fill'] . '"/>';
            if ($sw > 0) {
                foreach ($this->shapeArcs($block) as $arc) {
                    $svg .= '<path d="' . $arc . '" fill="none" stroke="' . $this->o['stroke']
                         . '" stroke-width="' . $sw . '" stroke-linecap="butt"/>';
                }
            }
        }

        return $svg;
    }

    public function svg(HexTile $tile, float $displaySize = 150.0): string
    {
        $box = $this->R() + (float) $this->o['padding'];
        $hex = $this->hexPoints();

        $svg = '<svg class="tile" width="' . $displaySize . '" height="' . $displaySize
             . '" viewBox="' . (-$box) . ' ' . (-$box) . ' ' . (2 * $box) . ' ' . (2 * $box) . '">'
             . '<polygon points="' . $hex . '" fill="' . $this->o['bg'] . '"/>'
             . $this->body($tile);

        if (!empty($this->o['showRim'])) {
            $svg .= '<polygon points="' . $hex . '" fill="none" stroke="' . $this->o['rim']
                 . '" stroke-width="1.2"/>';
        }
        return $svg . '</svg>';
    }

    /** Outline of the hexagon itself, for showing the grid on a tiling. */
    public function rimPath(): string
    {
        return '<polygon points="' . $this->hexPoints() . '" fill="none" stroke="'
            . $this->o['rim'] . '" stroke-width="1"/>';
    }

    /** Centre-to-centre spacing of a pointy-top hex grid: [dx, dy]. */
    public function spacing(): array
    {
        return [sqrt(3) * $this->R(), 1.5 * $this->R()];
    }
}
