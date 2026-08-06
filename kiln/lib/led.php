<?php
declare(strict_types=1);

/**
 * The Euclid / Bartlett readout, drawn as inline SVG.
 *
 * It is a 14-segment starburst display, not a 7-segment one — you can tell
 * from the panel photographs in resources/: N has two diagonals through the
 * middle, I is a serifed capital drawn with the centre verticals, and "-"
 * shows as two short dashes because the middle bar is split into g1 and g2.
 *
 *      -- a --
 *     |\  |  /|
 *     f h j k b
 *     |  \|/  |
 *     -g1- -g2-
 *     |  /|\  |
 *     e n m l c
 *     |/  |  \|
 *      -- d --
 *
 *   echo led_svg('IdLE');
 *   echo led_svg('3.20', ['digits' => 4, 'glow' => 1.3]);
 *
 * Segments are individually addressable, so JavaScript can re-light the same
 * SVG without redrawing it: each carries class "led-seg", data-digit="N" and
 * data-seg="a".."n" (plus "dp"). led_masks() gives the bitmask per digit.
 */

/** Segment order, and therefore the bit values. Keep in sync with app.js. */
function led_segments(): array
{
    return ['a' => 1, 'b' => 2, 'c' => 4, 'd' => 8, 'e' => 16, 'f' => 32,
            'g1' => 64, 'g2' => 128, 'h' => 256, 'j' => 512, 'k' => 1024,
            'l' => 2048, 'm' => 4096, 'n' => 8192, 'dp' => 16384];
}

/**
 * Which segments each character lights, written out by name so the table can
 * be read and corrected against a photograph.
 *
 * Case matters where the display draws them differently: the panel shows HOLd
 * and IdLE with a lowercase d, which is its own shape (right verticals, the
 * centre-right bar and a diagonal down to the bottom left).
 */
function led_font(): array
{
    return [
        '0' => 'a b c d e f',
        '1' => 'b c',
        '2' => 'a b g1 g2 e d',
        '3' => 'a b g1 g2 c d',
        '4' => 'f g1 g2 b c',
        '5' => 'a f g1 g2 c d',
        '6' => 'a f e d c g1 g2',
        '7' => 'a b c',
        '8' => 'a b c d e f g1 g2',
        '9' => 'a b c d f g1 g2',

        'A' => 'a b c e f g1 g2',
        'B' => 'a b c d g2 j m',
        'C' => 'a f e d',
        'D' => 'a b c d j m',
        'E' => 'a f e d g1 g2',
        'F' => 'a f e g1 g2',
        'G' => 'a f e d c g2',
        'H' => 'b c e f g1 g2',
        'I' => 'a d j m',
        'J' => 'b c d e',
        'K' => 'e f g1 k l',
        'L' => 'f e d',
        'M' => 'b c e f h k',
        'N' => 'b c e f h l',
        'O' => 'a b c d e f',
        'P' => 'a b e f g1 g2',
        'Q' => 'a b c d e f l',
        'R' => 'a b e f g1 g2 l',
        'S' => 'a f g1 g2 c d',
        'T' => 'a j m',
        'U' => 'b c d e f',
        'V' => 'e f k n',
        'W' => 'b c e f l n',
        'X' => 'h k l n',
        'Y' => 'h k m',
        'Z' => 'a k n d',

        // Lowercase shapes the display actually uses.
        'b' => 'c d e f g1 g2',
        'c' => 'd e g1 g2',
        'd' => 'b c d g2 n',
        'h' => 'c e f g1 g2',
        'n' => 'c e g1 g2',
        'o' => 'c d e g1 g2',
        'r' => 'e g1 g2',
        't' => 'f e d g1',
        'u' => 'c d e',
        'y' => 'b c d f g1 g2',

        '-' => 'g1 g2',
        '_' => 'd',
        '=' => 'g1 g2 d',
        '+' => 'g1 g2 j m',
        '*' => 'g1 g2 h j k l m n',
        '/' => 'k n',
        '\\' => 'h l',
        '°' => 'a b f g1 g2',
        '?' => 'a b g2 m',
        ' ' => '',
    ];
}

/**
 * Whole display states, for readouts the font cannot express character by
 * character. WAIT is the case in point: a 14-segment W is a compromise, so if
 * a photograph shows something different, spell it out here and it wins.
 *
 * Keys are the exact display string; values are one segment list per cell.
 */
function led_states(): array
{
    return [
        // W has no faithful 14-segment form; this is the usual approximation.
        'WAIT' => ['b c e f l n', 'a b c e f g1 g2', 'a d j m', 'a j m'],
    ];
}

/** Segment names to a bitmask. */
function led_mask_from(string $names): int
{
    $bits = led_segments();
    $mask = 0;
    foreach (preg_split('/\s+/', trim($names)) ?: [] as $name) {
        if ($name !== '' && isset($bits[$name])) {
            $mask |= $bits[$name];
        }
    }
    return $mask;
}

/** Segments lit for one character. Unknown characters come back blank. */
function led_char_mask(string $char): int
{
    $font = led_font();
    if (isset($font[$char])) {
        return led_mask_from($font[$char]);
    }
    $upper = strtoupper($char);
    if (isset($font[$upper])) {
        return led_mask_from($font[$upper]);
    }
    $lower = strtolower($char);
    return isset($font[$lower]) ? led_mask_from($font[$lower]) : 0;
}

/**
 * Bitmask per digit position for a string, padded to $digits.
 *
 * A "." folds into the preceding digit as its decimal point, the way a real
 * display handles it — so "3.20" fits in three cells.
 */
function led_masks(string $text, int $digits = 4, string $align = 'right'): array
{
    $states = led_states();
    if (isset($states[$text])) {
        $masks = array_map('led_mask_from', $states[$text]);
    } else {
        $masks = [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            if ($char === '.' && $masks) {
                $last = count($masks) - 1;
                if (!($masks[$last] & led_segments()['dp'])) {
                    $masks[$last] |= led_segments()['dp'];
                    continue;
                }
            }
            $masks[] = led_char_mask($char);
        }
    }

    if (count($masks) > $digits) {
        // Too long: keep the end, which is where the meaningful digits are.
        $masks = array_slice($masks, -$digits);
    }

    $pad = $digits - count($masks);
    if ($pad > 0) {
        if ($align === 'left') {
            $masks = array_merge($masks, array_fill(0, $pad, 0));
        } elseif ($align === 'center') {
            $left = intdiv($pad, 2);
            $masks = array_merge(array_fill(0, $left, 0), $masks, array_fill(0, $pad - $left, 0));
        } else {
            $masks = array_merge(array_fill(0, $pad, 0), $masks);
        }
    }

    return $masks;
}

/* ------------------------------------------------------------------ */
/* Geometry                                                            */
/* ------------------------------------------------------------------ */

/** A horizontal segment as a stretched hexagon, mitred at both ends. */
function led_bar_h(float $x0, float $x1, float $y, float $t, float $gap): string
{
    $x0 += $gap;
    $x1 -= $gap;
    $h = $t / 2;
    return led_points([
        [$x0, $y], [$x0 + $h, $y - $h], [$x1 - $h, $y - $h],
        [$x1, $y], [$x1 - $h, $y + $h], [$x0 + $h, $y + $h],
    ]);
}

/** The same shape stood on its end. */
function led_bar_v(float $y0, float $y1, float $x, float $t, float $gap): string
{
    $y0 += $gap;
    $y1 -= $gap;
    $h = $t / 2;
    return led_points([
        [$x, $y0], [$x + $h, $y0 + $h], [$x + $h, $y1 - $h],
        [$x, $y1], [$x - $h, $y1 - $h], [$x - $h, $y0 + $h],
    ]);
}

/** A diagonal: a thick line with its ends cut square to the run. */
function led_bar_d(float $x0, float $y0, float $x1, float $y1, float $t): string
{
    $dx = $x1 - $x0;
    $dy = $y1 - $y0;
    $len = sqrt($dx * $dx + $dy * $dy);
    if ($len <= 0.0) {
        return '';
    }
    $ux = $dx / $len;
    $uy = $dy / $len;
    $px = -$uy * $t / 2;   // perpendicular
    $py = $ux * $t / 2;
    // Pull the tips in a little so they read as points, like the real segment.
    $tipX = $ux * $t * 0.32;
    $tipY = $uy * $t * 0.32;

    return led_points([
        [$x0 + $tipX + $px, $y0 + $tipY + $py],
        [$x0 + $tipX * 0.2, $y0 + $tipY * 0.2],
        [$x0 + $tipX - $px, $y0 + $tipY - $py],
        [$x1 - $tipX - $px, $y1 - $tipY - $py],
        [$x1 - $tipX * 0.2, $y1 - $tipY * 0.2],
        [$x1 - $tipX + $px, $y1 - $tipY + $py],
    ]);
}

function led_points(array $points): string
{
    $out = [];
    foreach ($points as $point) {
        $out[] = round($point[0], 2) . ',' . round($point[1], 2);
    }
    return implode(' ', $out);
}

/** Every segment's outline for one cell, keyed by segment name. */
function led_cell_shapes(float $w, float $h, float $t, float $gap): array
{
    $half   = $h / 2;
    $midX   = $w / 2;
    $dt     = $t * 0.78;              // diagonals run a little thinner
    $inset  = $t * 1.15;              // keep diagonals clear of the outer bars
    $centre = $t * 0.85;              // and clear of the middle join

    return [
        'a'  => led_bar_h(0, $w, $t / 2, $t, $gap),
        'd'  => led_bar_h(0, $w, $h - $t / 2, $t, $gap),
        'f'  => led_bar_v($t / 2, $half, $t / 2, $t, $gap),
        'e'  => led_bar_v($half, $h - $t / 2, $t / 2, $t, $gap),
        'b'  => led_bar_v($t / 2, $half, $w - $t / 2, $t, $gap),
        'c'  => led_bar_v($half, $h - $t / 2, $w - $t / 2, $t, $gap),
        'g1' => led_bar_h(0, $midX, $half, $t, $gap),
        'g2' => led_bar_h($midX, $w, $half, $t, $gap),
        'j'  => led_bar_v($t / 2, $half, $midX, $t, $gap),
        'm'  => led_bar_v($half, $h - $t / 2, $midX, $t, $gap),
        'h'  => led_bar_d($inset, $inset, $midX - $centre, $half - $centre, $dt),
        'k'  => led_bar_d($w - $inset, $inset, $midX + $centre, $half - $centre, $dt),
        'n'  => led_bar_d($inset, $h - $inset, $midX - $centre, $half + $centre, $dt),
        'l'  => led_bar_d($w - $inset, $h - $inset, $midX + $centre, $half + $centre, $dt),
    ];
}

/** Every segment lit at once — a reference glyph for checking the geometry. */
function led_svg_all_on(array $options = []): string
{
    $all = 0;
    foreach (led_segments() as $bit) {
        $all |= $bit;
    }
    return led_svg_from_masks([$all], $options + ['label' => 'all segments lit']);
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

/**
 * @param string $text What to show, e.g. "IdLE", "3.20", "998".
 * @param array $options
 *   digits   int    number of character cells (default: at least 4)
 *   align    string right|left|center within those cells (default right)
 *   glow     float  bloom strength, 0 turns it off (default 1.0)
 *   skew     float  italic lean in degrees (default 4)
 *   bezel    bool   draw the dark glass behind the digits (default true)
 *   off      bool   show unlit segments faintly (default true)
 *   class    string extra class on the <svg>
 *   id       string suffix for the filter ids, if you need it stable
 *   label    string accessible label (default: the text)
 */
function led_svg(string $text, array $options = []): string
{
    // Decimal points ride on the preceding digit, so they cost no width.
    $natural = mb_strlen(str_replace('.', '', $text));
    $digits  = max(1, (int)($options['digits'] ?? max(4, $natural)));
    $align   = (string)($options['align'] ?? 'right');

    $masks = led_masks($text, $digits, $align);

    return led_svg_from_masks($masks, $options + ['label' => $text]);
}

/** The drawing half, for when you already know which segments to light. */
function led_svg_from_masks(array $masks, array $options = []): string
{
    static $counter = 0;

    $masks   = array_values($masks) ?: [0];
    $digits  = count($masks);
    $glow    = (float)($options['glow'] ?? 1.0);
    $skew    = (float)($options['skew'] ?? 4);
    $bezel   = $options['bezel'] ?? true;
    $showOff = $options['off'] ?? true;
    $class   = trim('led ' . (string)($options['class'] ?? ''));
    $uid     = (string)($options['id'] ?? 'led' . (++$counter));
    $label   = (string)($options['label'] ?? '');

    // Cell metrics, in user units. Everything else is derived from these.
    $cellW   = 62.0;
    $cellH   = 106.0;
    $thick   = 10.5;
    $gap     = 2.6;
    $spacing = 21.0;   // between cells, leaves room for a decimal point
    $padX    = 26.0;
    $padY    = 22.0;

    $digitsW = $digits * $cellW + ($digits - 1) * $spacing;
    $width   = $digitsW + $padX * 2;
    $height  = $cellH + $padY * 2;

    // Lean the digits about their own centre so they stay inside the bezel.
    $skewShift = tan(deg2rad($skew)) * ($cellH / 2);

    $shapes = led_cell_shapes($cellW, $cellH, $thick, $gap);
    $bits   = led_segments();

    $svg = '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 ' . round($width, 2) . ' ' . round($height, 2) . '"'
        . ' xmlns="http://www.w3.org/2000/svg" role="img"'
        . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-led="' . $digits . '">';

    $svg .= '<defs>';
    if ($glow > 0) {
        // Two blurs: a tight halo right at the segment, and a wider bloom that
        // spills onto the glass. Both sit under the crisp segment itself.
        $tight = round(1.8 * $glow, 2);
        $wide  = round(6.0 * $glow, 2);
        $svg .= '<filter id="' . $uid . '-glow" x="-35%" y="-35%" width="170%" height="170%" color-interpolation-filters="sRGB">'
            . '<feGaussianBlur in="SourceGraphic" stdDeviation="' . $wide . '" result="wide"/>'
            . '<feGaussianBlur in="SourceGraphic" stdDeviation="' . $tight . '" result="tight"/>'
            . '<feMerge>'
            . '<feMergeNode in="wide"/><feMergeNode in="wide"/>'
            . '<feMergeNode in="tight"/>'
            . '<feMergeNode in="SourceGraphic"/>'
            . '</feMerge></filter>';
    }
    if ($bezel) {
        $svg .= '<linearGradient id="' . $uid . '-glass" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#2a0b06"/>'
            . '<stop offset="0.5" stop-color="#150503"/>'
            . '<stop offset="1" stop-color="#0b0302"/>'
            . '</linearGradient>';
    }
    $svg .= '</defs>';

    if ($bezel) {
        $svg .= '<rect class="led-glass" x="0" y="0" width="' . round($width, 2) . '" height="' . round($height, 2) . '"'
            . ' rx="8" fill="url(#' . $uid . '-glass)"/>';
    }

    $group = '<g class="led-digits"' . ($glow > 0 ? ' filter="url(#' . $uid . '-glow)"' : '') . '>';

    foreach ($masks as $index => $mask) {
        $originX = $padX + $index * ($cellW + $spacing) + $skewShift;
        $transform = 'translate(' . round($originX, 2) . ',' . round($padY, 2) . ')'
            . ($skew != 0.0 ? ' skewX(' . round(-$skew, 2) . ')' : '');

        $group .= '<g class="led-digit" data-digit="' . $index . '" transform="' . $transform . '">';

        foreach ($shapes as $name => $points) {
            if ($points === '') {
                continue;
            }
            $lit = ($mask & $bits[$name]) !== 0;
            if (!$lit && !$showOff) {
                continue;
            }
            $group .= '<polygon class="led-seg' . ($lit ? ' on' : '') . '"'
                . ' data-digit="' . $index . '" data-seg="' . $name . '" points="' . $points . '"/>';
        }

        $dpLit = ($mask & $bits['dp']) !== 0;
        if ($dpLit || $showOff) {
            $group .= '<circle class="led-seg' . ($dpLit ? ' on' : '') . '"'
                . ' data-digit="' . $index . '" data-seg="dp"'
                . ' cx="' . round($cellW + $spacing / 2, 2) . '"'
                . ' cy="' . round($cellH - $thick / 2, 2) . '"'
                . ' r="' . round($thick * 0.5, 2) . '"/>';
        }

        $group .= '</g>';
    }

    $svg .= $group . '</g></svg>';

    return $svg;
}
