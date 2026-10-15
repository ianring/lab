<?php
declare(strict_types=1);

/**
 * Sudoku engine for any rectangular variant: n symbols on an n x n grid divided
 * into boxes w wide by h tall, where w * h = n.
 *
 * The geometry comes from a Variant, so one engine covers the 12x12 chromatic
 * grid on 4x3 boxes and the 8x8 diatonic grid on 4x2 boxes alike. Instances are
 * memoised per variant by for() - the unit, peer and popcount tables are worth
 * building once.
 *
 * Generation guarantees, by construction:
 *   - every puzzle is a subset of a known-complete valid grid, so a solution exists;
 *   - a cell is only dug out if the pure-inference solver can still finish the grid,
 *     and every technique in that solver is a sound elimination, so a full logical
 *     solve implies the solution is unique. No brute force is ever required.
 *
 * Note on subset and fish sizes: both are capped at 4. For n = 8 that cap is
 * mathematically complete, since a subset of size k in a unit of 8 is the
 * complement of one of size 8 - k. For n = 12 it is a deliberate truncation -
 * sizes 5 and 6 exist but are rare and expensive to search.
 */
final class SudokuEngine
{

    /** key, tier, cost, human label */
    private const LADDER = [
        ['nakedSingle',  1,  1, 'Naked single'],
        ['hiddenSingle', 2,  2, 'Hidden single'],
        ['nakedPair',    3,  5, 'Naked pair'],
        ['nakedTriple',  3,  8, 'Naked triple'],
        ['nakedQuad',    3, 12, 'Naked quad'],
        ['hiddenPair',   4,  7, 'Hidden pair'],
        ['hiddenTriple', 4, 10, 'Hidden triple'],
        ['hiddenQuad',   4, 14, 'Hidden quad'],
        ['intersection', 5,  6, 'Pointing / claiming'],
        ['xWing',        6, 20, 'X-Wing'],
        ['swordfish',    6, 30, 'Swordfish'],
        ['jellyfish',    6, 40, 'Jellyfish'],
        ['xyWing',       7, 25, 'XY-Wing'],
        ['colouring',    7, 30, 'Simple colouring'],
    ];

    /**
     * cap      - highest technique tier the digger is allowed to rely on
     * min      - retry generation until the puzzle actually needs this tier
     * attempts - how many complete grid + dig cycles to spend chasing min
     */
    public const DIFFICULTIES = [
        'easy'    => ['cap' => 2, 'min' => 1, 'attempts' => 4],
        'medium'  => ['cap' => 4, 'min' => 3, 'attempts' => 6],
        'hard'    => ['cap' => 5, 'min' => 5, 'attempts' => 8],
        'expert'  => ['cap' => 6, 'min' => 6, 'attempts' => 8],
        'extreme' => ['cap' => 7, 'min' => 7, 'attempts' => 6],
    ];

    public Variant $variant;

    private int $n;
    private int $bw;
    private int $bh;
    private int $stacks;
    private int $cells;
    private int $all;
    private string $alphabet;

    private array $units    = [];
    private array $unitsOf  = [];
    private array $peers    = [];
    private array $peerSet  = [];
    private array $popcount = [];
    private array $bitIndex = [];

    /** Step-by-step trace: null when tracing is off. */
    private ?array $trace = null;
    /** Eliminations accumulated by the technique currently running. */
    private array $pending = [];

    /** @var array<string,self> */
    private static array $engines = [];

    /** Engine for a variant, built once and reused. */
    public static function forVariant(string $variantKey): self
    {
        $key = Variant::name($variantKey);
        if (!isset(self::$engines[$key])) {
            self::$engines[$key] = new self(Variant::get($key));
        }
        return self::$engines[$key];
    }

    public function __construct(Variant $variant)
    {
        $this->variant  = $variant;
        $this->n        = $variant->n;
        $this->bw       = $variant->boxWidth;
        $this->bh       = $variant->boxHeight;
        $this->stacks   = $variant->stacks();
        $this->cells    = $variant->cells();
        $this->all      = (1 << $this->n) - 1;
        $this->alphabet = $variant->alphabet;
        $this->buildTables();
    }

    public function n(): int
    {
        return $this->n;
    }

    public function cells(): int
    {
        return $this->cells;
    }

    private function bands(): int
    {
        return intdiv($this->n, $this->bh);
    }

    // ---------------------------------------------------------------- geometry

    private function buildTables(): void
    {
        $this->popcount[0] = 0;
        for ($m = 1; $m <= $this->all; $m++) {
            $this->popcount[$m] = $this->popcount[$m >> 1] + ($m & 1);
        }
        for ($v = 1; $v <= $this->n; $v++) {
            $this->bitIndex[1 << ($v - 1)] = $v;
        }

        $units = [];
        for ($r = 0; $r < $this->n; $r++) {          // units 0..n-1 rows
            $u = [];
            for ($c = 0; $c < $this->n; $c++) {
                $u[] = $r * $this->n + $c;
            }
            $units[] = $u;
        }
        for ($c = 0; $c < $this->n; $c++) {          // units n..2n-1 columns
            $u = [];
            for ($r = 0; $r < $this->n; $r++) {
                $u[] = $r * $this->n + $c;
            }
            $units[] = $u;
        }
        for ($b = 0; $b < $this->n; $b++) {          // units 2n..3n-1 boxes
            $r0 = intdiv($b, $this->stacks) * $this->bh;
            $c0 = ($b % $this->stacks) * $this->bw;
            $u  = [];
            for ($dr = 0; $dr < $this->bh; $dr++) {
                for ($dc = 0; $dc < $this->bw; $dc++) {
                    $u[] = ($r0 + $dr) * $this->n + ($c0 + $dc);
                }
            }
            $units[] = $u;
        }
        $this->units = $units;

        $this->unitsOf = array_fill(0, $this->cells, []);
        foreach ($units as $id => $u) {
            foreach ($u as $cell) {
                $this->unitsOf[$cell][] = $id;
            }
        }

        for ($cell = 0; $cell < $this->cells; $cell++) {
            $set = [];
            foreach ($this->unitsOf[$cell] as $id) {
                foreach ($units[$id] as $p) {
                    if ($p !== $cell) {
                        $set[$p] = true;
                    }
                }
            }
            $this->peerSet[$cell] = $set;
            $this->peers[$cell]   = array_keys($set);
        }
    }

    public function boxOf(int $r, int $c): int
    {
        return intdiv($r, $this->bh) * $this->stacks + intdiv($c, $this->bw);
    }

    private function maskValues(int $m): array
    {
        $out = [];
        for ($v = 1; $v <= $this->n; $v++) {
            if ($m & (1 << ($v - 1))) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function symbolOf(int $v): string
    {
        return $this->alphabet[$v - 1];
    }

    public function symbolsOf(int $mask): string
    {
        $out = [];
        foreach ($this->maskValues($mask) as $v) {
            $out[] = $this->alphabet[$v - 1];
        }
        return implode(',', $out);
    }

    /** Standard sudoku notation, 1-based: cell 0 is r1c1. */
    public function cellName(int $cell): string
    {
        return 'r' . (intdiv($cell, $this->n) + 1) . 'c' . ($cell % $this->n + 1);
    }

    public function cellNames(array $cells): string
    {
        return implode(' ', array_map([$this, 'cellName'], $cells));
    }

    public function unitName(int $id): string
    {
        if ($id < $this->n) {
            return 'row ' . ($id + 1);
        }
        if ($id < 2 * $this->n) {
            return 'column ' . ($id - $this->n + 1);
        }
        return 'box ' . ($id - 2 * $this->n + 1);
    }

    private static function combos(array $items, int $k): \Generator
    {
        $n = count($items);
        if ($k <= 0 || $k > $n) {
            return;
        }
        $idx = range(0, $k - 1);
        while (true) {
            $out = [];
            foreach ($idx as $i) {
                $out[] = $items[$i];
            }
            yield $out;
            $i = $k - 1;
            while ($i >= 0 && $idx[$i] === $n - $k + $i) {
                $i--;
            }
            if ($i < 0) {
                return;
            }
            $idx[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $idx[$j] = $idx[$j - 1] + 1;
            }
        }
    }

    // ------------------------------------------------------- complete grids

    /** Randomised backtracking with most-constrained-cell-first; pattern fallback. */
    public function generateSolution(): array
    {
        $g   = array_fill(0, $this->cells, 0);
        $row = array_fill(0, $this->n, 0);
        $col = array_fill(0, $this->n, 0);
        $box = array_fill(0, $this->n, 0);
        $budget = 300000;

        if ($this->fill($g, $row, $col, $box, $budget)) {
            return $g;
        }
        return $this->shuffleGrid($this->patternGrid());
    }

    private function fill(array &$g, array &$row, array &$col, array &$box, int &$budget): bool
    {
        if ($budget-- <= 0) {
            return false;
        }

        $best = -1;
        $bestMask = 0;
        $bestCount = $this->n + 1;
        for ($i = 0; $i < $this->cells; $i++) {
            if ($g[$i] !== 0) {
                continue;
            }
            $r = intdiv($i, $this->n);
            $c = $i % $this->n;
            $m = $this->all & ~($row[$r] | $col[$c] | $box[$this->boxOf($r, $c)]);
            $n = $this->popcount[$m];
            if ($n === 0) {
                return false;
            }
            if ($n < $bestCount) {
                $bestCount = $n;
                $best      = $i;
                $bestMask  = $m;
                if ($n === 1) {
                    break;
                }
            }
        }
        if ($best === -1) {
            return true;
        }

        $r = intdiv($best, $this->n);
        $c = $best % $this->n;
        $b = $this->boxOf($r, $c);
        $vals = $this->maskValues($bestMask);
        shuffle($vals);
        foreach ($vals as $v) {
            $bit = 1 << ($v - 1);
            $g[$best] = $v;
            $row[$r] |= $bit;
            $col[$c] |= $bit;
            $box[$b] |= $bit;
            if ($this->fill($g, $row, $col, $box, $budget)) {
                return true;
            }
            $g[$best] = 0;
            $row[$r] &= ~$bit;
            $col[$c] &= ~$bit;
            $box[$b] &= ~$bit;
        }
        return false;
    }

    /** Canonical valid grid: value(r,c) = (BW*(r mod BH) + floor(r/BH) + c) mod N. */
    public function patternGrid(): array
    {
        $g = [];
        for ($r = 0; $r < $this->n; $r++) {
            for ($c = 0; $c < $this->n; $c++) {
                $g[$r * $this->n + $c] =
                    (($this->bw * ($r % $this->bh) + intdiv($r, $this->bh) + $c) % $this->n) + 1;
            }
        }
        return $g;
    }

    /**
     * Validity-preserving shuffles. No transpose: 4x3 boxes do not survive one.
     */
    public function shuffleGrid(array $g): array
    {
        $map = range(1, $this->n);
        shuffle($map);

        $bands = range(0, $this->bands() - 1);
        shuffle($bands);
        $rowOrder = [];
        foreach ($bands as $b) {
            $rs = range($b * $this->bh, $b * $this->bh + $this->bh - 1);
            shuffle($rs);
            foreach ($rs as $r) {
                $rowOrder[] = $r;
            }
        }

        $stacks = range(0, $this->stacks - 1);
        shuffle($stacks);
        $colOrder = [];
        foreach ($stacks as $s) {
            $cs = range($s * $this->bw, $s * $this->bw + $this->bw - 1);
            shuffle($cs);
            foreach ($cs as $c) {
                $colOrder[] = $c;
            }
        }

        $out = array_fill(0, $this->cells, 0);
        for ($r = 0; $r < $this->n; $r++) {
            for ($c = 0; $c < $this->n; $c++) {
                $out[$r * $this->n + $c] = $map[$g[$rowOrder[$r] * $this->n + $colOrder[$c]] - 1];
            }
        }
        return $out;
    }

    // ------------------------------------------------------- brute force check

    /** Counts solutions, aborting once $limit is reached. Verification only. */
    public function countSolutions(array $g, int $limit = 2): int
    {
        $row = array_fill(0, $this->n, 0);
        $col = array_fill(0, $this->n, 0);
        $box = array_fill(0, $this->n, 0);

        for ($i = 0; $i < $this->cells; $i++) {
            $v = $g[$i];
            if ($v === 0) {
                continue;
            }
            $r = intdiv($i, $this->n);
            $c = $i % $this->n;
            $b = $this->boxOf($r, $c);
            $bit = 1 << ($v - 1);
            if (($row[$r] | $col[$c] | $box[$b]) & $bit) {
                return 0;
            }
            $row[$r] |= $bit;
            $col[$c] |= $bit;
            $box[$b] |= $bit;
        }

        $count = 0;
        $this->countDfs($g, $row, $col, $box, $count, $limit);
        return $count;
    }

    private function countDfs(array &$g, array &$row, array &$col, array &$box, int &$count, int $limit): void
    {
        if ($count >= $limit) {
            return;
        }

        $best = -1;
        $bestMask = 0;
        $bestCount = $this->n + 1;
        for ($i = 0; $i < $this->cells; $i++) {
            if ($g[$i] !== 0) {
                continue;
            }
            $r = intdiv($i, $this->n);
            $c = $i % $this->n;
            $m = $this->all & ~($row[$r] | $col[$c] | $box[$this->boxOf($r, $c)]);
            $n = $this->popcount[$m];
            if ($n === 0) {
                return;
            }
            if ($n < $bestCount) {
                $bestCount = $n;
                $best      = $i;
                $bestMask  = $m;
                if ($n === 1) {
                    break;
                }
            }
        }
        if ($best === -1) {
            $count++;
            return;
        }

        $r = intdiv($best, $this->n);
        $c = $best % $this->n;
        $b = $this->boxOf($r, $c);
        foreach ($this->maskValues($bestMask) as $v) {
            $bit = 1 << ($v - 1);
            $g[$best] = $v;
            $row[$r] |= $bit;
            $col[$c] |= $bit;
            $box[$b] |= $bit;
            $this->countDfs($g, $row, $col, $box, $count, $limit);
            $g[$best] = 0;
            $row[$r] &= ~$bit;
            $col[$c] &= ~$bit;
            $box[$b] &= ~$bit;
            if ($count >= $limit) {
                return;
            }
        }
    }

    // ------------------------------------------------------ logical solver

    /**
     * Pure-inference solver. Never guesses.
     *
     * @return array{solved:bool,contradiction:bool,grid:array,log:array,maxTier:int,score:int,label:string}
     */
    public function solveLogical(array $g, int $tierCap = 7, bool $trace = false): array
    {
        $this->trace   = $trace ? [] : null;
        $this->pending = [];

        $cand = array_fill(0, $this->cells, 0);
        $row  = array_fill(0, $this->n, 0);
        $col  = array_fill(0, $this->n, 0);
        $box  = array_fill(0, $this->n, 0);

        for ($i = 0; $i < $this->cells; $i++) {
            $v = $g[$i];
            if ($v === 0) {
                continue;
            }
            $r = intdiv($i, $this->n);
            $c = $i % $this->n;
            $b = $this->boxOf($r, $c);
            $bit = 1 << ($v - 1);
            if (($row[$r] | $col[$c] | $box[$b]) & $bit) {
                return $this->report($g, [], true, $tierCap);
            }
            $row[$r] |= $bit;
            $col[$c] |= $bit;
            $box[$b] |= $bit;
        }
        for ($i = 0; $i < $this->cells; $i++) {
            if ($g[$i] !== 0) {
                continue;
            }
            $r = intdiv($i, $this->n);
            $c = $i % $this->n;
            $cand[$i] = $this->all & ~($row[$r] | $col[$c] | $box[$this->boxOf($r, $c)]);
            if ($cand[$i] === 0) {
                return $this->report($g, [], true, $tierCap);
            }
        }

        $log  = [];
        $fail = false;
        $steps = 0;

        while (true) {
            if (!in_array(0, $g, true)) {
                break;
            }
            if ($steps++ > 5000) {
                break;
            }

            $progress = false;
            foreach (self::LADDER as [$key, $tier, $cost, $label]) {
                if ($tier > $tierCap) {
                    continue;
                }
                $n = $this->applyTechnique($key, $g, $cand, $fail);
                if ($fail) {
                    return $this->report($g, $log, true, $tierCap);
                }
                if ($n > 0) {
                    $log[$key] = ($log[$key] ?? 0) + $n;
                    $progress = true;
                    break; // restart from the cheapest technique
                }
            }
            if (!$progress) {
                break;
            }
        }

        return $this->report($g, $log, false, $tierCap);
    }

    private function applyTechnique(string $key, array &$g, array &$cand, bool &$fail): int
    {
        switch ($key) {
            case 'nakedSingle':  return $this->nakedSingles($g, $cand, $fail);
            case 'hiddenSingle': return $this->hiddenSingles($g, $cand, $fail);
            case 'nakedPair':    return $this->nakedSubsets($g, $cand, 2, $fail);
            case 'nakedTriple':  return $this->nakedSubsets($g, $cand, 3, $fail);
            case 'nakedQuad':    return $this->nakedSubsets($g, $cand, 4, $fail);
            case 'hiddenPair':   return $this->hiddenSubsets($g, $cand, 2, $fail);
            case 'hiddenTriple': return $this->hiddenSubsets($g, $cand, 3, $fail);
            case 'hiddenQuad':   return $this->hiddenSubsets($g, $cand, 4, $fail);
            case 'intersection': return $this->intersections($g, $cand, $fail);
            case 'xWing':        return $this->fish($g, $cand, 2, $fail);
            case 'swordfish':    return $this->fish($g, $cand, 3, $fail);
            case 'jellyfish':    return $this->fish($g, $cand, 4, $fail);
            case 'xyWing':       return $this->xyWing($g, $cand, $fail);
            case 'colouring':    return $this->colouring($g, $cand, $fail);
        }
        return 0;
    }

    private function report(array $g, array $log, bool $contradiction, int $tierCap): array
    {
        $maxTier = 0;
        $score   = 0;
        $named   = [];
        foreach (self::LADDER as [$key, $tier, $cost, $label]) {
            if (!isset($log[$key])) {
                continue;
            }
            $maxTier = max($maxTier, $tier);
            $score  += $cost * $log[$key];
            $named[$label] = $log[$key];
        }
        $steps = $this->trace ?? [];
        $this->trace   = null;
        $this->pending = [];

        $solved = !$contradiction && !in_array(0, $g, true);
        $empty  = 0;
        foreach ($g as $v) {
            if ($v === 0) {
                $empty++;
            }
        }

        return [
            'solved'        => $solved,
            'contradiction' => $contradiction,
            'unsolvedCells' => $empty,
            'grid'          => $g,
            'log'           => $log,
            'techniques'    => $named,
            'steps'         => $steps,
            'maxTier'       => $maxTier,
            'score'         => $score,
            'label'         => self::labelFor($maxTier),
            'tierCap'       => $tierCap,
        ];
    }

    public static function labelFor(int $tier): string
    {
        if ($tier <= 1) return 'Trivial';
        if ($tier === 2) return 'Easy';
        if ($tier <= 4)  return 'Medium';
        if ($tier === 5) return 'Tricky';
        if ($tier === 6) return 'Hard';
        return 'Expert';
    }

    // ------------------------------------------------------------ primitives

    private function assign(array &$g, array &$cand, int $cell, int $v): bool
    {
        $g[$cell]    = $v;
        $cand[$cell] = 0;
        $bit = 1 << ($v - 1);
        foreach ($this->peers[$cell] as $p) {
            if ($g[$p] === $v) {
                return false;
            }
            if ($g[$p] === 0 && ($cand[$p] & $bit)) {
                $cand[$p] &= ~$bit;
                if ($cand[$p] === 0) {
                    return false;
                }
            }
        }
        return true;
    }

    private function elim(array &$cand, int $cell, int $bits, bool &$fail): bool
    {
        $removed = $cand[$cell] & $bits;
        if (!$removed) {
            return false;
        }
        $cand[$cell] &= ~$removed;
        if ($this->trace !== null) {
            $this->pending[] = ['cell' => $cell, 'mask' => $removed];
        }
        if ($cand[$cell] === 0) {
            $fail = true;
        }
        return true;
    }

    /** Record a digit placement. */
    private function tracePlace(string $key, int $cell, int $v, string $detail): void
    {
        if ($this->trace === null) {
            return;
        }
        $this->trace[] = [
            'type'   => 'place',
            'key'    => $key,
            'cell'   => $cell,
            'value'  => $v,
            'detail' => $detail,
        ];
    }

    /** Flush the eliminations one technique application produced. */
    private function traceStep(string $key, string $detail): void
    {
        if ($this->trace === null || !$this->pending) {
            $this->pending = [];
            return;
        }
        $this->trace[] = [
            'type'   => 'elim',
            'key'    => $key,
            'detail' => $detail,
            'elims'  => $this->pending,
        ];
        $this->pending = [];
    }

    // ------------------------------------------------------------ techniques

    private function nakedSingles(array &$g, array &$cand, bool &$fail): int
    {
        $n = 0;
        do {
            $found = false;
            for ($i = 0; $i < $this->cells; $i++) {
                if ($g[$i] !== 0) {
                    continue;
                }
                $m = $cand[$i];
                if ($m === 0) {
                    $fail = true;
                    return $n;
                }
                if ($this->popcount[$m] === 1) {
                    $v = $this->bitIndex[$m];
                    if (!$this->assign($g, $cand, $i, $v)) {
                        $fail = true;
                        return $n;
                    }
                    $this->tracePlace(
                        'nakedSingle',
                        $i,
                        $v,
                        'every other symbol is already used by a peer of ' . $this->cellName($i)
                    );
                    $n++;
                    $found = true;
                }
            }
        } while ($found);
        return $n;
    }

    private function hiddenSingles(array &$g, array &$cand, bool &$fail): int
    {
        $n = 0;
        foreach ($this->units as $uid => $u) {
            for ($v = 1; $v <= $this->n; $v++) {
                $bit = 1 << ($v - 1);
                $cnt = 0;
                $where = -1;
                $placed = false;
                foreach ($u as $cell) {
                    if ($g[$cell] === $v) {
                        $placed = true;
                        break;
                    }
                    if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                        $cnt++;
                        $where = $cell;
                    }
                }
                if ($placed) {
                    continue;
                }
                if ($cnt === 0) {
                    $fail = true;
                    return $n;
                }
                if ($cnt === 1) {
                    if (!$this->assign($g, $cand, $where, $v)) {
                        $fail = true;
                        return $n;
                    }
                    $this->tracePlace(
                        'hiddenSingle',
                        $where,
                        $v,
                        'the only cell in ' . $this->unitName($uid) . ' that can still hold ' . $this->symbolOf($v)
                    );
                    $n++;
                }
            }
        }
        return $n;
    }

    private function nakedSubsets(array &$g, array &$cand, int $k, bool &$fail): int
    {
        $n = 0;
        $keys = [2 => 'nakedPair', 3 => 'nakedTriple', 4 => 'nakedQuad'];
        foreach ($this->units as $uid => $u) {
            $cells = [];
            foreach ($u as $cell) {
                if ($g[$cell] !== 0) {
                    continue;
                }
                $c = $this->popcount[$cand[$cell]];
                if ($c >= 2 && $c <= $k) {
                    $cells[] = $cell;
                }
            }
            if (count($cells) < $k) {
                continue;
            }
            foreach (self::combos($cells, $k) as $set) {
                $m = 0;
                foreach ($set as $cell) {
                    $m |= $cand[$cell];
                }
                if ($this->popcount[$m] !== $k) {
                    continue;
                }
                $in = array_flip($set);
                $changed = false;
                foreach ($u as $cell) {
                    if ($g[$cell] !== 0 || isset($in[$cell])) {
                        continue;
                    }
                    if ($this->elim($cand, $cell, $m, $fail)) {
                        $changed = true;
                    }
                    if ($fail) {
                        return $n;
                    }
                }
                if ($changed) {
                    $this->traceStep($keys[$k], sprintf(
                        '%s occupy %s between them, so no other cell in %s can use those symbols',
                        $this->symbolsOf($m),
                        $this->cellNames($set),
                        $this->unitName($uid)
                    ));
                    $n++;
                }
            }
        }
        return $n;
    }

    private function hiddenSubsets(array &$g, array &$cand, int $k, bool &$fail): int
    {
        $n = 0;
        $keys = [2 => 'hiddenPair', 3 => 'hiddenTriple', 4 => 'hiddenQuad'];
        foreach ($this->units as $uid => $u) {
            $pos = [];
            for ($v = 1; $v <= $this->n; $v++) {
                $bit = 1 << ($v - 1);
                $m = 0;
                $placed = false;
                foreach ($u as $slot => $cell) {
                    if ($g[$cell] === $v) {
                        $placed = true;
                        break;
                    }
                    if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                        $m |= 1 << $slot;
                    }
                }
                if ($placed) {
                    continue;
                }
                $c = $this->popcount[$m];
                if ($c >= 2 && $c <= $k) {
                    $pos[$v] = $m;
                }
            }
            if (count($pos) < $k) {
                continue;
            }
            foreach (self::combos(array_keys($pos), $k) as $vals) {
                $slots = 0;
                $keep  = 0;
                foreach ($vals as $v) {
                    $slots |= $pos[$v];
                    $keep  |= 1 << ($v - 1);
                }
                if ($this->popcount[$slots] !== $k) {
                    continue;
                }
                $touched = [];
                $changed = false;
                for ($sl = 0; $sl < $this->n; $sl++) {
                    if (!($slots & (1 << $sl))) {
                        continue;
                    }
                    $cell = $u[$sl];
                    $touched[] = $cell;
                    if ($this->elim($cand, $cell, $cand[$cell] & ~$keep, $fail)) {
                        $changed = true;
                    }
                    if ($fail) {
                        return $n;
                    }
                }
                if ($changed) {
                    $this->traceStep($keys[$k], sprintf(
                        '%s can only go in %s within %s, so those cells hold nothing else',
                        $this->symbolsOf($keep),
                        $this->cellNames($touched),
                        $this->unitName($uid)
                    ));
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Pointing (box -> line) and claiming (line -> box). */
    private function intersections(array &$g, array &$cand, bool &$fail): int
    {
        $n = 0;

        // Pointing: candidates for v inside a box confined to one row/column.
        for ($b = 0; $b < $this->n; $b++) {
            $boxCells = $this->units[2 * $this->n + $b];
            $inBox    = array_flip($boxCells);
            for ($v = 1; $v <= $this->n; $v++) {
                $bit = 1 << ($v - 1);
                $rows = [];
                $cols = [];
                $hits = [];
                foreach ($boxCells as $cell) {
                    if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                        $hits[] = $cell;
                        $rows[intdiv($cell, $this->n)] = true;
                        $cols[$cell % $this->n] = true;
                    }
                }
                if (count($hits) < 2) {
                    continue;
                }
                foreach ([[$rows, 0], [$cols, 12]] as [$lines, $offset]) {
                    if (count($lines) !== 1) {
                        continue;
                    }
                    $lineId = array_key_first($lines) + $offset;
                    $changed = false;
                    foreach ($this->units[$lineId] as $cell) {
                        if (isset($inBox[$cell]) || $g[$cell] !== 0) {
                            continue;
                        }
                        if ($this->elim($cand, $cell, $bit, $fail)) {
                            $changed = true;
                        }
                        if ($fail) {
                            return $n;
                        }
                    }
                    if ($changed) {
                        $this->traceStep('intersection', sprintf(
                            'pointing: %s in %s is confined to %s, so it is gone from the rest of that line',
                            $this->symbolOf($v),
                            $this->unitName(2 * $this->n + $b),
                            $this->unitName($lineId)
                        ));
                        $n++;
                    }
                }
            }
        }

        // Claiming: candidates for v inside a line confined to one box.
        for ($id = 0; $id < 2 * $this->n; $id++) {
            $line   = $this->units[$id];
            $inLine = array_flip($line);
            for ($v = 1; $v <= $this->n; $v++) {
                $bit = 1 << ($v - 1);
                $boxes = [];
                $hits  = 0;
                foreach ($line as $cell) {
                    if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                        $hits++;
                        $boxes[$this->boxOf(intdiv($cell, $this->n), $cell % $this->n)] = true;
                    }
                }
                if ($hits < 2 || count($boxes) !== 1) {
                    continue;
                }
                $b = array_key_first($boxes);
                $changed = false;
                foreach ($this->units[2 * $this->n + $b] as $cell) {
                    if (isset($inLine[$cell]) || $g[$cell] !== 0) {
                        continue;
                    }
                    if ($this->elim($cand, $cell, $bit, $fail)) {
                        $changed = true;
                    }
                    if ($fail) {
                        return $n;
                    }
                }
                if ($changed) {
                    $this->traceStep('intersection', sprintf(
                        'claiming: %s in %s must fall inside %s, so it is gone from the rest of that box',
                        $this->symbolOf($v),
                        $this->unitName($id),
                        $this->unitName(2 * $this->n + $b)
                    ));
                    $n++;
                }
            }
        }

        return $n;
    }

    /** X-Wing (k=2), Swordfish (k=3), Jellyfish (k=4), both orientations. */
    private function fish(array &$g, array &$cand, int $k, bool &$fail): int
    {
        $n = 0;
        $keys  = [2 => 'xWing', 3 => 'swordfish', 4 => 'jellyfish'];
        $names = [2 => 'X-Wing', 3 => 'Swordfish', 4 => 'Jellyfish'];

        for ($v = 1; $v <= $this->n; $v++) {
            $bit = 1 << ($v - 1);
            for ($orient = 0; $orient < 2; $orient++) {
                $lines = [];
                for ($l = 0; $l < $this->n; $l++) {
                    $mask = 0;
                    for ($pp = 0; $pp < $this->n; $pp++) {
                        $cell = $orient === 0 ? $l * $this->n + $pp : $pp * $this->n + $l;
                        if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                            $mask |= 1 << $pp;
                        }
                    }
                    $c = $this->popcount[$mask];
                    if ($c >= 2 && $c <= $k) {
                        $lines[$l] = $mask;
                    }
                }
                if (count($lines) < $k) {
                    continue;
                }
                foreach (self::combos(array_keys($lines), $k) as $set) {
                    $union = 0;
                    foreach ($set as $l) {
                        $union |= $lines[$l];
                    }
                    if ($this->popcount[$union] !== $k) {
                        continue;
                    }
                    $inSet   = array_flip($set);
                    $covers  = [];
                    $changed = false;
                    for ($pp = 0; $pp < $this->n; $pp++) {
                        if (!($union & (1 << $pp))) {
                            continue;
                        }
                        $covers[] = $pp + 1;
                        for ($l = 0; $l < $this->n; $l++) {
                            if (isset($inSet[$l])) {
                                continue;
                            }
                            $cell = $orient === 0 ? $l * $this->n + $pp : $pp * $this->n + $l;
                            if ($g[$cell] !== 0) {
                                continue;
                            }
                            if ($this->elim($cand, $cell, $bit, $fail)) {
                                $changed = true;
                            }
                            if ($fail) {
                                return $n;
                            }
                        }
                    }
                    if ($changed) {
                        $this->traceStep($keys[$k], sprintf(
                            '%s on %s: %s %s hold it only in %s %s, clearing it elsewhere in those %s',
                            $names[$k],
                            $this->symbolOf($v),
                            $orient === 0 ? 'rows' : 'columns',
                            implode(',', array_map(static fn (int $l): int => $l + 1, $set)),
                            $orient === 0 ? 'columns' : 'rows',
                            implode(',', $covers),
                            $orient === 0 ? 'columns' : 'rows'
                        ));
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    private function xyWing(array &$g, array &$cand, bool &$fail): int
    {
        $n = 0;
        $biv = [];
        for ($i = 0; $i < $this->cells; $i++) {
            if ($g[$i] === 0 && $this->popcount[$cand[$i]] === 2) {
                $biv[$i] = $cand[$i];
            }
        }

        foreach ($biv as $pivot => $pm) {
            $wings = [];
            foreach ($this->peers[$pivot] as $q) {
                if (isset($biv[$q]) && $this->popcount[$pm & $biv[$q]] === 1) {
                    $wings[] = $q;
                }
            }
            $wc = count($wings);
            for ($a = 0; $a < $wc; $a++) {
                for ($b = $a + 1; $b < $wc; $b++) {
                    $A = $wings[$a];
                    $B = $wings[$b];
                    $am = $biv[$A];
                    $bm = $biv[$B];
                    if (($am & $pm) === ($bm & $pm)) {
                        continue;              // wings must hinge on different pivot symbols
                    }
                    $z = $am & $bm;
                    if ($this->popcount[$z] !== 1 || ($z & $pm)) {
                        continue;              // Z must be shared and absent from the pivot
                    }
                    $changed = false;
                    foreach ($this->peers[$A] as $cell) {
                        if ($cell === $pivot || $cell === $B || $g[$cell] !== 0) {
                            continue;
                        }
                        if (!isset($this->peerSet[$B][$cell])) {
                            continue;
                        }
                        if ($this->elim($cand, $cell, $z, $fail)) {
                            $changed = true;
                        }
                        if ($fail) {
                            return $n;
                        }
                    }
                    if ($changed) {
                        $this->traceStep('xyWing', sprintf(
                            'pivot %s {%s} with wings %s {%s} and %s {%s}: one wing must be %s, so cells seeing both lose it',
                            $this->cellName($pivot),
                            $this->symbolsOf($pm),
                            $this->cellName($A),
                            $this->symbolsOf($am),
                            $this->cellName($B),
                            $this->symbolsOf($bm),
                            $this->symbolsOf($z)
                        ));
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    private function colouring(array &$g, array &$cand, bool &$fail): int
    {
        $n = 0;
        for ($v = 1; $v <= $this->n; $v++) {
            $bit = 1 << ($v - 1);

            $adj = [];
            foreach ($this->units as $u) {
                $cells = [];
                foreach ($u as $cell) {
                    if ($g[$cell] === 0 && ($cand[$cell] & $bit)) {
                        $cells[] = $cell;
                    }
                }
                if (count($cells) === 2) {
                    $adj[$cells[0]][] = $cells[1];
                    $adj[$cells[1]][] = $cells[0];
                }
            }
            if (!$adj) {
                continue;
            }

            $seen = [];
            foreach (array_keys($adj) as $start) {
                if (isset($seen[$start])) {
                    continue;
                }
                $colour = [$start => 0];
                $seen[$start] = true;
                $queue = [$start];
                while ($queue) {
                    $x = array_pop($queue);
                    foreach ($adj[$x] as $y) {
                        if (!isset($colour[$y])) {
                            $colour[$y] = 1 - $colour[$x];
                            $seen[$y] = true;
                            $queue[] = $y;
                        }
                    }
                }
                if (count($colour) < 4) {
                    continue;
                }

                $c0 = [];
                $c1 = [];
                foreach ($colour as $cell => $cc) {
                    if ($cc === 0) {
                        $c0[] = $cell;
                    } else {
                        $c1[] = $cell;
                    }
                }

                // Rule 1: a colour appearing twice in one unit is false everywhere.
                $falseColour = -1;
                $clash = -1;
                foreach ($this->units as $uid => $u) {
                    $cnt = [0, 0];
                    foreach ($u as $cell) {
                        if (isset($colour[$cell])) {
                            $cnt[$colour[$cell]]++;
                        }
                    }
                    if ($cnt[0] >= 2) {
                        $falseColour = 0;
                        $clash = $uid;
                    }
                    if ($cnt[1] >= 2) {
                        $falseColour = 1;
                        $clash = $uid;
                    }
                }

                $changed = false;
                if ($falseColour >= 0) {
                    foreach (($falseColour === 0 ? $c0 : $c1) as $cell) {
                        if ($this->elim($cand, $cell, $bit, $fail)) {
                            $changed = true;
                        }
                        if ($fail) {
                            return $n;
                        }
                    }
                    if ($changed) {
                        $this->traceStep('colouring', sprintf(
                            'chain on %s: one colour appears twice in %s, so every cell of that colour drops %s',
                            $this->symbolOf($v),
                            $this->unitName($clash),
                            $this->symbolOf($v)
                        ));
                        $n++;
                    }
                } else {
                    // Rule 2: an outside cell seeing both colours cannot hold v.
                    for ($x = 0; $x < $this->cells; $x++) {
                        if ($g[$x] !== 0 || isset($colour[$x]) || !($cand[$x] & $bit)) {
                            continue;
                        }
                        $s0 = false;
                        foreach ($c0 as $cell) {
                            if (isset($this->peerSet[$x][$cell])) {
                                $s0 = true;
                                break;
                            }
                        }
                        if (!$s0) {
                            continue;
                        }
                        $s1 = false;
                        foreach ($c1 as $cell) {
                            if (isset($this->peerSet[$x][$cell])) {
                                $s1 = true;
                                break;
                            }
                        }
                        if (!$s1) {
                            continue;
                        }
                        if ($this->elim($cand, $x, $bit, $fail)) {
                            $changed = true;
                        }
                        if ($fail) {
                            return $n;
                        }
                    }
                    if ($changed) {
                        $this->traceStep('colouring', sprintf(
                            'chain on %s across %d cells: one colour must be true, so any cell seeing both colours drops %s',
                            $this->symbolOf($v),
                            count($colour),
                            $this->symbolOf($v)
                        ));
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    // ------------------------------------------------------------ generation

    /**
     * Dig holes out of a complete grid. A cell is only removed if the logical
     * solver can still finish the grid within $cap; because every technique is a
     * sound elimination, a full logical solve also proves the solution is unique.
     */
    private function dig(array $solution, int $cap): array
    {
        $g = $solution;
        $order = range(0, $this->cells - 1);
        shuffle($order);

        foreach ($order as $cell) {
            $save = $g[$cell];
            $g[$cell] = 0;
            $res = $this->solveLogical($g, $cap);
            if (!$res['solved']) {
                $g[$cell] = $save;
            }
        }
        return $g;
    }

    public function generatePuzzle(string $difficulty = 'medium', ?int $seed = null, ?int $attempts = null): Puzzle
    {
        $difficulty = isset(self::DIFFICULTIES[$difficulty]) ? $difficulty : 'medium';
        $spec = self::DIFFICULTIES[$difficulty];
        $attempts = $attempts ?? $spec['attempts'];

        $seed = $seed ?? random_int(0, 2147483647);
        mt_srand($seed);

        $best = null;
        for ($a = 0; $a < $attempts; $a++) {
            $solution = $this->generateSolution();
            $puzzle   = $this->dig($solution, $spec['cap']);
            $res      = $this->solveLogical($puzzle, $spec['cap']);

            $cand = $this->package($difficulty, $seed, $a, $puzzle, $solution, $res);
            if ($best === null || $res['maxTier'] > $best->analysis['maxTier']) {
                $best = $cand;
            }
            if ($res['maxTier'] >= $spec['min']) {
                $best = $cand;
                break;
            }
        }
        return $best;
    }

    private function package(string $difficulty, int $seed, int $attempt, array $puzzle, array $solution, array $res): Puzzle
    {
        return new Puzzle(
            $this->variant->key,
            $puzzle,
            $solution,
            [
                'label'      => $res['label'],
                'maxTier'    => $res['maxTier'],
                'score'      => $res['score'],
                'tierCap'    => $res['tierCap'],
                'solved'     => $res['solved'],
                'techniques' => $res['techniques'],
            ],
            [
                'difficulty' => $difficulty,
                'seed'       => $seed,
                'attempt'    => $attempt,
            ]
        );
    }

    // --------------------------------------------------------- serialisation

    public function gridToString(array $g): string
    {
        $s = '';
        foreach ($g as $v) {
            $s .= $v === 0 ? '.' : $this->alphabet[$v - 1];
        }
        return $s;
    }

    public function stringToGrid(string $s): array
    {
        $g = [];
        for ($i = 0; $i < $this->cells; $i++) {
            $ch = $s[$i] ?? '.';
            $p  = strpos($this->alphabet, $ch);
            $g[] = ($ch === '.' || $p === false) ? 0 : $p + 1;
        }
        return $g;
    }

    // ----------------------------------------------------------- verification

    /**
     * Hard assertions run on every emitted puzzle. Returns a list of failures;
     * an empty list means the puzzle is provably a legitimate, uniquely-solvable,
     * inference-only sudoku.
     */
    public function verify(Puzzle $p): array
    {
        $errors = [];

        if ($p->variantKey !== $this->variant->key) {
            return ["puzzle is a {$p->variantKey} grid but this engine is {$this->variant->key}"];
        }

        $puzzle   = $p->puzzle;
        $solution = $p->solution;

        if (count($puzzle) !== $this->cells) {
            $errors[] = 'puzzle grid is the wrong size';
            return $errors;
        }
        if (count($solution) !== $this->cells) {
            $errors[] = 'solution grid is the wrong size';
            return $errors;
        }

        foreach ($solution as $v) {
            if ($v < 1 || $v > $this->n) {
                $errors[] = 'solution has an empty or out-of-range cell';
                break;
            }
        }
        if (!$errors) {
            foreach ($this->units as $id => $u) {
                $seen = 0;
                foreach ($u as $cell) {
                    $bit = 1 << ($solution[$cell] - 1);
                    if ($seen & $bit) {
                        $errors[] = "solution has a duplicate symbol in unit $id";
                        break 2;
                    }
                    $seen |= $bit;
                }
            }
        }

        for ($i = 0; $i < $this->cells; $i++) {
            if ($puzzle[$i] !== 0 && $puzzle[$i] !== $solution[$i]) {
                $errors[] = "given at cell $i disagrees with the solution";
                break;
            }
        }

        $count = $this->countSolutions($puzzle, 2);
        if ($count !== 1) {
            $errors[] = "brute force found $count solution(s), expected exactly 1";
        }

        $res = $this->solveLogical($puzzle, 7);
        if (!$res['solved']) {
            $errors[] = 'logical solver stalled at ' . $res['unsolvedCells'] . ' unsolved cells (would need guessing)';
        } elseif ($res['grid'] !== $solution) {
            $errors[] = 'logical solver reached a different grid than the recorded solution';
        }

        return $errors;
    }

    public static function ladder(): array
    {
        return self::LADDER;
    }

    /** key => ['label' => human name, 'tier' => difficulty tier]. */
    public static function techniqueLabels(): array
    {
        $out = [];
        foreach (self::LADDER as [$key, $tier, $cost, $label]) {
            $out[$key] = ['label' => $label, 'tier' => $tier, 'cost' => $cost];
        }
        return $out;
    }

    /**
     * Full inference walkthrough for a puzzle: an ordered list of steps, each
     * either a placement (cell + symbol + rule) or a candidate elimination that
     * unlocked later placements.
     */
    public function explain(Puzzle $p, int $tierCap = 7): array
    {
        return $this->solveLogical($p->puzzle, $tierCap, true);
    }
}
