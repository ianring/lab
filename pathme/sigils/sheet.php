<?php
declare(strict_types=1);

/**
 * Verification and preview, from the real Glyph class.
 *
 *   php sheet.php                  stats over 3000 glyphs
 *   php sheet.php --svg=sheet.svg  also write a contact sheet
 *   php sheet.php --n=500 --cols=12 --size=110
 *
 * This exists so the numbers and the picture come from the code that actually
 * runs, rather than from a transcription of it. Run it after any change to
 * lib/Glyph.php - it catches arity errors and rule violations that a syntax
 * check cannot.
 */

require __DIR__ . '/lib/Glyph.php';

function distPointSeg(array $pt, array $s): float
{
    $vx = $s[2] - $s[0];
    $vy = $s[3] - $s[1];
    $L  = $vx * $vx + $vy * $vy;
    if ($L < 1e-12) {
        return hypot($pt[0] - $s[0], $pt[1] - $s[1]);
    }
    $t = max(0.0, min(1.0, (($pt[0] - $s[0]) * $vx + ($pt[1] - $s[1]) * $vy) / $L));
    return hypot($pt[0] - ($s[0] + $t * $vx), $pt[1] - ($s[1] + $t * $vy));
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$opt = ['n' => 3000, 'svg' => '', 'cols' => 10, 'size' => 96, 'seed' => 0];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z]+)=(.*)$/', $a, $m) || !array_key_exists($m[1], $opt)) {
        exit("unknown argument $a\n");
    }
    $opt[$m[1]] = $m[1] === 'svg' ? $m[2] : (int) $m[2];
}

mt_srand($opt['seed'] !== 0 ? $opt['seed'] : 20260912);

/** The lattice angles, in degrees. Anything else is a rule violation. */
const LATTICE_ANGLES = [0.0, 45.0, 90.0, 135.0];

$ringCounts = [];
$ringsTotal = $ringsCorner = 0;
$segTotal   = $segOffLattice = 0;
$voids      = [];
$crossings  = 0;
$outOfBounds = 0;
$family     = [];
$lengths    = [];
$barsTotal  = 0;
$barsDiag   = 0;
$bundleDist = [];
$withDots   = 0;
$dotDist    = [];
$parPairs   = 0;
$parClose   = 0;

$sheet = [];
$n = max(1, (int) $opt['n']);

for ($i = 0; $i < $n; $i++) {
    $g = Glyph::generate();

    if ($i < ($opt['svg'] !== '' ? $opt['cols'] * 200 : 0)) {
        $sheet[] = $g;
    }

    $c = count($g->rings);
    $ringCounts[$c] = ($ringCounts[$c] ?? 0) + 1;

    $margin = Glyph::DEFAULTS['margin'];
    $corners = [[$margin, $margin], [1 - $margin, $margin],
                [$margin, 1 - $margin], [1 - $margin, 1 - $margin]];
    $tol = Glyph::DEFAULTS['ring'] * 1.4 + Glyph::DEFAULTS['jitter'] * 2;

    foreach ($g->rings as $r) {
        $ringsTotal++;
        foreach ($corners as $cn) {
            if (hypot($r[0] - $cn[0], $r[1] - $cn[1]) < $tol) {
                $ringsCorner++;
                break;
            }
        }
    }

    foreach ($g->segments as $s) {
        $segTotal++;
        $a = fmod(rad2deg(atan2($s[3] - $s[1], $s[2] - $s[0])) + 360.0, 180.0);
        $ok = false;
        foreach (LATTICE_ANGLES as $L) {
            if (abs($a - $L) < 7 || abs($a - $L - 180) < 7) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $segOffLattice++;
        }

        // which family, and how long - the two things that were out of balance
        $fam = (abs($a) < 7 || abs($a - 90) < 7 || abs($a - 180) < 7) ? 'orthogonal'
             : ((abs($a - 45) < 7 || abs($a - 135) < 7) ? '45 degree' : 'OFF-GRID');
        $family[$fam] = ($family[$fam] ?? 0) + 1;

        $len  = hypot($s[2] - $s[0], $s[3] - $s[1]);
        $unit = (1.0 - 2 * Glyph::DEFAULTS['margin']) / (Glyph::DEFAULTS['lattice'] - 1);
        $band = $len < $unit * 1.45 ? 'short' : ($len < $unit * 2.45 ? 'medium' : 'long');
        $lengths[$band] = ($lengths[$band] ?? 0) + 1;
        foreach ([[$s[0], $s[1]], [$s[2], $s[3]]] as $p) {
            if ($p[0] < -0.01 || $p[0] > 1.01 || $p[1] < -0.01 || $p[1] > 1.01) {
                $outOfBounds++;
            }
        }
    }

    $bundleDist[$g->bundleCount] = ($bundleDist[$g->bundleCount] ?? 0) + 1;
    $dotDist[$g->dotGroups] = ($dotDist[$g->dotGroups] ?? 0) + 1;
    if ($g->dots) {
        $withDots++;
    }

    // the tripled lines must never be diagonal
    foreach ($g->bars as $bi) {
        if (!isset($g->segments[$bi])) {
            continue;
        }
        $b = $g->segments[$bi];
        $barsTotal++;
        $ang = fmod(rad2deg(atan2($b[3] - $b[1], $b[2] - $b[0])) + 360.0, 180.0);
        if (!(abs($ang) < 7 || abs($ang - 90) < 7 || abs($ang - 180) < 7)) {
            $barsDiag++;
        }
    }

    // parallel line-work should be uncommon, and well spaced when it happens
    $bars = array_flip($g->bars);
    $segN = count($g->segments);
    for ($x = 0; $x < $segN; $x++) {
        if (isset($bars[$x])) {
            continue;
        }
        for ($y = $x + 1; $y < $segN; $y++) {
            if (isset($bars[$y])) {
                continue;
            }
            $p1 = $g->segments[$x];
            $p2 = $g->segments[$y];
            $l1 = hypot($p1[2] - $p1[0], $p1[3] - $p1[1]);
            $l2 = hypot($p2[2] - $p2[0], $p2[3] - $p2[1]);
            if ($l1 < 1e-9 || $l2 < 1e-9) {
                continue;
            }
            $cross = abs((($p1[2] - $p1[0]) / $l1) * (($p2[3] - $p2[1]) / $l2)
                       - (($p1[3] - $p1[1]) / $l1) * (($p2[2] - $p2[0]) / $l2));
            if ($cross > 0.12) {
                continue;
            }
            $parPairs++;
            $d = min(
                distPointSeg([$p1[0], $p1[1]], $p2), distPointSeg([$p1[2], $p1[3]], $p2),
                distPointSeg([$p2[0], $p2[1]], $p1), distPointSeg([$p2[2], $p2[3]], $p1)
            );
            if ($d < Glyph::DEFAULTS['parallelGap']) {
                $parClose++;
            }
        }
    }

    // emptiest spot left on the cell
    $worst = 0.0;
    for ($gx = 0; $gx <= 8; $gx++) {
        for ($gy = 0; $gy <= 8; $gy++) {
            $p = [0.14 + $gx * 0.09, 0.14 + $gy * 0.09];
            $best = 1.0;
            foreach ($g->segments as $s) {
                $vx = $s[2] - $s[0];
                $vy = $s[3] - $s[1];
                $L2 = $vx * $vx + $vy * $vy;
                $t = $L2 < 1e-12 ? 0.0 : max(0.0, min(1.0, (($p[0] - $s[0]) * $vx + ($p[1] - $s[1]) * $vy) / $L2));
                $best = min($best, hypot($p[0] - ($s[0] + $t * $vx), $p[1] - ($s[1] + $t * $vy)));
            }
            foreach ($g->rings as $r) {
                $best = min($best, abs(hypot($p[0] - $r[0], $p[1] - $r[1]) - $r[2]));
            }
            foreach ($g->dots as $d) {
                $best = min($best, hypot($p[0] - $d[0], $p[1] - $d[1]));
            }
            $worst = max($worst, $best);
        }
    }
    $voids[] = $worst;
}

ksort($ringCounts);
sort($voids);

printf("%d glyphs from lib/Glyph.php\n\n", $n);

echo "  ring count        ";
foreach ($ringCounts as $k => $v) {
    printf("%d:%d  ", $k, $v);
}
$ringsOk = !array_diff(array_keys($ringCounts), [1, 2]);
echo $ringsOk ? "   OK\n" : "   VIOLATION - must be 1 or 2\n";

printf("  rings in a corner %5.1f%%   of %d\n", 100 * $ringsCorner / max($ringsTotal, 1), $ringsTotal);
printf("  strokes off-grid  %5d     of %d%s\n", $segOffLattice, $segTotal,
    $segOffLattice / max($segTotal, 1) > 0.01 ? '   VIOLATION' : '');
printf("  out of bounds     %5d%s\n", $outOfBounds, $outOfBounds ? '   VIOLATION' : '');
echo "\n  angle mix\n";
foreach (['orthogonal', '45 degree', 'OFF-GRID'] as $k) {
    printf("    %-12s %5.1f%%\n", $k, 100 * ($family[$k] ?? 0) / max($segTotal, 1));
}
printf("  bundle bars       %5d, %d diagonal%s\n", $barsTotal, $barsDiag,
    $barsDiag ? '   VIOLATION - bundles are upright or flat only' : '   OK');

printf("  parallel pairs    %5d, %d closer than %.2f%s\n", $parPairs, $parClose,
    Glyph::DEFAULTS['parallelGap'],
    $parClose ? '   VIOLATION' : '   OK');
printf("  glyphs with dots  %5.1f%%   1 in %.1f   (target 1 in 12)\n",
    100 * $withDots / $n, $withDots ? $n / $withDots : 0);
ksort($dotDist);
echo "  dot groups        ";
foreach ($dotDist as $k => $v) {
    printf("%d:%d  ", $k, $v);
}
echo array_diff(array_keys($dotDist), [0, 1]) ? "   VIOLATION - zero or one\n" : "   OK\n";

$withBundle = ($bundleDist[1] ?? 0);
printf("  glyphs w/ bundle  %5.1f%%   %.1f in 16   (target 6 in 16 = 37.5%%)\n",
    100 * $withBundle / $n, 16 * $withBundle / $n);
ksort($bundleDist);
echo "  bundles per glyph ";
foreach ($bundleDist as $k => $v) {
    printf("%d:%d  ", $k, $v);
}
echo array_diff(array_keys($bundleDist), [0, 1]) ? "   VIOLATION - zero or one\n" : "   OK\n";
echo "  stroke lengths\n";
foreach (['short', 'medium', 'long'] as $k) {
    printf("    %-12s %5.1f%%\n", $k, 100 * ($lengths[$k] ?? 0) / max($segTotal, 1));
}
echo "\n";

printf("  largest void      %5.3f median, %.3f worst  (target below %.3f)\n",
    $voids[intdiv(count($voids), 2)], end($voids), Glyph::DEFAULTS['fillGap']);

if ($opt['svg'] !== '') {
    $size = (int) $opt['size'];
    $cols = (int) $opt['cols'];
    $pad  = 26;
    $rows = (int) ceil(count($sheet) / $cols);
    $w    = $cols * ($size + $pad);
    $h    = $rows * ($size + $pad);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h
         . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
         . '<rect width="' . $w . '" height="' . $h . '" fill="#faf6ee"/>';
    foreach ($sheet as $k => $g) {
        $x = ($k % $cols) * ($size + $pad) + $pad / 2;
        $y = intdiv($k, $cols) * ($size + $pad) + $pad / 2;
        $inner = $g->svg((float) $size);
        $inner = preg_replace('/^<svg[^>]*>|<\/svg>$/', '', $inner);
        $svg .= '<g transform="translate(' . $x . ',' . $y . ')">' . $inner . '</g>';
    }
    $svg .= '</svg>';
    file_put_contents($opt['svg'], $svg);
    printf("\n  wrote %s  (%d glyphs)\n", $opt['svg'], count($sheet));
}
