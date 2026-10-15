<?php
declare(strict_types=1);

/**
 * A puzzle family: grid geometry plus the musical meaning of its symbols.
 *
 * The grid size follows from the symbol set, because a sudoku of n symbols
 * needs boxes of w x h where w * h = n. Twelve chromatic pitch classes give a
 * 12x12 on 4x3 boxes; eight scale degrees give an 8x8 on 4x2 boxes.
 *
 * Each degree carries both spellings of its pitch so a puzzle can be engraved
 * with sharps, with flats, or with a mix - the note is the same key either way,
 * which is the point of the enharmonic exercise.
 */
final class Variant
{
    public const DEFAULT = 'chromatic';

    /** Accidental spellings a variant can be engraved with. */
    public const SPELLINGS = [
        'sharps' => 'Sharps',
        'flats'  => 'Flats',
        'mixed'  => 'Mixed (enharmonic)',
    ];

    public string $key;
    public string $label;
    public string $description;
    public int $n;
    public int $boxWidth;
    public int $boxHeight;
    public string $alphabet;
    /** @var array<int,array{oct:int,sharp:array{ly:string,name:string},flat:array{ly:string,name:string}}> */
    public array $degrees;

    private function __construct(array $spec)
    {
        $this->key         = $spec['key'];
        $this->label       = $spec['label'];
        $this->description = $spec['description'];
        $this->n           = $spec['n'];
        $this->boxWidth    = $spec['boxWidth'];
        $this->boxHeight   = $spec['boxHeight'];
        $this->alphabet    = $spec['alphabet'];
        $this->degrees     = $spec['degrees'];
    }

    // ------------------------------------------------------------- registry

    private static array $cache = [];

    public static function keys(): array
    {
        return array_keys(self::specs());
    }

    public static function exists(string $key): bool
    {
        return isset(self::specs()[$key]);
    }

    public static function name(string $key): string
    {
        return self::exists($key) ? $key : self::DEFAULT;
    }

    public static function get(string $key): self
    {
        $key = self::name($key);
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = new self(self::specs()[$key]);
        }
        return self::$cache[$key];
    }

    /** @return self[] */
    public static function all(): array
    {
        return array_map([self::class, 'get'], self::keys());
    }

    // ---------------------------------------------------------------- shape

    public function cells(): int
    {
        return $this->n * $this->n;
    }

    public function stacks(): int
    {
        return intdiv($this->n, $this->boxWidth);
    }

    public function bands(): int
    {
        return intdiv($this->n, $this->boxHeight);
    }

    public function symbol(int $value): string
    {
        return $this->alphabet[$value - 1];
    }

    public function boxLabel(): string
    {
        return $this->boxWidth . ' wide x ' . $this->boxHeight . ' tall';
    }

    public function sizeLabel(): string
    {
        return $this->n . 'x' . $this->n;
    }

    // -------------------------------------------------------------- pitches

    public function pitchClass(int $index): int
    {
        return $this->degrees[$index]['pc'];
    }

    /**
     * Can this set be engraved across the full staff range?
     *
     * Only if every degree is a distinct pitch class. The diatonic set fails
     * this: degrees 1 and 8 are both C, told apart purely by octave. Free the
     * octave and a written C becomes ambiguous - it could be either symbol - so
     * full range is refused rather than producing an unsolvable worksheet.
     */
    public function allowsFullRange(): bool
    {
        $seen = [];
        foreach ($this->degrees as $d) {
            if (isset($seen[$d['pc']])) {
                return false;
            }
            $seen[$d['pc']] = true;
        }
        return true;
    }

    /** Why full range is unavailable, or '' when it is available. */
    public function fullRangeBlockedBecause(): string
    {
        if ($this->allowsFullRange()) {
            return '';
        }
        return 'The ' . $this->label . ' set repeats a pitch class (degrees 1 and 8 are both C), '
            . 'so they are told apart only by octave. Freeing the octave would make a written C '
            . 'ambiguous, so this set stays inside one octave.';
    }

    /** Does this degree have two spellings? Only the black keys do. */
    public function isEnharmonic(int $index): bool
    {
        return $this->degrees[$index]['sharp']['ly'] !== $this->degrees[$index]['flat']['ly'];
    }

    /**
     * Which spelling to use for one degree.
     *
     * 'mixed' must be reproducible: the same puzzle has to engrave identically
     * every time or the cached score would stop matching the cached puzzle. So
     * the choice is derived from the seed and the cell, never from rand().
     */
    public function spellingFor(string $mode, int $index, int $seed, int $cell): string
    {
        if ($mode === 'flats') {
            return 'flat';
        }
        if ($mode !== 'mixed' || !$this->isEnharmonic($index)) {
            return 'sharp';
        }
        // Cheap deterministic hash of (seed, cell, degree).
        $h = crc32($seed . ':' . $cell . ':' . $index);
        return ($h & 1) === 0 ? 'sharp' : 'flat';
    }

    /** LilyPond pitch name for a degree, without the octave mark. */
    public function pitchLy(int $index, string $spelling): string
    {
        return $this->degrees[$index][$spelling]['ly'];
    }

    /** How many octaves above the clef's base this degree sits. */
    public function pitchOctave(int $index): int
    {
        return $this->degrees[$index]['oct'];
    }

    /** Human-readable pitch name, e.g. "C#", "Db", or "C#/Db" when mixed. */
    public function pitchName(int $index, string $mode = 'sharps'): string
    {
        $sharp = $this->degrees[$index]['sharp']['name'];
        $flat  = $this->degrees[$index]['flat']['name'];
        if ($sharp === $flat) {
            return $sharp;
        }
        if ($mode === 'flats') {
            return $flat;
        }
        if ($mode === 'mixed') {
            return $sharp . '/' . $flat;
        }
        return $sharp;
    }

    /** @return string[] symbol => pitch name, for legends */
    public function legend(string $mode = 'sharps'): array
    {
        $out = [];
        for ($i = 0; $i < $this->n; $i++) {
            $out[$this->alphabet[$i]] = $this->pitchName($i, $mode);
        }
        return $out;
    }

    // --------------------------------------------------------------- specs

    private static function specs(): array
    {
        static $specs = null;
        if ($specs !== null) {
            return $specs;
        }

        // pc is the pitch class, 0 = C .. 11 = B. It is what lets the renderer
        // work out which octaves a degree may occupy within a clef's bounds.
        $nat = static fn (string $ly, string $name, int $pc, int $oct = 0): array => [
            'pc'    => $pc,
            'oct'   => $oct,
            'sharp' => ['ly' => $ly, 'name' => $name],
            'flat'  => ['ly' => $ly, 'name' => $name],
        ];
        $alt = static fn (string $sLy, string $sName, string $fLy, string $fName, int $pc): array => [
            'pc'    => $pc,
            'oct'   => 0,
            'sharp' => ['ly' => $sLy, 'name' => $sName],
            'flat'  => ['ly' => $fLy, 'name' => $fName],
        ];

        $specs = [
            'chromatic' => [
                'key'         => 'chromatic',
                'label'       => 'Chromatic (12 tones)',
                'description' => 'All twelve pitch classes, one per symbol. 12x12 grid on 4x3 boxes.',
                'n'           => 12,
                'boxWidth'    => 4,
                'boxHeight'   => 3,
                'alphabet'    => '0123456789AB',
                'degrees'     => [
                    $nat('c', 'C', 0),
                    $alt('cis', 'C#', 'des', 'Db', 1),
                    $nat('d', 'D', 2),
                    $alt('dis', 'D#', 'ees', 'Eb', 3),
                    $nat('e', 'E', 4),
                    $nat('f', 'F', 5),
                    $alt('fis', 'F#', 'ges', 'Gb', 6),
                    $nat('g', 'G', 7),
                    $alt('gis', 'G#', 'aes', 'Ab', 8),
                    $nat('a', 'A', 9),
                    $alt('ais', 'A#', 'bes', 'Bb', 10),
                    $nat('b', 'B', 11),
                ],
            ],

            'diatonic' => [
                'key'         => 'diatonic',
                'label'       => 'Diatonic + octave (8 tones)',
                'description' => 'Scale degrees 1-8 of C major, 8 being the octave. '
                    . '8x8 grid on 4x2 boxes. No accidentals - good first worksheets.',
                'n'           => 8,
                'boxWidth'    => 4,
                'boxHeight'   => 2,
                'alphabet'    => '12345678',
                'degrees'     => [
                    $nat('c', 'C', 0),
                    $nat('d', 'D', 2),
                    $nat('e', 'E', 4),
                    $nat('f', 'F', 5),
                    $nat('g', 'G', 7),
                    $nat('a', 'A', 9),
                    $nat('b', 'B', 11),
                    $nat('c', 'C', 0, 1),   // the octave: same pitch class, one octave up
                ],
            ],
        ];

        return $specs;
    }
}
