<?php
declare(strict_types=1);

/**
 * Generator for the sigil script.
 *
 * Revised after a second, much larger sample. Three rules from the first pass
 * were wrong, and they were wrong in the direction of being too restrictive:
 *
 *   T-JUNCTIONS exist. The corpus has clear |-, -|, _|_ and I-beam forms, so a
 *   stroke may land on the middle of another stroke, not only on its end.
 *
 *   ARROWHEADS exist. Several unambiguous up, down and right arrows with a V
 *   head. So a free end has three possible treatments, not two: bare, ringed,
 *   or barbed.
 *
 *   CLOSED SHAPES exist. Squares, diamonds and triangles, and one rounded
 *   rectangle with three dots sitting inside it - so a loop can also be a
 *   container.
 *
 * What survived unchanged: endpoints sit on a lattice (the artist is working on
 * graph paper, so the angle family falls out rather than being chosen); rings
 * cap ends and corners but never the middle of a stroke; bundles stay detached
 * from everything.
 */
final class Glyph
{
    public const DEFAULTS = [
        'lattice'    => 5,
        'minSeg'     => 2,
        'maxSeg'     => 5,
        'maxStep'    => 4,      // 4 spans the lattice corner to corner

        // Every stroke is vertical, horizontal or a true 45 - nothing else.
        // The earlier 2:1 slopes were my misreading: the photographs are taken
        // at an angle, so the cells render as parallelograms and a real 45
        // measures as something shallower.
        'wOrtho'     => 48,     // 0 and 90 degrees
        'wDiag'      => 52,     // 45 degrees

        // Step lengths. The dominant mark is a long stroke crossing most of the
        // cell; short ones are mostly bundle bars. Middling is the rarest.
        'wLen1'      => 30,
        'wLen2'      => 10,
        'wLen3'      => 26,
        'wLen4'      => 40,

        'pClose'     => 0.22,   // shut the walk into a loop when it can
        'pBranch'    => 0.26,   // hang a T-junction off a stroke
        'pTwoRings'  => 0.45,   // one ring or two - never none, never three
        'pSpur'      => 0.30,   // a third stroke off an existing vertex
        'pAccessory' => 0.62,
        // Dots are rare: about one glyph in twelve carries any. They used to
        // arrive from three separate places - two accessory types and the void
        // filler - so they turned up in most cells.
        'pDots'      => 0.083,
        // Six glyphs in sixteen carry a tripled-line element. Like the dots,
        // this used to be the sum of an accessory roll and the void filler,
        // so the rate was whatever those happened to produce.
        'pBundle'    => 0.375,
        'wDots'      => 14,
        'wStub'      => 26,
        'wDot'       => 10,
        'bundleN'    => 3,      // small lines come in threes
        'pBundle4'   => 0.12,   // occasionally four
        'pCornerStart' => 0.70, // the walk likes to begin at a cell corner
        'pTangent'   => 0.38,   // ring meets the stroke side-on, not head-on
        'fillGap'    => 0.215,  // a void wider than this gets something put in it
        'maxFill'    => 3,
        'ring'       => 0.070,
        'stroke'     => 0.040,
        'margin'     => 0.130,
        'jitter'     => 0.012,
        'clearance'  => 0.080,
        // Two strokes running parallel close together read as a mistake rather
        // than as writing. Bundles are exempt - being parallel is the whole
        // point of them - but any other pair must be this far apart.
        'parallelGap' => 0.26,
    ];

    /** @var array[] [x1,y1,x2,y2] in cell units 0..1 */
    public array $segments = [];
    /** @var array[] [x,y,r] */
    public array $rings = [];
    /** @var array[] [x,y] */
    public array $dots = [];
    /** @var array[] closed loops, as lists of [x,y] - used to place dots inside */
    public array $loops = [];
    /** @var int[] indices into $segments belonging to a bundle, so they can be checked */
    public array $bars = [];
    /**
     * How many tripled-line elements the glyph carries.
     *
     * A glyph has one or none, never two - and bundles could previously arrive
     * from two separate places, the accessory and the void filler, so a cell
     * could collect four of them.
     */
    public int $bundleCount = 0;
    /** Dot groups on this glyph: at most one, and usually none. */
    public int $dotGroups = 0;

    /** Decided once per glyph, so every path that could place one agrees. */
    private bool $allowDots = false;
    private bool $allowBundle = false;

    private array $o;
    private int $n;
    /** Points where a stroke is allowed to touch another, i.e. T-junctions. */
    private array $touchOk = [];

    private function __construct(array $o)
    {
        $this->o = $o;
        $this->n = max(3, min(9, (int) $o['lattice']));
    }

    // ------------------------------------------------------------- geometry

    private function pt(int $i, int $j): array
    {
        $m    = (float) $this->o['margin'];
        $span = 1.0 - 2 * $m;
        $step = $span / ($this->n - 1);
        $jit  = (float) $this->o['jitter'];
        return [
            $m + $i * $step + $this->rand(-$jit, $jit),
            $m + $j * $step + $this->rand(-$jit, $jit),
        ];
    }

    private function rand(float $lo, float $hi): float
    {
        return $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
    }

    /** The four cell corners, without jitter, so they can be recognised later. */
    private function corners(): array
    {
        $m = (float) $this->o['margin'];
        return [[$m, $m], [1 - $m, $m], [$m, 1 - $m], [1 - $m, 1 - $m]];
    }

    /**
     * Is this point one of the cell's corners?
     *
     * Rings are a corner habit in the corpus - they sit in the corners of the
     * cell far more often than anywhere else - so capping is gated on this
     * rather than applied to any old terminal.
     */
    private function nearCorner(array $p): bool
    {
        $tol = (float) $this->o['jitter'] * 2.0 + 1e-3;
        foreach ($this->corners() as $c) {
            if (hypot($p[0] - $c[0], $p[1] - $c[1]) < $tol) {
                return true;
            }
        }
        return false;
    }

    /** How far is this point from any ink already on the cell? */
    private function distanceToInk(array $p): float
    {
        $best = 1.0;
        foreach ($this->segments as $s) {
            $best = min($best, self::pointToSegment($p, $s));
        }
        foreach ($this->rings as $r) {
            $best = min($best, abs(hypot($p[0] - $r[0], $p[1] - $r[1]) - $r[2]));
        }
        foreach ($this->dots as $d) {
            $best = min($best, hypot($p[0] - $d[0], $p[1] - $d[1]));
        }
        return $best;
    }

    private function step(): float
    {
        return (1.0 - 2 * (float) $this->o['margin']) / ($this->n - 1);
    }

    /** Primitive lattice directions, components at most 2. */
    private static function directions(): array
    {
        static $d = null;
        if ($d !== null) {
            return $d;
        }
        // Eight directions, full stop: the four orthogonals and the four
        // diagonals. Anything with a 2:1 component is not in this script.
        $d = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ($dx || $dy) {
                    $d[] = [$dx, $dy];
                }
            }
        }
        return $d;
    }

    /** Which family a direction belongs to: orthogonal, diagonal, or slope. */
    private function dirWeight(array $d): int
    {
        $ax = abs($d[0]);
        $ay = abs($d[1]);
        if ($ax === 0 || $ay === 0) {
            return (int) $this->o['wOrtho'];
        }
        return (int) $this->o['wDiag'];
    }

    /**
     * Directions to try, in weighted-random order.
     *
     * The walk takes the first direction that fits, so the ORDER is what biases
     * the result. Shuffling a flat list and taking the first legal one gives
     * every angle an equal chance, which is what produced too many odd slopes.
     *
     * @return array[] all sixteen, likely ones first
     */
    private function directionOrder(): array
    {
        $pool = [];
        foreach (self::directions() as $d) {
            for ($i = 0, $w = max(1, $this->dirWeight($d)); $i < $w; $i++) {
                $pool[] = $d;
            }
        }
        shuffle($pool);

        $seen = [];
        $out  = [];
        foreach ($pool as $d) {
            $k = $d[0] . ',' . $d[1];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $d;
            }
        }
        return $out;
    }

    /** A single weighted direction, for the detached pieces. */
    private function pickDirection(): array
    {
        return $this->directionOrder()[0];
    }

    /**
     * Horizontal or vertical only.
     *
     * The tripled lines are never diagonal in the source - always upright or
     * flat - so bundles pick from the orthogonals rather than all eight.
     */
    private function pickOrthogonal(): array
    {
        return [[1, 0], [0, 1]][mt_rand(0, 1)];
    }

    /** Step lengths to try, weighted so middling strokes are rarest. */
    private function stepOrder(): array
    {
        $max = max(1, (int) $this->o['maxStep']);
        $w   = [
            1 => (int) $this->o['wLen1'],
            2 => (int) $this->o['wLen2'],
            3 => (int) $this->o['wLen3'],
            4 => (int) $this->o['wLen4'],
        ];

        $pool = [];
        for ($len = 1; $len <= $max; $len++) {
            for ($i = 0, $n = max(1, $w[$len] ?? 10); $i < $n; $i++) {
                $pool[] = $len;
            }
        }
        shuffle($pool);

        $seen = [];
        $out  = [];
        foreach ($pool as $len) {
            if (!isset($seen[$len])) {
                $seen[$len] = true;
                $out[] = $len;
            }
        }
        return $out;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max($a, 1);
    }

    private static function same(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-7 && abs($a[1] - $b[1]) < 1e-7;
    }

    /**
     * Do two segments properly cross?
     *
     * Shared endpoints do not count, and neither does a touch at a point that
     * was deliberately created as a T-junction - otherwise every branch would
     * be rejected as a crossing.
     */
    private function crosses(array $p, array $q): bool
    {
        $d = static fn (array $a, array $b, array $c): float =>
            ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);

        $a = [$p[0], $p[1]];
        $b = [$p[2], $p[3]];
        $c = [$q[0], $q[1]];
        $e = [$q[2], $q[3]];

        foreach ([[$a, $c], [$a, $e], [$b, $c], [$b, $e]] as [$u, $v]) {
            if (self::same($u, $v)) {
                return false;
            }
        }
        foreach ($this->touchOk as $t) {
            foreach ([$a, $b] as $end) {
                if (self::same($end, $t) && self::pointToSegment($t, $q) < 1e-6) {
                    return false;
                }
            }
        }

        $d1 = $d($a, $b, $c);
        $d2 = $d($a, $b, $e);
        $d3 = $d($c, $e, $a);
        $d4 = $d($c, $e, $b);
        return (($d1 > 0) !== ($d2 > 0)) && (($d3 > 0) !== ($d4 > 0));
    }

    /** Closest approach between two segments that do not cross. */
    private static function segmentDistance(array $a, array $b): float
    {
        return min(
            self::pointToSegment([$a[0], $a[1]], $b),
            self::pointToSegment([$a[2], $a[3]], $b),
            self::pointToSegment([$b[0], $b[1]], $a),
            self::pointToSegment([$b[2], $b[3]], $a)
        );
    }

    /**
     * Would this stroke run alongside one already drawn?
     *
     * Parallel lines are uncommon in the source, and where they do occur they
     * are well separated. Bundle bars are excluded, since they are deliberately
     * parallel to one another.
     */
    private function tooParallel(array $cand): bool
    {
        $gap = (float) $this->o['parallelGap'];
        if ($gap <= 0) {
            return false;
        }

        $cx = $cand[2] - $cand[0];
        $cy = $cand[3] - $cand[1];
        $cl = hypot($cx, $cy);
        if ($cl < 1e-9) {
            return false;
        }

        $bars = array_flip($this->bars);
        foreach ($this->segments as $i => $s) {
            if (isset($bars[$i])) {
                continue;
            }
            $sx = $s[2] - $s[0];
            $sy = $s[3] - $s[1];
            $sl = hypot($sx, $sy);
            if ($sl < 1e-9) {
                continue;
            }
            // parallel when the unit vectors have no cross product either way
            $cross = abs(($cx / $cl) * ($sy / $sl) - ($cy / $cl) * ($sx / $sl));
            if ($cross > 0.12) {
                continue;
            }
            if (self::segmentDistance($cand, $s) < $gap) {
                return true;
            }
        }
        return false;
    }

    private static function pointToSegment(array $pt, array $s): float
    {
        $vx = $s[2] - $s[0];
        $vy = $s[3] - $s[1];
        $len = $vx * $vx + $vy * $vy;
        if ($len < 1e-12) {
            return hypot($pt[0] - $s[0], $pt[1] - $s[1]);
        }
        $t = max(0.0, min(1.0, (($pt[0] - $s[0]) * $vx + ($pt[1] - $s[1]) * $vy) / $len));
        return hypot($pt[0] - ($s[0] + $t * $vx), $pt[1] - ($s[1] + $t * $vy));
    }

    private function clear(array $pt, float $need): bool
    {
        foreach ($this->segments as $s) {
            if (self::pointToSegment($pt, $s) < $need) {
                return false;
            }
        }
        foreach ($this->rings as $r) {
            if (hypot($pt[0] - $r[0], $pt[1] - $r[1]) < $need + $r[2]) {
                return false;
            }
        }
        foreach ($this->dots as $d) {
            if (hypot($pt[0] - $d[0], $pt[1] - $d[1]) < $need) {
                return false;
            }
        }
        return true;
    }

    /** A whole segment, not just its ends - a bar can cross through the middle. */
    private function segmentClear(array $a, array $b, float $need): bool
    {
        $cand = [$a[0], $a[1], $b[0], $b[1]];
        foreach ($this->segments as $s) {
            if ($this->crosses($cand, $s)) {
                return false;
            }
        }
        for ($i = 0; $i <= 6; $i++) {
            $t = $i / 6;
            if (!$this->clear([$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t], $need)) {
                return false;
            }
        }
        return true;
    }

    private static function inPolygon(array $pt, array $poly): bool
    {
        $in = false;
        $c  = count($poly);
        for ($i = 0, $j = $c - 1; $i < $c; $j = $i++) {
            if ((($poly[$i][1] > $pt[1]) !== ($poly[$j][1] > $pt[1]))
                && ($pt[0] < ($poly[$j][0] - $poly[$i][0]) * ($pt[1] - $poly[$i][1])
                    / ($poly[$j][1] - $poly[$i][1]) + $poly[$i][0])) {
                $in = !$in;
            }
        }
        return $in;
    }

    // ----------------------------------------------------------- the walk

    /**
     * Self-avoiding walk on the lattice. When `close` is set it will shut the
     * loop if the start is a legal step away, which is what produces the
     * squares, diamonds and triangles in the corpus.
     *
     * @return array{path:array,segs:array,closed:bool}
     */
    private function walk(int $want, bool $close, float $clearFrom = 0.0, ?array $startAt = null): array
    {
        if ($startAt !== null) {
            $start = $startAt;
        } elseif ($this->rand(0, 1) < (float) $this->o['pCornerStart']) {
            $k = $this->n - 1;
            $start = [[0, 0], [$k, 0], [0, $k], [$k, $k]][mt_rand(0, 3)];
        } else {
            $start = [mt_rand(0, $this->n - 1), mt_rand(0, $this->n - 1)];
        }
        $path  = [$start];
        $segs  = [];
        $last  = null;
        $closed = false;

        for ($k = 0; $k < $want; $k++) {
            $cur = end($path);

            // try to shut the loop once there is enough of one to shut
            if ($close && count($path) >= 3 && $this->rand(0, 1) < (float) $this->o['pClose']) {
                $seg = $this->tryStep($cur, $start, $segs);
                if ($seg !== null) {
                    $segs[] = $seg;
                    $closed = true;
                    break;
                }
            }

            $dirs = $this->directionOrder();
            $placed = false;

            foreach ($dirs as $d) {
                if ($last !== null
                    && (($d[0] === -$last[0] && $d[1] === -$last[1])
                        || ($d[0] === $last[0] && $d[1] === $last[1]))) {
                    continue;
                }
                $steps = $this->stepOrder();

                foreach ($steps as $len) {
                    $next = [$cur[0] + $d[0] * $len, $cur[1] + $d[1] * $len];
                    if ($next[0] < 0 || $next[0] >= $this->n || $next[1] < 0 || $next[1] >= $this->n) {
                        continue;
                    }
                    foreach ($path as $seen) {
                        if ($seen === $next) {
                            continue 2;
                        }
                    }
                    $seg = $this->tryStep($cur, $next, $segs);
                    if ($seg === null) {
                        continue;
                    }
                    if ($clearFrom > 0) {
                        $b = [$seg[2], $seg[3]];
                        if (!$this->clear($b, $clearFrom)) {
                            continue;
                        }
                    }
                    $segs[] = $seg;
                    $path[] = $next;
                    $last   = $d;
                    $placed = true;
                    break 2;
                }
            }
            if (!$placed) {
                break;
            }
        }

        foreach ($segs as $s) {
            $this->segments[] = $s;
        }
        return ['path' => $path, 'segs' => $segs, 'closed' => $closed];
    }

    /** Legal step from lattice point a to b, or null. */
    /** A legal step from lattice point $a to $b, or null. */
    private function tryStep(array $a, array $b, array $pending): ?array
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        if ($dx === 0 && $dy === 0) {
            return null;
        }
        // The step must be a whole number of moves along one of the eight
        // directions: |dx| and |dy| are equal, or one of them is zero.
        $ax = abs($dx);
        $ay = abs($dy);
        if ($ax !== 0 && $ay !== 0 && $ax !== $ay) {
            return null;
        }
        if (max($ax, $ay) > (int) $this->o['maxStep']) {
            return null;
        }

        $pa = $this->pt($a[0], $a[1]);
        $pb = $this->pt($b[0], $b[1]);
        $cand = [$pa[0], $pa[1], $pb[0], $pb[1]];

        foreach (array_merge($this->segments, $pending) as $s) {
            if ($this->crosses($cand, $s)) {
                return null;
            }
        }
        if ($this->tooParallel($cand)) {
            return null;
        }
        foreach ($pending as $s) {          // and against this walk's own strokes
            $sx = $s[2] - $s[0];
            $sy = $s[3] - $s[1];
            $sl = hypot($sx, $sy);
            $cx = $cand[2] - $cand[0];
            $cy = $cand[3] - $cand[1];
            $cl = hypot($cx, $cy);
            if ($sl < 1e-9 || $cl < 1e-9) {
                continue;
            }
            $cross = abs(($cx / $cl) * ($sy / $sl) - ($cy / $cl) * ($sx / $sl));
            if ($cross <= 0.12
                && self::segmentDistance($cand, $s) < (float) $this->o['parallelGap']) {
                return null;
            }
        }
        return $cand;
    }

    // ------------------------------------------------------- end treatments

    /**
     * Spend the ring budget.
     *
     * Every glyph in the corpus carries one ring or two - never none, never
     * three. Letting rings fall out of several independent rules made the count
     * wander, so it is decided first and then spent on the best sites: cell
     * corners for preference, then free ends, then anything.
     */
    private function placeRings(int $target): void
    {
        $r = (float) $this->o['ring'];

        // count how many strokes meet at each point, so free ends stand out
        $tally = [];
        foreach ($this->segments as $idx => $seg) {
            foreach ([[$seg[0], $seg[1], $idx, 0], [$seg[2], $seg[3], $idx, 1]] as $e) {
                $key = round($e[0], 4) . ',' . round($e[1], 4);
                if (!isset($tally[$key])) {
                    $tally[$key] = ['p' => [$e[0], $e[1]], 'n' => 0, 'seg' => $e[2], 'end' => $e[3]];
                }
                $tally[$key]['n']++;
            }
        }

        $sites = [];
        foreach ($tally as $t) {
            $sites[] = ['p' => $t['p'], 'corner' => $this->nearCorner($t['p']),
                        'free' => $t['n'] === 1, 'seg' => $t['seg'], 'end' => $t['end']];
        }
        foreach ($this->corners() as $c) {      // a ring needs no stroke on it
            $sites[] = ['p' => $c, 'corner' => true, 'free' => false, 'seg' => null, 'end' => 0];
        }

        shuffle($sites);
        usort($sites, static function (array $a, array $b): int {
            if ($a['corner'] !== $b['corner']) {
                return $a['corner'] ? -1 : 1;
            }
            return ($b['free'] ? 1 : 0) <=> ($a['free'] ? 1 : 0);
        });

        $placed = 0;
        foreach ($sites as $site) {
            if ($placed >= $target) {
                break;
            }
            $p = $site['p'];
            if ($p[0] < $r || $p[0] > 1 - $r || $p[1] < $r || $p[1] > 1 - $r) {
                continue;
            }
            foreach ($this->rings as $ex) {      // rings never touch each other
                if (hypot($p[0] - $ex[0], $p[1] - $ex[1]) < ($r + $ex[2]) * 1.5) {
                    continue 2;
                }
            }
            if ($site['seg'] === null && !$this->clear($p, $r * 1.4)) {
                continue;
            }
            $this->attachRingAt($p, $site['seg'], $site['end'], $r);
            $placed++;
        }

        if ($placed === 0) {                     // never leave a glyph bare
            foreach ($this->corners() as $c) {
                if ($c[0] > $r && $c[1] > $r) {
                    $this->rings[] = [$c[0], $c[1], $r];
                    return;
                }
            }
        }
    }

    /**
     * Shorten every stroke meeting this point so it stops at the ring's edge
     * rather than running through to its centre. This is what makes a capped
     * end read as a lollipop instead of a bead threaded on a line.
     */
    private function pullBack(array $at, float $r): void
    {
        foreach ($this->segments as $k => $seg) {
            for ($e = 0; $e < 2; $e++) {
                $ex = $seg[$e * 2];
                $ey = $seg[$e * 2 + 1];
                if (hypot($ex - $at[0], $ey - $at[1]) > 1e-9) {
                    continue;
                }
                $ox = $seg[(1 - $e) * 2];
                $oy = $seg[(1 - $e) * 2 + 1];
                $len = hypot($ox - $ex, $oy - $ey);
                if ($len <= $r * 1.2) {
                    continue;
                }
                $this->segments[$k][$e * 2]     = $ex + ($ox - $ex) / $len * $r;
                $this->segments[$k][$e * 2 + 1] = $ey + ($oy - $ey) / $len * $r;
            }
        }
    }

    /** Radial, or tangent so the stroke leaves the circle's edge sideways. */
    private function attachRingAt(array $at, ?int $segIndex, int $end, float $r): void
    {
        if ($segIndex !== null && isset($this->segments[$segIndex])
            && $this->rand(0, 1) < (float) $this->o['pTangent']) {
            $seg = $this->segments[$segIndex];
            $far = $end === 0 ? [$seg[2], $seg[3]] : [$seg[0], $seg[1]];
            $len = hypot($far[0] - $at[0], $far[1] - $at[1]);
            if ($len > 1e-6) {
                $ux = ($far[0] - $at[0]) / $len;
                $uy = ($far[1] - $at[1]) / $len;
                $sign = mt_rand(0, 1) ? 1 : -1;
                $c = [$at[0] - $uy * $sign * $r, $at[1] + $ux * $sign * $r];
                if ($c[0] > $r && $c[0] < 1 - $r && $c[1] > $r && $c[1] < 1 - $r) {
                    $this->rings[] = [$c[0], $c[1], $r];
                    return;
                }
            }
        }
        $this->rings[] = [$at[0], $at[1], $r];
        if ($segIndex !== null) {
            $this->pullBack($at, $r);
        }
    }

    /**
     * A third stroke leaving a vertex the walk already visited.
     *
     * What looked like arrowheads in the corpus are these: points where three
     * lines converge. Drawing actual barbs was wrong twice over - it invented a
     * terminal decoration that is not in the vocabulary, and it put strokes at
     * angles off the lattice, which nothing else in the script does.
     */
    private function spur(array $path): void
    {
        if (count($path) < 2) {
            return;
        }
        $order = $path;
        shuffle($order);

        foreach ($order as $vertex) {
            $dirs = $this->directionOrder();
            foreach ($dirs as $d) {
                $steps = $this->stepOrder();
                foreach ($steps as $len) {
                    $next = [$vertex[0] + $d[0] * $len, $vertex[1] + $d[1] * $len];
                    if ($next[0] < 0 || $next[0] >= $this->n || $next[1] < 0 || $next[1] >= $this->n) {
                        continue;
                    }
                    foreach ($path as $seen) {
                        if ($seen === $next) {
                            continue 2;
                        }
                    }
                    $seg = $this->tryStep($vertex, $next, []);
                    if ($seg === null) {
                        continue;
                    }
                    $this->segments[] = $seg;
                    return;
                }
            }
        }
    }

    /** Hang a short stroke off the middle of an existing one. */
    private function branch(): void
    {
        if (!$this->segments) {
            return;
        }
        $step = $this->step();

        for ($try = 0; $try < 30; $try++) {
            $s = $this->segments[array_rand($this->segments)];
            $t = [0.33, 0.5, 0.67][mt_rand(0, 2)];
            $at = [$s[0] + ($s[2] - $s[0]) * $t, $s[1] + ($s[3] - $s[1]) * $t];

            $len = hypot($s[2] - $s[0], $s[3] - $s[1]);
            if ($len < 1e-6) {
                continue;
            }
            $ux = ($s[2] - $s[0]) / $len;
            $uy = ($s[3] - $s[1]) / $len;
            $sign = mt_rand(0, 1) ? 1 : -1;
            $L = $step * $this->rand(0.8, 1.8);
            $end = [$at[0] - $uy * $sign * $L, $at[1] + $ux * $sign * $L];

            if ($end[0] < 0.06 || $end[0] > 0.94 || $end[1] < 0.06 || $end[1] > 0.94) {
                continue;
            }

            $this->touchOk[] = $at;
            if (!$this->segmentClear($at, $end, 0.0)
                || $this->tooParallel([$at[0], $at[1], $end[0], $end[1]])) {
                array_pop($this->touchOk);
                continue;
            }

            $this->segments[] = [$at[0], $at[1], $end[0], $end[1]];
            return;         // its free end is simply another ring candidate
        }
    }

    // ---------------------------------------------------------- accessories

    private function addAccessory(): void
    {
        $w = [
            'dots'   => $this->allowDots ? (int) $this->o['wDots'] : 0,
            'stub'   => (int) $this->o['wStub'],
            'dot'    => $this->allowDots ? (int) $this->o['wDot'] : 0,
        ];
        $total = array_sum($w);
        if ($total <= 0) {
            return;
        }
        $roll = mt_rand(1, $total);
        $pick = 'stub';
        foreach ($w as $k => $v) {
            $roll -= $v;
            if ($roll <= 0) {
                $pick = $k;
                break;
            }
        }

        switch ($pick) {
            case 'dots':   $this->addDots(); break;
            case 'dot':    $this->addSingleDot(); break;
            case 'stub':   $this->addStub(); break;
        }
    }

    private function addBundle(): void
    {
        if (!$this->allowBundle || $this->bundleCount > 0) {
            return;                  // this glyph has none, or already has its one
        }
        $clear = (float) $this->o['clearance'];
        $span  = $this->step();

        for ($try = 0; $try < 50; $try++) {
            $d  = $this->pickOrthogonal();
            $dl = hypot($d[0], $d[1]);
            $ux = $d[0] / $dl;
            $uy = $d[1] / $dl;
            $px = -$uy;
            $py = $ux;

            $count = (int) $this->o['bundleN'];
            if ($this->rand(0, 1) < (float) $this->o['pBundle4']) {
                $count++;
            }
            $len   = $span * $this->rand(1.1, 2.6);
            $gap   = $span * $this->rand(0.26, 0.42);
            $c     = [$this->rand(0.25, 0.75), $this->rand(0.25, 0.75)];

            $lines = [];
            $ok = true;
            for ($i = 0; $i < $count; $i++) {
                $off = ($i - ($count - 1) / 2) * $gap;
                $mx = $c[0] + $px * $off;
                $my = $c[1] + $py * $off;
                $a = [$mx - $ux * $len / 2, $my - $uy * $len / 2];
                $b = [$mx + $ux * $len / 2, $my + $uy * $len / 2];

                foreach ([$a, $b] as $p) {
                    if ($p[0] < 0.05 || $p[0] > 0.95 || $p[1] < 0.05 || $p[1] > 0.95) {
                        $ok = false;
                        break 2;
                    }
                }
                if (!$this->segmentClear($a, $b, $clear)) {
                    $ok = false;
                    break;
                }
                $lines[] = [$a[0], $a[1], $b[0], $b[1]];
            }
            if ($ok) {
                foreach ($lines as $l) {
                    $this->bars[] = count($this->segments);
                    $this->segments[] = $l;
                }
                $this->bundleCount++;
                return;
            }
        }
    }

    private function addSingleDot(): void
    {
        if (!$this->allowDots || $this->dotGroups > 0) {
            return;
        }
        for ($try = 0; $try < 40; $try++) {
            $p = [$this->rand(0.15, 0.85), $this->rand(0.15, 0.85)];
            if ($this->clear($p, (float) $this->o['clearance'])) {
                $this->dots[] = $p;
                $this->dotGroups++;
                return;
            }
        }
    }

    /** Dots in a row, or tucked inside a loop if the glyph made one. */
    private function addDots(): void
    {
        if (!$this->allowDots || $this->dotGroups > 0) {
            return;
        }
        if ($this->loops && $this->rand(0, 1) < 0.55 && $this->dotsInLoop()) {
            return;
        }

        $span = $this->step();
        for ($try = 0; $try < 40; $try++) {
            $d  = $this->pickDirection();
            $dl = hypot($d[0], $d[1]);
            $ux = $d[0] / $dl;
            $uy = $d[1] / $dl;

            $count = mt_rand(3, 4);
            $gap   = $span * $this->rand(0.28, 0.42);
            $st    = [$this->rand(0.15, 0.85), $this->rand(0.15, 0.85)];

            $pts = [];
            $ok = true;
            for ($i = 0; $i < $count; $i++) {
                $p = [$st[0] + $ux * $gap * $i, $st[1] + $uy * $gap * $i];
                if ($p[0] < 0.07 || $p[0] > 0.93 || $p[1] < 0.07 || $p[1] > 0.93
                    || !$this->clear($p, (float) $this->o['clearance'])) {
                    $ok = false;
                    break;
                }
                $pts[] = $p;
            }
            if ($ok) {
                foreach ($pts as $p) {
                    $this->dots[] = $p;
                }
                $this->dotGroups++;
                return;
            }
        }
    }

    /** The rounded-rectangle-with-three-dots motif. */
    private function dotsInLoop(): bool
    {
        $poly = $this->loops[array_rand($this->loops)];
        $cx = 0.0;
        $cy = 0.0;
        foreach ($poly as $p) {
            $cx += $p[0];
            $cy += $p[1];
        }
        $cx /= count($poly);
        $cy /= count($poly);

        $count = mt_rand(2, 3);
        $gap   = $this->step() * 0.34;
        $pts = [];
        for ($i = 0; $i < $count; $i++) {
            $p = [$cx + ($i - ($count - 1) / 2) * $gap, $cy];
            if (!self::inPolygon($p, $poly) || !$this->clear($p, (float) $this->o['clearance'] * 0.6)) {
                return false;
            }
            $pts[] = $p;
        }
        foreach ($pts as $p) {
            $this->dots[] = $p;
        }
        $this->dotGroups++;
        return true;
    }

    private function addStub(): void
    {
        $before = count($this->segments);
        $w = $this->walk(mt_rand(1, 2), false, (float) $this->o['clearance']);
        // a second polyline wins no rings of its own; the budget is already spent
    }

    // -------------------------------------------------------------- build

    public static function generate(array $opts = []): self
    {
        $g = new self(array_merge(self::DEFAULTS, $opts));

        // settle the budgets before anything can try to spend them
        $g->allowDots   = $g->rand(0, 1) < (float) $g->o['pDots'];
        $g->allowBundle = $g->rand(0, 1) < (float) $g->o['pBundle'];

        $want = mt_rand((int) $g->o['minSeg'], (int) $g->o['maxSeg']);
        $w = $g->walk($want, true);

        if ($w['closed']) {
            $poly = [];
            foreach ($w['segs'] as $s) {
                $poly[] = [$s[0], $s[1]];
            }
            if (count($poly) >= 3) {
                $g->loops[] = $poly;
            }
        }

        // skeleton first, so every vertex is a candidate; then spend the rings
        if ($g->rand(0, 1) < (float) $g->o['pSpur']) {
            $g->spur($w['path']);
        }
        if ($g->rand(0, 1) < (float) $g->o['pBranch']) {
            $g->branch();
        }
        $g->placeRings($g->rand(0, 1) < (float) $g->o['pTwoRings'] ? 2 : 1);
        // the bundle is placed outright rather than competing for an accessory
        // slot, so its rate tracks pBundle instead of falling out of the weights
        if ($g->allowBundle) {
            $g->addBundle();
        }
        if ($g->rand(0, 1) < (float) $g->o['pAccessory']) {
            $g->addAccessory();
        }

        // The corpus has an even texture: where a glyph leaves a large hole, the
        // artist drops something small into it. So rather than one accessory and
        // done, keep looking for the emptiest spot and filling it.
        $g->fillVoids();
        return $g;
    }

    // ------------------------------------------------------------- density

    private function fillVoids(): void
    {
        $placed = 0;
        $guard  = 0;

        while ($placed < (int) $this->o['maxFill'] && $guard++ < 12) {
            $spots = $this->voidsBySize();
            if (!$spots) {
                return;
            }
            $any = false;
            foreach (array_slice($spots, 0, 4) as $spot) {
                if ($this->placeSmall($spot)) {
                    $placed++;
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                return;             // nothing fits anywhere; the cell is full
            }
        }
    }

    /** @return array[] empty spots, emptiest first */
    private function voidsBySize(): array
    {
        $out = [];
        for ($gx = 0; $gx <= 8; $gx++) {
            for ($gy = 0; $gy <= 8; $gy++) {
                $p = [0.14 + $gx * 0.09, 0.14 + $gy * 0.09];
                $d = $this->distanceToInk($p);
                if ($d > (float) $this->o['fillGap']) {
                    $out[] = [$p, $d];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        return array_map(static fn (array $r): array => $r[0], $out);
    }

    /** Drop a small element into a hole: three dots, three bars, or a ring. */
    private function placeSmall(array $at): bool
    {
        $order = ['dots', 'bars'];
        shuffle($order);
        foreach ($order as $kind) {
            if ($this->placeOne($at, $kind)) {
                return true;
            }
        }
        return false;
    }

    private function placeOne(array $at, string $kind): bool
    {
        $span  = $this->step();
        $clear = (float) $this->o['clearance'] * 0.75;

        if ($kind === 'dots') {                  // three dots in a row
            if (!$this->allowDots || $this->dotGroups > 0) {
                return false;        // the caller will try bars instead
            }
            for ($t = 0; $t < 12; $t++) {
                $d  = $this->pickDirection();
                $dl = hypot($d[0], $d[1]);
                $gap = $span * $this->rand(0.28, 0.40);
                $pts = [];
                $ok = true;
                for ($k = 0; $k < 3; $k++) {
                    $p = [$at[0] + $d[0] / $dl * $gap * ($k - 1),
                          $at[1] + $d[1] / $dl * $gap * ($k - 1)];
                    if ($p[0] < 0.07 || $p[0] > 0.93 || $p[1] < 0.07 || $p[1] > 0.93
                        || !$this->clear($p, $clear)) {
                        $ok = false;
                        break;
                    }
                    $pts[] = $p;
                }
                if ($ok) {
                    foreach ($pts as $p) {
                        $this->dots[] = $p;
                    }
                    $this->dotGroups++;
                    return true;
                }
            }
            return false;
        }

        if ($kind === 'bars') {                  // three short bars
            if (!$this->allowBundle || $this->bundleCount > 0) {
                return false;        // the caller will try dots instead
            }
            for ($t = 0; $t < 12; $t++) {
                $d  = $this->pickOrthogonal();
                $dl = hypot($d[0], $d[1]);
                $ux = $d[0] / $dl;
                $uy = $d[1] / $dl;
                $len = $span * $this->rand(0.8, 1.4);
                $gap = $span * $this->rand(0.26, 0.36);
                $lines = [];
                $ok = true;
                for ($k = -1; $k <= 1; $k++) {
                    $mx = $at[0] - $uy * $gap * $k;
                    $my = $at[1] + $ux * $gap * $k;
                    $a = [$mx - $ux * $len / 2, $my - $uy * $len / 2];
                    $b = [$mx + $ux * $len / 2, $my + $uy * $len / 2];
                    if (min($a[0], $b[0]) < 0.06 || max($a[0], $b[0]) > 0.94
                        || min($a[1], $b[1]) < 0.06 || max($a[1], $b[1]) > 0.94
                        || !$this->segmentClear($a, $b, $clear)) {
                        $ok = false;
                        break;
                    }
                    $lines[] = [$a[0], $a[1], $b[0], $b[1]];
                }
                if ($ok) {
                    foreach ($lines as $l) {
                        $this->bars[] = count($this->segments);
                        $this->segments[] = $l;
                    }
                    $this->bundleCount++;
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    // ------------------------------------------------------------- render

    public function svg(float $size, bool $showCell = false, string $ink = '#1d4f3f'): string
    {
        $w = (float) $this->o['stroke'] * $size;
        $f = static fn (float $v): string => (string) round($v, 2);

        $s = '<svg class="glyph" width="' . $size . '" height="' . $size
           . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
        if ($showCell) {
            $s .= '<rect x="0.5" y="0.5" width="' . ($size - 1) . '" height="' . ($size - 1)
                . '" fill="none" stroke="#c9c2b4" stroke-width="1"/>';
        }
        $s .= '<g stroke="' . $ink . '" stroke-width="' . $f($w)
            . '" stroke-linecap="round" fill="none">';
        foreach ($this->segments as $seg) {
            $s .= '<line x1="' . $f($seg[0] * $size) . '" y1="' . $f($seg[1] * $size)
               . '" x2="' . $f($seg[2] * $size) . '" y2="' . $f($seg[3] * $size) . '"/>';
        }
        foreach ($this->rings as $r) {
            $s .= '<circle cx="' . $f($r[0] * $size) . '" cy="' . $f($r[1] * $size)
               . '" r="' . $f($r[2] * $size) . '"/>';
        }
        $s .= '</g>';
        if ($this->dots) {
            $s .= '<g fill="' . $ink . '">';
            foreach ($this->dots as $d) {
                $s .= '<circle cx="' . $f($d[0] * $size) . '" cy="' . $f($d[1] * $size)
                   . '" r="' . $f($w * 0.62) . '"/>';
            }
            $s .= '</g>';
        }
        return $s . '</svg>';
    }
}
