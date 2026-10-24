<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every word that can be traced on a fixed printed Boggle board:
 * step from each letter to a touching one (the eight neighbours, diagonals
 * included), never using the same square twice, spelling the word as you go.
 *
 * The board is hardcoded per subclass (grid()), so each board is its own
 * self-contained, zero-argument rule -- one mechanism, a whole family of
 * puzzles, exactly like the Contains* cards. One square may hold "Qu" (real
 * Boggle), which counts as the two letters Q and U together when traced.
 *
 * PERFORMANCE. Tracing a word on the board is a backtracking search -- far too
 * expensive to run on every failsWord() during construction. So the traceable
 * words are PRECOMPUTED once by bin/compute-boggle.php (which calls trace()
 * over the whole dictionary) and written to data/boggle/<ClassName>.txt; at
 * build time failsWord() is just a set lookup. The file's line count IS the
 * rule's cardinality, known without any runtime search -- the encapsulation
 * Ian asked every rule to keep.
 */
abstract class BoggleRule extends SpellingRuleBase
{
    public const MIN_LENGTH = 3;

    /** @var array<string,array<string,bool>> class => {word => true} precomputed traceable set */
    private static array $cache = [];

    /**
     * The board: rows of cells, each cell a lowercase string of one letter
     * (or "qu" for the Qu tile). Every subclass pins its own grid.
     *
     * @return string[][]
     */
    abstract protected function board(): array;

    /** The board, exposed for the renderer and the compute tool. @return string[][] */
    final public function grid(): array
    {
        return $this->board();
    }

    public function failsWord(string $word): bool
    {
        return strlen($word) >= self::MIN_LENGTH && isset($this->traceableSet()[$word]);
    }

    public function describe(): string
    {
        return 'words that can be traced on the printed Boggle board (' . self::MIN_LENGTH . '+ letters)';
    }

    /**
     * The printed instruction, with the board drawn out row by row. The "Qu"
     * note is included only when the board actually has a multi-letter tile; a
     * subclass may override this wholesale for fully bespoke copy.
     */
    public function ruleText(): string
    {
        $rows = [];
        $hasMultiTile = false;
        foreach ($this->board() as $row) {
            foreach ($row as $cell) {
                if (strlen($cell) > 1) {
                    $hasMultiTile = true;
                }
            }
            $rows[] = implode(' ', array_map(static fn (string $c): string => ucfirst($c), $row));
        }
        $text = "Here is a letter board:\n" . implode("\n", $rows) . "\n\n"
            . 'Cross out every word of ' . self::MIN_LENGTH . ' or more letters that you can trace on it: '
            . 'start on any square, move to a touching square (sideways, up, down, or diagonally), and keep '
            . 'going, spelling the word one square at a time. You may not use the same square twice in one word.';
        if ($hasMultiTile) {
            $text .= ' The "Qu" square counts as the two letters Q and U together.';
        }
        return $text;
    }

    /** @return array<string,bool> */
    private function traceableSet(): array
    {
        $class = static::class;
        if (!isset(self::$cache[$class])) {
            $path = self::wordsFile($class);
            if (!is_file($path)) {
                throw new \RuntimeException(
                    "Boggle word list missing for {$class}: {$path}. Run `php bin/compute-boggle.php` first."
                );
            }
            $words = array_filter(array_map('trim', explode("\n", (string) file_get_contents($path))), static fn (string $w): bool => $w !== '');
            self::$cache[$class] = array_fill_keys($words, true);
        }
        return self::$cache[$class];
    }

    /** Where this board's precomputed word list lives. */
    public static function wordsFile(string $class): string
    {
        $short = (new \ReflectionClass($class))->getShortName();
        return dirname(__DIR__, 3) . "/data/boggle/{$short}.txt";
    }

    /**
     * True if $word can be traced on $board under Boggle rules. Used by the
     * compute tool to build the precomputed list; NOT called at build time.
     *
     * @param string[][] $board
     */
    public static function trace(array $board, string $word): bool
    {
        $rows = count($board);
        $cols = count($board[0]);
        $cells = [];        // index => string
        $neighbours = [];   // index => int[]
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $i = $r * $cols + $c;
                $cells[$i] = $board[$r][$c];
                $list = [];
                for ($dr = -1; $dr <= 1; $dr++) {
                    for ($dc = -1; $dc <= 1; $dc++) {
                        if ($dr === 0 && $dc === 0) {
                            continue;
                        }
                        $rr = $r + $dr;
                        $cc = $c + $dc;
                        if ($rr >= 0 && $rr < $rows && $cc >= 0 && $cc < $cols) {
                            $list[] = $rr * $cols + $cc;
                        }
                    }
                }
                $neighbours[$i] = $list;
            }
        }

        $dfs = static function (int $cell, int $pos, int $used) use (&$dfs, $word, $cells, $neighbours): bool {
            if ($pos === strlen($word)) {
                return true;
            }
            foreach ($neighbours[$cell] as $n) {
                if ($used & (1 << $n)) {
                    continue;
                }
                $s = $cells[$n];
                $len = strlen($s);
                if (substr($word, $pos, $len) === $s && $dfs($n, $pos + $len, $used | (1 << $n))) {
                    return true;
                }
            }
            return false;
        };

        foreach ($cells as $i => $s) {
            $len = strlen($s);
            if (substr($word, 0, $len) === $s && $dfs($i, $len, 1 << $i)) {
                return true;
            }
        }
        return false;
    }
}
