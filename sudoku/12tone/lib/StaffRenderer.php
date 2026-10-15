<?php
declare(strict_types=1);

/**
 * Renders a Puzzle as musical notation via LilyPond.
 *
 * Layout: the whole puzzle is ONE LilyPond score rather than n separate renders.
 * Simultaneous staves mean LilyPond aligns every bar line vertically, which a
 * sudoku needs - you have to be able to read down a column.
 *
 * The box geometry falls out of the notation directly:
 *   - w columns wide -> a thick bar line every w measures
 *   - h rows tall    -> staves grouped h to a StaffGroup, whose span bars
 *                       connect the bar lines within a band
 *
 * Pitch comes from the Variant; the octave comes from the clef. Each clef has a
 * base octave chosen so the row sits on the staff rather than drowning in ledger
 * lines - the C clefs and the F clef want different octaves for the same notes.
 */
final class StaffRenderer
{
    /**
     * Supported clefs. `base` is how many octaves above LilyPond's bare `c`
     * (which is C3) the variant's first degree sits, chosen per clef so the
     * notes land on the staff:
     *
     *   treble  base 1  first degree at C4, one ledger below the staff
     *   alto    base 1  first degree on the middle line
     *   tenor   base 0  first degree just under the bottom line - no ledgers
     *   bass    base 0  first degree in the second space - no ledgers
     */
    public const CLEFS = [
        'treble' => ['lily' => 'treble', 'base' => 1, 'label' => 'Treble', 'low' => 59, 'high' => 83],
        'alto'   => ['lily' => 'alto',   'base' => 1, 'label' => 'Alto',   'low' => 48, 'high' => 72],
        'tenor'  => ['lily' => 'tenor',  'base' => 0, 'label' => 'Tenor',  'low' => 45, 'high' => 69],
        'bass'   => ['lily' => 'bass',   'base' => 0, 'label' => 'Bass',   'low' => 38, 'high' => 62],
    ];

    public const DEFAULT_CLEF = 'treble';

    /** Clef mode meaning "pick a different one for every staff". */
    public const RANDOM_CLEF = 'random';

    /**
     * How far notes may roam.
     *
     *   octave - the tidy default: one octave, sitting where the clef reads best
     *   full   - anywhere on the staff within one ledger line above or below,
     *            which works out to exactly two octaves in every clef
     */
    public const RANGE_MODES = [
        'octave' => 'One octave',
        'full'   => 'Full staff range',
    ];

    public const DEFAULT_RANGE = 'octave';

    /**
     * Staff size in points. At 20 the twelve staves stack to roughly 215 mm,
     * which clears the 254 mm of printable height on US Letter portrait with
     * half-inch margins. Going much above this will overflow the page.
     */
    public const DEFAULT_STAFF_SIZE = 20;

    /** US Letter portrait, half-inch margins: 215.9 - 2 x 12.7 = 190.5 mm. */
    public const LINE_WIDTH_MM = 190.5;

    /** Clef definition by name, falling back to treble for anything unknown. */
    public static function clef(string $name): array
    {
        return self::CLEFS[$name] ?? self::CLEFS[self::DEFAULT_CLEF];
    }

    public static function clefName(string $name): string
    {
        if ($name === self::RANDOM_CLEF) {
            return $name;
        }
        return isset(self::CLEFS[$name]) ? $name : self::DEFAULT_CLEF;
    }

    /** Selectable clef modes: the four clefs plus the per-staff shuffle. */
    public static function clefModes(): array
    {
        $out = [];
        foreach (self::CLEFS as $key => $def) {
            $out[$key] = $def['label'];
        }
        $out[self::RANDOM_CLEF] = 'Random per staff';
        return $out;
    }

    /**
     * The clef for one staff. Under RANDOM_CLEF each staff draws its own,
     * derived from the seed so a puzzle always engraves the same way - a
     * re-render that moved the clefs around would not match its cached score.
     */
    public static function clefFor(string $mode, int $row, int $seed): array
    {
        if ($mode !== self::RANDOM_CLEF) {
            return self::clef($mode);
        }
        $keys = array_keys(self::CLEFS);
        return self::CLEFS[$keys[crc32($seed . ':clef:' . $row) % count($keys)]];
    }

    public static function rangeMode(string $mode, Variant $variant): string
    {
        if ($mode === 'full' && $variant->allowsFullRange()) {
            return 'full';
        }
        return self::DEFAULT_RANGE;
    }

    /** MIDI number a degree would sound at, written with this many octave marks. */
    private static function midi(Variant $variant, int $degree, int $marks): int
    {
        return (4 + $marks) * 12 + $variant->pitchClass($degree);
    }

    /**
     * Octave marks for one note.
     *
     * In 'octave' mode this is the clef's home octave, as before. In 'full' mode
     * every octave that keeps the note within one ledger line of the staff is a
     * candidate, and one is picked from the seed - so the same symbol turns up
     * high in one bar and low in another. A high B flat and a low B flat are one
     * pitch class and therefore one symbol, which is the exercise.
     */
    public static function marksFor(
        Variant $variant,
        int $degree,
        array $clef,
        string $mode,
        int $seed,
        int $cell
    ): int {
        $home = $clef['base'] + $variant->pitchOctave($degree);
        if ($mode !== 'full') {
            return $home;
        }

        $candidates = [];
        for ($m = -4; $m <= 5; $m++) {
            $pitch = self::midi($variant, $degree, $m);
            if ($pitch >= $clef['low'] && $pitch <= $clef['high']) {
                $candidates[] = $m;
            }
        }
        if (!$candidates) {
            return $home;   // should not happen, but never engrave nothing
        }
        return $candidates[crc32($seed . ':oct:' . $cell) % count($candidates)];
    }

    /** LilyPond octave marks: apostrophes upward, commas downward. */
    private static function octaveMark(int $n): string
    {
        return $n >= 0 ? str_repeat("'", $n) : str_repeat(',', -$n);
    }

    /** Scientific-pitch range this variant occupies in this clef, e.g. "C4-C5". */
    public static function range(Variant $variant, string $clef, string $mode = self::DEFAULT_RANGE): string
    {
        if ($clef === self::RANDOM_CLEF) {
            return 'mixed clefs';
        }
        $def = self::clef($clef);

        if (self::rangeMode($mode, $variant) === 'full') {
            return self::spell($def['low']) . '-' . self::spell($def['high']);
        }

        $last = $variant->n - 1;
        return $variant->pitchName(0) . (3 + $def['base'])
            . '-'
            . $variant->pitchName($last) . (3 + $def['base'] + $variant->pitchOctave($last));
    }

    /** MIDI number as a scientific pitch name, e.g. 59 -> "B3". */
    private static function spell(int $midi): string
    {
        $names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        return $names[$midi % 12] . (intdiv($midi, 12) - 1);
    }

    private static ?string $version = null;
    private static bool $versionChecked = false;
    private static ?string $cacheDir = null;

    /** How long a probed LilyPond version stays trusted on disk. */
    private const VERSION_TTL = 86400;

    /**
     * Point the renderer at a cache directory so the `lilypond --version` probe
     * can be remembered between requests. Spawning a process per page load is
     * pure overhead once the answer is known.
     */
    public static function useCacheDir(string $dir): void
    {
        self::$cacheDir = rtrim($dir, '/');
    }

    // ------------------------------------------------------------- lilypond

    public static function binary(): string
    {
        return getenv('LILYPOND_BIN') ?: 'lilypond';
    }

    /** Installed LilyPond version, or null if the binary could not be run. */
    public static function version(): ?string
    {
        if (self::$versionChecked) {
            return self::$version;
        }
        self::$versionChecked = true;

        $memo = self::$cacheDir !== null ? self::$cacheDir . '/.lilypond-version' : null;
        if ($memo !== null && is_file($memo) && (time() - (int) filemtime($memo)) < self::VERSION_TTL) {
            $cached = trim((string) @file_get_contents($memo));
            self::$version = $cached === '' ? null : $cached;
            return self::$version;
        }

        $out = [];
        $status = 0;
        @exec(escapeshellarg(self::binary()) . ' --version 2>&1', $out, $status);
        if ($status === 0 && $out && preg_match('/(\d+\.\d+(?:\.\d+)?)/', implode(' ', $out), $m)) {
            self::$version = $m[1];
        }

        if ($memo !== null && is_dir(dirname($memo))) {
            @file_put_contents($memo, (string) self::$version);
        }
        return self::$version;
    }

    /** -dcrop trims the page down to the music; it landed in LilyPond 2.20. */
    public static function supportsCrop(): bool
    {
        $v = self::version();
        return $v !== null && version_compare($v, '2.20', '>=');
    }

    /**
     * Build the LilyPond source for one puzzle.
     *
     * @param bool $solved render the completed grid instead of the puzzle
     */
    public static function lilypond(
        Puzzle $p,
        bool $solved = false,
        int $staffSize = self::DEFAULT_STAFF_SIZE,
        ?float $lineWidthMm = null,
        string $clef = self::DEFAULT_CLEF,
        string $spelling = 'sharps',
        string $rangeMode = self::DEFAULT_RANGE
    ): string {
        $variant   = $p->variant();
        $grid      = $solved ? $p->solution : $p->puzzle;
        $seed      = (int) ($p->meta['seed'] ?? 0);
        $rangeMode = self::rangeMode($rangeMode, $variant);
        $staves    = [];

        for ($band = 0; $band < $variant->bands(); $band++) {
            $group = [];
            for ($sub = 0; $sub < $variant->boxHeight; $sub++) {
                $r = $band * $variant->boxHeight + $sub;
                $group[] = '      ' . self::staffFor(
                    $grid,
                    $r,
                    self::clefFor($clef, $r, $seed),
                    $variant,
                    $spelling,
                    $seed,
                    $rangeMode
                );
            }
            $staves[] = "    \\new StaffGroup <<\n" . implode("\n", $group) . "\n    >>";
        }

        $template = <<<'LY'
\version "2.20.0"

%% @VARIANT@ sudoku engraved as a tone row. @CLEF@ clef, @RANGE@.
%% @LEGEND@

#(set-global-staff-size @STAFFSIZE@)

\header { tagline = ##f }

\paper {
@PAPER@
  indent = 0\mm
  line-width = @LINEWIDTH@\mm
  ragged-right = ##f
  ragged-bottom = ##t
  ragged-last-bottom = ##t
  print-page-number = ##f
  oddHeaderMarkup = ##f
  evenHeaderMarkup = ##f
  oddFooterMarkup = ##f
  evenFooterMarkup = ##f
}

\score {
  <<
@STAVES@
  >>
  \layout {
    \context {
      \Score
      %% strictly proportional spacing, so an empty measure is exactly as wide
      %% as one holding a note and the columns line up as a grid.
      %% strict-note-spacing is deliberately NOT set: it would let the treble
      %% clef crowd the first note. The first measure ends up marginally wider
      %% than the rest on every staff equally, so columns still align.
      proportionalNotationDuration = #(ly:make-moment 1/1)
      \override SpacingSpanner.uniform-stretching = ##t
      %% one system, always
      \override NonMusicalPaperColumn.line-break-permission = ##f
      \override StaffGrouper.staff-staff-spacing.basic-distance = #9
      \override StaffGrouper.staffgroup-staff-spacing.basic-distance = #15
      \remove "Bar_number_engraver"
    }
    \context {
      \Staff
      \override TimeSignature.stencil = ##f
    }
    \context {
      \StaffGroup
      systemStartDelimiter = #'SystemStartBar
    }
  }
}
LY;

        $lineWidth = $lineWidthMm ?? self::LINE_WIDTH_MM;

        if ($lineWidthMm === null) {
            // Standalone sheet: US Letter portrait with half-inch margins.
            // set-paper-size must come first - it resets the margins, so
            // anything set before it would be discarded.
            $paper = '  #(set-paper-size "letter")' . "\n"
                . "  top-margin = 12.7\\mm\n"
                . "  bottom-margin = 12.7\\mm\n"
                . "  left-margin = 12.7\\mm\n"
                . "  right-margin = 12.7\\mm";
        } else {
            // Composed into a page by something else: size the sheet to the
            // music so -dcrop has a clean box, and let the caller place it.
            $paper = sprintf("  paper-width = %.2f\\mm\n", $lineWidth + 20)
                . "  paper-height = 340\\mm\n"
                . "  top-margin = 10\\mm\n"
                . "  bottom-margin = 10\\mm\n"
                . "  left-margin = 10\\mm\n"
                . "  right-margin = 10\\mm";
        }

        return str_replace(
            [
                '@STAFFSIZE@', '@PAPER@', '@LINEWIDTH@',
                '@VARIANT@', '@CLEF@', '@RANGE@', '@LEGEND@', '@STAVES@',
            ],
            [
                (string) $staffSize,
                $paper,
                sprintf('%.2f', $lineWidth),
                $variant->sizeLabel() . ' ' . $variant->label,
                $clef === self::RANDOM_CLEF ? 'mixed' : self::clef($clef)['lily'],
                self::range($variant, $clef, $rangeMode),
                self::legendComment($variant, $spelling),
                implode("\n", $staves),
            ],
            $template
        );
    }

    /** One staff: n measures, thick bar lines on the box boundaries. */
    private static function staffFor(
        array $grid,
        int $r,
        array $clef,
        Variant $variant,
        string $spelling,
        int $seed,
        string $rangeMode
    ): string {
        $n     = $variant->n;
        $parts = [];

        for ($c = 0; $c < $n; $c++) {
            $cell = $r * $n + $c;
            $v    = $grid[$cell];

            if ($v === 0) {
                // s1 is an invisible spacer: an empty measure to be filled in
                $parts[] = 's1';
            } else {
                $degree  = $v - 1;
                $which   = $variant->spellingFor($spelling, $degree, $seed, $cell);
                $marks   = self::marksFor($variant, $degree, $clef, $rangeMode, $seed, $cell);
                $parts[] = $variant->pitchLy($degree, $which) . self::octaveMark($marks) . '1';
            }

            if ($c === $n - 1) {
                $parts[] = '\bar "|."';                   // closing edge of the grid
            } elseif (($c + 1) % $variant->boxWidth === 0) {
                $parts[] = '\bar "."';                    // thick: box boundary
            } else {
                $parts[] = '|';                           // bar check
            }
        }

        return '\new Staff { \clef ' . $clef['lily'] . ' \accidentalStyle forget '
            . implode(' ', $parts)
            . ' }';
    }

    // -------------------------------------------------------------- rendering

    private static function legendComment(Variant $variant, string $spelling): string
    {
        $parts = [];
        foreach ($variant->legend($spelling) as $symbol => $name) {
            $parts[] = $symbol . '=' . $name;
        }
        return implode(' ', $parts);
    }

    /**
     * Run LilyPond over the given source, cached by content hash.
     *
     * @return array{ok:bool,path:?string,content:?string,cached:bool,log:string,command:string,warnings:array}
     */
    public static function render(string $ly, string $cacheDir, string $format = 'svg'): array
    {
        $format = $format === 'pdf' ? 'pdf' : 'svg';
        $result = [
            'ok'       => false,
            'path'     => null,
            'content'  => null,
            'cached'   => false,
            'log'      => '',
            'command'  => '',
            'warnings' => [],
        ];

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            $result['log'] = "cache directory $cacheDir is not writable";
            return $result;
        }

        $hash  = substr(sha1($ly . '|' . $format), 0, 20);
        $base  = rtrim($cacheDir, '/') . '/' . $hash;
        $final = $base . '.' . $format;

        if (is_file($final) && filesize($final) > 0) {
            $result['ok']      = true;
            $result['cached']  = true;
            $result['path']    = $final;
            $result['content'] = (string) file_get_contents($final);
            $result['warnings'] = self::inspect($result['content'], $format);
            return $result;
        }

        $lyFile = $base . '.ly';
        if (file_put_contents($lyFile, $ly) === false) {
            $result['log'] = "could not write $lyFile";
            return $result;
        }

        $args = [escapeshellarg(self::binary())];
        if ($format === 'svg') {
            $args[] = '-dbackend=svg';
        } else {
            $args[] = '--pdf';
        }
        $args[] = '-dno-point-and-click';
        if (self::supportsCrop()) {
            $args[] = '-dcrop';
        }
        $args[] = escapeshellarg('--output=' . $base);
        $args[] = escapeshellarg($lyFile);

        $cmd = implode(' ', $args);
        $result['command'] = $cmd;

        $out = [];
        $status = 0;
        @exec($cmd . ' 2>&1', $out, $status);
        $result['log'] = implode("\n", $out);

        // -dcrop writes <base>.cropped.<ext>; otherwise LilyPond may page-number
        // the file, so fall back through the plausible names.
        $candidates = array_merge(
            [$base . '.cropped.' . $format, $final, $base . '-1.' . $format, $base . '-page1.' . $format],
            glob($base . '*.' . $format) ?: []
        );

        $found = null;
        foreach ($candidates as $file) {
            if (is_file($file) && filesize($file) > 0) {
                $found = $file;
                break;
            }
        }

        if ($found === null) {
            $result['log'] = trim("LilyPond produced no $format output (exit $status)\n" . $result['log']);
            return $result;
        }

        if ($found !== $final) {
            copy($found, $final);
        }

        $result['ok']       = true;
        $result['path']     = $final;
        $result['content']  = (string) file_get_contents($final);
        $result['warnings'] = self::inspect($result['content'], $format);

        return $result;
    }

    /**
     * LilyPond's SVG backend can emit glyphs as <text> referencing the
     * Emmentaler music font rather than as outlines. That renders fine locally
     * but shows tofu in a browser without the font installed, so say so plainly
     * instead of leaving a mysteriously blank staff.
     */
    private static function inspect(string $content, string $format): array
    {
        $warnings = [];
        if ($format !== 'svg') {
            return $warnings;
        }
        $usesFont   = stripos($content, 'Emmentaler') !== false;
        $embedsFont = stripos($content, '@font-face') !== false;
        if ($usesFont && !$embedsFont) {
            $warnings[] = 'This SVG references the Emmentaler music font by name without embedding it. '
                . 'If noteheads and clefs are missing in the browser, either install Emmentaler as a '
                . 'system font or add -dsvg-woff to the LilyPond invocation in StaffRenderer::render().';
        }
        return $warnings;
    }

    /** Strip the XML prologue so the SVG can be dropped straight into HTML. */
    public static function inlineSvg(string $svg): string
    {
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
        $svg = preg_replace(
            '/<svg\b/',
            '<svg style="max-width:100%;height:auto;display:block"',
            $svg,
            1
        ) ?? $svg;
        return trim($svg);
    }
}
