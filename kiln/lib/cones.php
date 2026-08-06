<?php
declare(strict_types=1);

/**
 * Pyrometric cone diagrams, drawn as inline SVG.
 *
 * A cone is mounted 8 degrees back from vertical and bends as it takes on heat
 * work. Orton's chart reads the result off a protractor: the tip lands on one
 * of seven rays, from Standing (8 degrees, untouched) through to 6 o'clock
 * (90 degrees, the end point).
 *
 *   echo cone_svg('06', 4);
 *
 * All seven bends are drawn into the same SVG with only one shown, so the
 * up/down buttons can switch position without a redraw or a round trip.
 */

/** The seven positions, in the order Orton numbers them. */
function cone_positions(): array
{
    return [
        ['label' => 'Standing',  'angle' => 8.0,  'note' => 'Untouched — this cone did not reach its temperature.'],
        ['label' => "1 o'clock", 'angle' => 21.0, 'note' => 'Just starting to move.'],
        ['label' => "2 o'clock", 'angle' => 35.0, 'note' => ''],
        ['label' => "3 o'clock", 'angle' => 49.0, 'note' => 'Halfway. From here the cone bends more quickly.'],
        ['label' => "4 o'clock", 'angle' => 63.0, 'note' => 'Usually only 1–2 degrees short of the end point.'],
        ['label' => "5 o'clock", 'angle' => 77.0, 'note' => ''],
        ['label' => "6 o'clock", 'angle' => 90.0, 'note' => 'End point — the tip is level with the base.'],
    ];
}

function cone_position_labels(): array
{
    return array_column(cone_positions(), 'label');
}

/** Short forms, for when there is no room for "3 o'clock". */
function cone_position_short(): array
{
    return ['Std', '1', '2', '3', '4', '5', '6'];
}

function cone_position_clamp(int $position): int
{
    return max(0, min(count(cone_positions()) - 1, $position));
}

/**
 * The three cones in a pack for a given firing cone: one cooler (the guide),
 * the target (the firing cone) and one hotter (the guard).
 *
 * Returned coolest first, because WPW packs are made to fall to the left.
 * Watch the numbering: 07 is cooler than 06, but 7 is hotter than 6.
 */
function cone_pack_for(string $cone): array
{
    $ladder = array_keys(cone_table());
    usort($ladder, static function ($a, $b) {
        return cone_rank((string)$a) <=> cone_rank((string)$b);
    });
    $ladder = array_map('strval', $ladder);

    $index = array_search($cone, $ladder, true);
    if ($index === false) {
        // A cone we do not have a temperature for: show it alone rather than guess.
        return [
            ['role' => 'Firing', 'cone' => $cone],
        ];
    }

    $pack = [];
    if ($index > 0) {
        $pack[] = ['role' => 'Guide', 'cone' => $ladder[$index - 1]];
    }
    $pack[] = ['role' => 'Firing', 'cone' => $ladder[$index]];
    if ($index < count($ladder) - 1) {
        $pack[] = ['role' => 'Guard', 'cone' => $ladder[$index + 1]];
    }
    return $pack;
}

/* ------------------------------------------------------------------ */
/* Geometry                                                            */
/* ------------------------------------------------------------------ */

const CONE_STEPS = 28;
// Curvature distribution along the cone. 1.0 is an even bend — a circular arc —
// which is what Orton's chart shows. Higher values curl the tip into a hook.
const CONE_BIAS  = 1.0;

/**
 * Where the tip ends up, as an angle from vertical, if the centreline sweeps
 * from the 8 degree mounting angle to $endTangent. Length cancels out.
 */
function cone_tip_angle(float $endTangent): float
{
    $start = deg2rad(8.0);
    $end   = deg2rad($endTangent);
    $x = 0.0;
    $y = 0.0;
    for ($i = 0; $i < CONE_STEPS; $i++) {
        $theta = $start + ($end - $start) * pow($i / CONE_STEPS, CONE_BIAS);
        $x += sin($theta);
        $y -= cos($theta);
    }
    return rad2deg(atan2($x, -$y));
}

/**
 * Orton reads the position off where the TIP lands, not off the slope at the
 * tip — a cone at 6 o'clock has its tip on the 90 degree ray while its own
 * end is curled well past horizontal. So solve for the sweep that puts the
 * tip on the wanted ray.
 */
function cone_bend_for(float $tipAngle): float
{
    if ($tipAngle <= 8.0) {
        return 8.0;
    }
    $low  = 8.0;
    $high = 260.0;
    for ($i = 0; $i < 40; $i++) {
        $mid = ($low + $high) / 2;
        if (cone_tip_angle($mid) < $tipAngle) {
            $low = $mid;
        } else {
            $high = $mid;
        }
    }
    return ($low + $high) / 2;
}

/**
 * One bent cone as a filled outline.
 *
 * The centreline is swept from the mounting angle towards the finished angle,
 * with the bend weighted towards the tip — a cone slumps from the top, it does
 * not pivot at the foot. Half-width tapers to nothing so the tip is a point.
 */
function cone_body_path(float $px, float $py, float $length, float $angle, float $baseHalf): string
{
    $steps  = CONE_STEPS;
    $start  = deg2rad(8.0);
    $end    = deg2rad(cone_bend_for($angle));
    $bias   = CONE_BIAS;
    $ds     = $length / $steps;

    $x = $px;
    $y = $py;
    $left  = [];
    $right = [];

    for ($i = 0; $i <= $steps; $i++) {
        $t     = $i / $steps;
        $theta = $start + ($end - $start) * pow($t, $bias);
        $half  = $baseHalf * pow(1 - $t, 1.15);

        $nx = cos($theta);          // perpendicular to the centreline
        $ny = sin($theta);

        $left[]  = [$x - $nx * $half, $y - $ny * $half];
        $right[] = [$x + $nx * $half, $y + $ny * $half];

        $x += sin($theta) * $ds;    // screen y grows downwards
        $y -= cos($theta) * $ds;
    }

    $points = array_merge($left, array_reverse($right));
    $path   = '';
    foreach ($points as $i => $point) {
        $path .= ($i === 0 ? 'M' : 'L') . round($point[0], 2) . ' ' . round($point[1], 2);
    }
    return $path . 'Z';
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

/**
 * The whole pack in one picture: three cones set in a single clay plug, each
 * bent to its own position. Cones fall to the left, the way WPW packs are
 * made, and run coolest on the left to hottest on the right.
 *
 * Every bend of every cone is drawn, tagged data-slot and data-pos, with only
 * the current one shown — so the steppers switch position without a redraw.
 *
 * @param array $entries [['cone' => '06', 'pos' => 3], …]
 */
function cone_pack_svg(array $entries, array $options = []): string
{
    $entries = array_values($entries);
    $count   = max(1, count($entries));

    $cellW  = 92.0;
    $padX   = 26.0;
    $width  = $count * $cellW + $padX * 2;
    $height = 134.0;   // room under the plug for each cone's temperature

    $baseTop = 92.0;
    $baseBot = 114.0;
    $length  = 76.0;
    $half    = 7.0;

    $class = trim('cone-pack-figure ' . (string)($options['class'] ?? ''));
    $names = [];
    foreach ($entries as $entry) {
        $names[] = 'cone ' . $entry['cone'] . ' ' . cone_position_labels()[cone_position_clamp((int)$entry['pos'])];
    }

    $svg = '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 ' . round($width, 2) . ' ' . $height . '"'
        . ' xmlns="http://www.w3.org/2000/svg" role="img"'
        . ' aria-label="' . htmlspecialchars('Witness cone pack: ' . implode(', ', $names), ENT_QUOTES, 'UTF-8') . '">';

    // The clay plug they are all set into.
    $svg .= '<path class="cone-base" d="M6 ' . $baseBot
        . 'L14 ' . $baseTop
        . 'L' . round($width - 14, 2) . ' ' . $baseTop
        . 'L' . round($width - 6, 2) . ' ' . $baseBot . 'Z"/>';

    // Pin holes, poked to let the plug dry evenly.
    for ($i = 0; $i < $count * 3; $i++) {
        $hx = 24 + $i * (($width - 48) / max(1, $count * 3 - 1));
        $svg .= '<circle class="cone-base-hole" cx="' . round($hx, 2) . '" cy="' . round($baseBot - 6, 2) . '" r="1.6"/>';
    }

    foreach ($entries as $slot => $entry) {
        $pivotX   = $padX + $cellW * $slot + $cellW / 2;
        $position = cone_position_clamp((int)$entry['pos']);

        // Mirror about this cone's own foot so it falls to the left.
        $svg .= '<g transform="translate(' . round($pivotX * 2, 2) . ',0) scale(-1,1)">';
        foreach (cone_positions() as $i => $spot) {
            $svg .= '<path class="cone-body' . ($i === $position ? ' on' : '') . '"'
                . ' data-slot="' . $slot . '" data-pos="' . $i . '"'
                . ' d="' . cone_body_path($pivotX, $baseTop + 1, $length, (float)$spot['angle'], $half) . '"/>';
        }
        $svg .= '</g>';

        $svg .= '<text class="cone-number" x="' . round($pivotX, 2) . '" y="' . round($baseBot - 6, 2) . '"'
            . ' text-anchor="middle">' . htmlspecialchars((string)$entry['cone'], ENT_QUOTES, 'UTF-8') . '</text>';

        // The temperature, so "coolest on the left" is something you can read
        // off rather than take on trust — the numbering inverts either side of
        // cone 1, which makes the order look wrong when it is not.
        $temperature = function_exists('cone_temp') ? cone_temp((string)$entry['cone']) : null;
        if ($temperature !== null) {
            $svg .= '<text class="cone-temp" x="' . round($pivotX, 2) . '" y="' . round($baseBot + 14, 2) . '"'
                . ' text-anchor="middle">' . (int)$temperature . '&#176;</text>';
        }
    }

    return $svg . '</svg>';
}

/**
 * One cone with the full Orton protractor behind it — the reference drawing,
 * when there is room for it.
 *
 * @param string $cone     Cone number for the label, e.g. "06".
 * @param int    $position 0–6, indexing cone_positions().
 * @param array  $options  id (unique suffix), rays (bool, default true),
 *                         class (extra classes on the svg)
 */
function cone_svg(string $cone, int $position = 0, array $options = []): string
{
    static $counter = 0;

    $position = cone_position_clamp($position);
    $uid      = (string)($options['id'] ?? 'cone' . (++$counter));
    $showRays = $options['rays'] ?? true;
    $class    = trim('cone-diagram ' . (string)($options['class'] ?? ''));

    $width  = 200.0;
    $height = 150.0;
    $px     = 26.0;          // pivot: where the cone meets its base
    $py     = 128.0;
    $radius = 132.0;
    $length = 112.0;
    $half   = 9.0;

    $svg = '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 ' . $width . ' ' . $height . '"'
        . ' xmlns="http://www.w3.org/2000/svg" role="img"'
        . ' aria-label="' . htmlspecialchars('Cone ' . $cone . ', ' . cone_position_labels()[$position], ENT_QUOTES, 'UTF-8') . '">';

    if ($showRays) {
        // The protractor: an arc from upright to horizontal, with a ray for
        // each of the seven positions.
        $arcStartX = $px;
        $arcStartY = $py - $radius;
        $arcEndX   = $px + $radius;
        $arcEndY   = $py;
        $svg .= '<path class="cone-arc" d="M' . round($arcStartX, 2) . ' ' . round($arcStartY, 2)
            . 'A' . $radius . ' ' . $radius . ' 0 0 1 ' . round($arcEndX, 2) . ' ' . round($arcEndY, 2) . '"/>';

        foreach (cone_positions() as $i => $spot) {
            $a  = deg2rad($spot['angle']);
            $x2 = $px + sin($a) * $radius;
            $y2 = $py - cos($a) * $radius;
            $svg .= '<line class="cone-ray" data-ray="' . $i . '"'
                . ' x1="' . round($px, 2) . '" y1="' . round($py, 2) . '"'
                . ' x2="' . round($x2, 2) . '" y2="' . round($y2, 2) . '"/>';

            $lx = $px + sin($a) * ($radius * 0.74);
            $ly = $py - cos($a) * ($radius * 0.74);
            $svg .= '<text class="cone-ray-label" data-ray="' . $i . '"'
                . ' x="' . round($lx, 2) . '" y="' . round($ly, 2) . '"'
                . ' text-anchor="middle" dominant-baseline="middle"'
                . ' transform="rotate(' . round($spot['angle'] - 90, 2) . ' ' . round($lx, 2) . ' ' . round($ly, 2) . ')">'
                . (int)$spot['angle'] . '&#176;</text>';
        }
    }

    // The base the cone is mounted in.
    $svg .= '<path class="cone-base" d="M' . round($px - 17, 2) . ' ' . round($py + 12, 2)
        . 'L' . round($px - 13, 2) . ' ' . round($py, 2)
        . 'L' . round($px + 15, 2) . ' ' . round($py, 2)
        . 'L' . round($px + 19, 2) . ' ' . round($py + 12, 2) . 'Z"/>';

    // Every bend, with only the current one shown.
    foreach (cone_positions() as $i => $spot) {
        $svg .= '<path class="cone-body' . ($i === $position ? ' on' : '') . '"'
            . ' data-pos="' . $i . '"'
            . ' d="' . cone_body_path($px, $py, $length, (float)$spot['angle'], $half) . '"/>';
    }

    $svg .= '<text class="cone-number" x="' . round($width - 8, 2) . '" y="' . round($height - 8, 2)
        . '" text-anchor="end">' . htmlspecialchars($cone, ENT_QUOTES, 'UTF-8') . '</text>';

    return $svg . '</svg>';
}
