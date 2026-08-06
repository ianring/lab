<?php
declare(strict_types=1);

/**
 * The temperature chart, drawn as inline SVG.
 *
 * One series — temperature against time — so there is no legend and no
 * categorical palette to validate: the title names the series and the phosphor
 * green carries it against the dark surface. The only other mark is a dashed
 * reference line at the target cone's temperature, which is a reference, not a
 * second series, and never gets its own axis.
 *
 * Readings are plotted against real time, not against their index, so an hour
 * you missed shows as a gap rather than being silently evened out.
 */

/**
 * @param array $temps   [['at' => iso, 'c' => float], …]
 * @param array $options cone (string) draws the target line; class; label
 */
function temp_chart_svg(array $temps, array $options = []): string
{
    $points = [];
    foreach ($temps as $temp) {
        $at = parse_dt($temp['at'] ?? '');
        if (!$at) {
            continue;
        }
        $points[] = ['t' => $at->getTimestamp(), 'c' => (float)($temp['c'] ?? 0), 'at' => $at];
    }
    if (count($points) < 1) {
        return '';
    }
    usort($points, static function ($a, $b) {
        return $a['t'] <=> $b['t'];
    });

    $width   = 640.0;
    $height  = 240.0;
    $padL    = 46.0;
    $padR    = 18.0;
    $padT    = 16.0;
    $padB    = 28.0;
    $plotW   = $width - $padL - $padR;
    $plotH   = $height - $padT - $padB;

    $minT = $points[0]['t'];
    $maxT = $points[count($points) - 1]['t'];
    $spanT = max(1, $maxT - $minT);

    $target = null;
    $coneLabel = (string)($options['cone'] ?? '');
    if ($coneLabel !== '' && function_exists('cone_temp')) {
        $target = cone_temp($coneLabel);
    }

    $peak = 0.0;
    foreach ($points as $point) {
        $peak = max($peak, $point['c']);
    }
    // Pick the gridline step that gets 4–6 lines and the least dead space above
    // the data. A fixed step wastes a fifth of the plot on a 1223 degree peak.
    $ceiling = max(120.0, $peak * 1.04, $target ? (float)$target * 1.02 : 0.0);
    $step    = 100.0;
    $maxY    = ceil($ceiling / $step) * $step;
    foreach ([25.0, 50.0, 100.0, 200.0, 250.0, 300.0, 500.0] as $candidate) {
        $lines = ceil($ceiling / $candidate);
        if ($lines >= 4 && $lines <= 6) {
            $step = $candidate;
            $maxY = $lines * $candidate;
            break;
        }
    }

    $x = static function (int $t) use ($minT, $spanT, $padL, $plotW) {
        return $padL + ($t - $minT) / $spanT * $plotW;
    };
    $y = static function (float $c) use ($maxY, $padT, $plotH) {
        return $padT + $plotH - min($c, $maxY) / $maxY * $plotH;
    };

    // A single reading has no span to plot along; park it in the middle.
    $single = count($points) === 1;

    $svg = '<svg class="' . e(trim('temp-chart ' . (string)($options['class'] ?? ''))) . '"'
        . ' viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg"'
        . ' role="img" aria-label="' . e(count($points) . ' temperature readings, up to ' . round($peak) . " degrees") . '">';

    // Recessive gridlines, with the value on the left.
    for ($value = 0.0; $value <= $maxY; $value += $step) {
        $gy = round($y($value), 2);
        $svg .= '<line class="chart-grid" x1="' . $padL . '" y1="' . $gy . '" x2="' . round($width - $padR, 2) . '" y2="' . $gy . '"/>';
        $svg .= '<text class="chart-axis" x="' . round($padL - 8, 2) . '" y="' . round($gy + 4, 2) . '" text-anchor="end">'
            . (int)$value . '</text>';
    }

    // The cone we are aiming at.
    if ($target !== null && $target <= $maxY) {
        $ty = round($y((float)$target), 2);
        $svg .= '<line class="chart-target" x1="' . $padL . '" y1="' . $ty . '" x2="' . round($width - $padR, 2) . '" y2="' . $ty . '"/>';
        // Left end of the line: a rising curve leaves that corner empty, and it
        // keeps this clear of the direct label on the latest reading.
        $svg .= '<text class="chart-target-label" x="' . round($padL + 4, 2) . '" y="' . round($ty - 6, 2) . '">'
            . e('cone ' . $coneLabel . ' · ' . $target . '°C') . '</text>';
    }

    // The series.
    $coords = [];
    foreach ($points as $i => $point) {
        $px = $single ? $padL + $plotW / 2 : $x($point['t']);
        $coords[] = [round($px, 2), round($y($point['c']), 2), $point];
    }

    if (!$single) {
        $area = 'M' . $coords[0][0] . ' ' . round($padT + $plotH, 2);
        foreach ($coords as $coord) {
            $area .= 'L' . $coord[0] . ' ' . $coord[1];
        }
        $area .= 'L' . $coords[count($coords) - 1][0] . ' ' . round($padT + $plotH, 2) . 'Z';
        $svg .= '<path class="chart-area" d="' . $area . '"/>';

        $line = '';
        foreach ($coords as $i => $coord) {
            $line .= ($i === 0 ? 'M' : 'L') . $coord[0] . ' ' . $coord[1];
        }
        $svg .= '<path class="chart-line" d="' . $line . '"/>';
    }

    foreach ($coords as $coord) {
        $point = $coord[2];
        $svg .= '<circle class="chart-point" cx="' . $coord[0] . '" cy="' . $coord[1] . '" r="4">'
            . '<title>' . e(ltrim($point['at']->format('D g:i a'), '0') . ' — ' . round($point['c']) . '°C') . '</title>'
            . '</circle>';
    }

    // Direct label on the latest reading only — never a number on every point.
    $last = $coords[count($coords) - 1];
    $svg .= '<text class="chart-last" x="' . round($last[0] - 8, 2) . '" y="' . round($last[1] - 10, 2) . '" text-anchor="end">'
        . e(round($last[2]['c']) . '°C') . '</text>';

    // Time axis: the ends, which is all a phone has room for.
    $svg .= '<text class="chart-axis" x="' . $padL . '" y="' . round($height - 8, 2) . '">'
        . e(ltrim($points[0]['at']->format('g:i a'), '0')) . '</text>';
    if (!$single) {
        $svg .= '<text class="chart-axis" x="' . round($width - $padR, 2) . '" y="' . round($height - 8, 2) . '" text-anchor="end">'
            . e(ltrim($points[count($points) - 1]['at']->format('g:i a'), '0')) . '</text>';
    }

    return $svg . '</svg>';
}
