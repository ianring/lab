<?php

declare(strict_types=1);

namespace Puzzlebook2\Series;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\Engine;
use Puzzlebook2\Dictionary\Dictionary;
use Puzzlebook2\Rules\SpellingRuleBase;
use Puzzlebook2\Solver\Rng;

/**
 * The cross-book coordinator: decides, for the whole series at once and before
 * any grid is solved, where every answer and Mystery sits so the locked rule
 * resolves on the real grids.
 *
 * THE LOCKED MYSTERY RULE (Ian's, 2026-10-23):
 *
 *   Book 1 (the root): its Mystery is read IN BOOK 1 at
 *       page   = checksum of its answer (A=1..Z=26, summed)
 *       row    = the answer's letter count
 *       column = the answer's vowel count
 *
 *   Books 2..21: the Mystery is read IN THIS BOOK at the cell got by ADDING,
 *   coordinate by coordinate (wrapping), the position of THIS book's answer and
 *   the position of the PREVIOUS book's Mystery (both found in this book):
 *       page   = (answer.page   + prevMystery.page)   wrapped to 1..100
 *       row    = (answer.row    + prevMystery.row)    wrapped to 1..25
 *       column = (answer.column + prevMystery.column) wrapped to 1..5
 *
 * Why the blend: adding an unknown-but-uniform position acts as a one-time pad,
 * so one ingredient alone leaves the cell uniform over the whole book (1 in
 * 12,500) -- you need BOTH the answer (solved this book) and the previous
 * Mystery (walked the chain). Holding Book N's Mystery proves books 1..N were
 * solved; the Capstone's proves all 21.
 *
 * Placement: answers and planted words go at uniformly random cells, skipping
 * the four corners (so the corner rule never touches them) and anything already
 * placed. The answer floats to its own cell; its position, not its letters,
 * feeds the blend.
 */
final class SeriesPlanner
{
    /** @param array<int,string> $answers 1-indexed: one distinct answer per book */
    public function __construct(
        private array $answers,
        private Dictionary $dictionary,
        private BoardSpec $spec,
        private int $totalBooks,
        private int $seed = 777,
        /** @var array<int,array{type:string,needle:string,offsets:array,steps:array}> bookNo => reaction */
        private array $reactions = [],
    ) {
    }

    public function plan(): array
    {
        $spec = $this->spec;
        $P = $spec->pages;
        $R = $spec->rows;
        $C = $spec->cols;

        $mysteries = $this->pickMysteryWords();

        // ---- fixed acrostic + box geometry, reserved before random placement ----
        $addressee = 'TO THE KEEPER OF THE ELIMINOID';
        $acrosticPage = min($P - 1, 11);
        $startCol = AcrosticPlanner::cornerFreeStartCol();
        $placePage = max(0, min($P - 1, (int) round($P * 0.19)));
        $boxPage = max(0, min($P - 1, (int) round($P * 0.41)));
        $placeDefs = [
            ['component' => 'town', 'book' => 5, 'string' => 'WATERLOO'],
            ['component' => 'region', 'book' => 10, 'string' => 'ONTARIO'],
            ['component' => 'country', 'book' => 15, 'string' => 'CANADA'],
        ];

        $books = [];
        $occupied = [];
        for ($b = 1; $b <= $this->totalBooks; $b++) {
            $books[$b] = ['answer_cell' => null, 'plants' => []];
            $occupied[$b] = [];
        }
        $place = function (int $b, array $plant) use (&$books, &$occupied): void {
            $books[$b]['plants'][] = $plant;
            $occupied[$b][$plant['cell']] = true;
        };

        // addressee acrostic (Book 1)
        foreach (AcrosticPlanner::wrappingDiagonal($spec, $acrosticPage, $startCol, $addressee, 'addr') as $pl) {
            $place(1, $pl);
        }
        // town / region / country acrostics
        $places = [];
        foreach ($placeDefs as $p) {
            if ($p['book'] > $this->totalBooks) {
                continue;
            }
            foreach (AcrosticPlanner::wrappingDiagonal($spec, $placePage, $startCol, $p['string'], 'place-' . $p['component']) as $pl) {
                $place($p['book'], $pl);
            }
            $places[$p['component']] = [
                'book' => $p['book'],
                'page' => $placePage,
                'string' => strtoupper((string) preg_replace('/[^A-Za-z]/', '', $p['string'])),
            ];
        }
        // PO box: the Capstone's Mystery planted in Book 1 on the page == box number
        $capstoneMystery = $mysteries[$this->totalBooks];
        $place(1, [
            'cell' => $spec->linearIndex($boxPage, intdiv($R, 2), intdiv($C, 2)),
            'word' => $capstoneMystery,
            'id' => 'box-find',
            'role' => 'box_find',
        ]);

        // ---- per book: place the answer and the Mystery plant(s) ----
        for ($b = 1; $b <= $this->totalBooks; $b++) {
            $cells = Rng::shuffle($spec->allCells(), $this->seed + $b * 101);
            $cursor = 0;

            if ($b === 1) {
                // Book 1 root: the checksum cell is fixed by the answer.
                $A = $this->answers[1];
                $mp = $this->checksum($A) - 1;
                $mr = $this->letterCount($A) - 1;
                $mc = $this->vowelCount($A) - 1;
                if ($mp < 0 || $mp >= $P || $mr < 0 || $mr >= $R || $mc < 0 || $mc >= $C) {
                    throw new \RuntimeException(sprintf(
                        "Book 1 answer '%s' -> cell page %d/row %d/col %d is out of a %dx%dx%d grid "
                        . '(need letter-sum<=pages, length<=rows, vowels 1..cols).',
                        $A, $mp + 1, $mr + 1, $mc + 1, $P, $R, $C,
                    ));
                }
                $mysteryCell = $spec->linearIndex($mp, $mr, $mc);
                if (isset($occupied[1][$mysteryCell])) {
                    throw new \RuntimeException("Book 1's checksum cell collides with an acrostic/box cell; adjust pages.");
                }
                $place(1, ['cell' => $mysteryCell, 'word' => $mysteries[1], 'id' => 'mystery-1', 'role' => 'mystery']);
            } else {
                // Books 2+: draw a pad cell for M[N-1]; the Mystery cell is the blend.
                $aCell = $this->draw($cells, $cursor, $occupied[$b]);
                $occupied[$b][$aCell] = true;
                $padCell = null;
                $mysteryCell = null;
                for (; $cursor < count($cells); $cursor++) {
                    $cand = $cells[$cursor];
                    if ($this->isCorner($cand) || isset($occupied[$b][$cand])) {
                        continue;
                    }
                    $blend = $this->blend($aCell, $cand);
                    if ($this->isCorner($blend) || isset($occupied[$b][$blend]) || $blend === $aCell || $blend === $cand) {
                        continue;
                    }
                    $padCell = $cand;
                    $mysteryCell = $blend;
                    $cursor++;
                    break;
                }
                if ($padCell === null) {
                    throw new \RuntimeException("Book {$b}: could not place the Mystery plants (grid too crowded).");
                }
                $place($b, ['cell' => $padCell, 'word' => $mysteries[$b - 1], 'id' => "pad-{$b}", 'role' => 'mystery_pad']);
                $place($b, ['cell' => $mysteryCell, 'word' => $mysteries[$b], 'id' => "mystery-{$b}", 'role' => 'mystery']);
                $books[$b]['answer_cell'] = $aCell;
                continue;
            }

            // Book 1 answer cell: any non-corner cell clear of everything placed.
            $books[1]['answer_cell'] = $this->draw($cells, $cursor, $occupied[1]);
            $occupied[1][$books[1]['answer_cell']] = true;
        }

        // ---- triggered-rule placements: each reaction book gets its trigger
        // words planted and their blast footprints reserved. One per book.
        $bombs = [];
        foreach ($this->reactions as $bookNo => $reaction) {
            if ($bookNo < 1 || $bookNo > $this->totalBooks) {
                continue;
            }
            foreach ($this->placeReaction($bookNo, $reaction, $books, $occupied, $mysteries) as $placed) {
                $bombs[] = $placed;
            }
        }

        return [
            'answers' => $this->answers,
            'mysteries' => $mysteries,
            'reaction_books' => array_keys($this->reactions),
            'bombs' => $bombs,
            'addressee' => $addressee,
            'acrostic_page' => $acrosticPage,
            'acrostic_start_col' => $startCol,
            'places' => $places,
            'box_number' => $boxPage + 1,
            'box_find' => ['book' => 1, 'page' => $boxPage, 'word' => $capstoneMystery],
            'books' => $books,
        ];
    }

    /** First non-corner cell in $cells from $cursor not already in $occupied; advances $cursor past it. */
    private function draw(array $cells, int &$cursor, array $occupied): int
    {
        for (; $cursor < count($cells); $cursor++) {
            $cell = $cells[$cursor];
            if (!$this->isCorner($cell) && !isset($occupied[$cell])) {
                $cursor++;
                return $cell;
            }
        }
        throw new \RuntimeException('Ran out of free non-corner cells while placing.');
    }

    private function isCorner(int $cell): bool
    {
        [, $r, $c] = $this->spec->coordsOf($cell);
        return ($r === 0 || $r === $this->spec->rows - 1) && ($c === 0 || $c === $this->spec->cols - 1);
    }

    /** Add two cells' positions coordinate-by-coordinate, wrapping within each range. */
    private function blend(int $a, int $b): int
    {
        $spec = $this->spec;
        [$ap, $ar, $ac] = $spec->coordsOf($a);
        [$bp, $br, $bc] = $spec->coordsOf($b);
        return $spec->linearIndex(
            ($ap + $bp) % $spec->pages,
            ($ar + $br) % $spec->rows,
            ($ac + $bc) % $spec->cols,
        );
    }

    /**
     * Plant one reaction book's triggers and reserve each blast footprint. The
     * trigger word (containing the reaction's needle) is drawn to die EARLY in
     * the book's chain, so the blast fires while its footprint still stands; the
     * footprint is filled with words drawn to die LATER (so they outlive the
     * trigger and the blast actually clears them). The footprint shape is the
     * rule's own offsets, clipped to the page. Best-effort: if it cannot place a
     * bomb, the reaction simply stays an inert standing rule -- no build failure.
     *
     * @param array{type:string,needle:string,offsets:array,steps:array} $reaction
     * @return array<int,array{center:int,trigger:string,victims:int[],needle:string}>
     */
    private function placeReaction(int $b, array $reaction, array &$books, array &$occupied, array $mysteries): array
    {
        $spec = $this->spec;
        $needle = (string) $reaction['needle'];
        $offsets = $reaction['offsets'];
        $wantBombs = 1;

        $chainRules = $this->reactionSpellingRules($reaction['steps'] ?? []);
        $triggers = $this->pickTriggers($needle, $chainRules, $wantBombs);
        if ($triggers === []) {
            return []; // no eliminable trigger word -> reaction stays inert
        }

        $used = array_fill_keys(array_map('strtolower', $this->answers), true);
        foreach ($mysteries as $m) {
            $used[strtolower((string) $m)] = true;
        }
        foreach ($triggers as $t) {
            $used[strtolower($t)] = true;
        }

        $triggerDeath = 0;
        foreach ($triggers as $t) {
            $triggerDeath = max($triggerDeath, $this->deathStep($t, $chainRules));
        }
        $victimsPool = $chainRules === []
            ? $this->neighbourPool($used)
            : $this->lateVictimPool($used, $triggerDeath, $chainRules, $needle);
        $vp = 0;

        $cells = Rng::shuffle($spec->allCells(), $this->seed + 9000 + $b);
        $bombs = [];
        foreach ($cells as $center) {
            if (count($bombs) >= $wantBombs || isset($occupied[$b][$center])) {
                continue;
            }
            [$p, $r, $c] = $spec->coordsOf($center);
            // The footprint cells on this page (offsets clipped to the edges).
            $foot = [];
            foreach ($offsets as [$dr, $dc]) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                if ($rr < 0 || $rr >= $spec->rows || $cc < 0 || $cc >= $spec->cols) {
                    continue;
                }
                $cell = $spec->linearIndex($p, $rr, $cc);
                if ($cell !== $center) {
                    $foot[$cell] = true;
                }
            }
            $foot = array_keys($foot);
            if ($foot === [] || $vp + count($foot) > count($victimsPool)) {
                continue;
            }
            $clash = false;
            foreach ($foot as $cell) {
                if (isset($occupied[$b][$cell])) {
                    $clash = true;
                    break;
                }
            }
            if ($clash) {
                continue;
            }
            $k = count($bombs);
            $trigger = $triggers[$k] ?? $triggers[0];
            $books[$b]['plants'][] = ['cell' => $center, 'word' => $trigger, 'id' => "react-{$needle}-trigger-{$b}-{$k}", 'role' => 'react_trigger'];
            $occupied[$b][$center] = true;
            $victims = [];
            foreach ($foot as $i => $cell) {
                $books[$b]['plants'][] = ['cell' => $cell, 'word' => $victimsPool[$vp++], 'id' => "react-{$needle}-victim-{$b}-{$k}-{$i}", 'role' => 'react_victim'];
                $occupied[$b][$cell] = true;
                $victims[] = $cell;
            }
            $bombs[] = ['center' => $center, 'trigger' => $trigger, 'victims' => $victims, 'needle' => $needle];
        }
        return $bombs;
    }

    /**
     * Up to $k trigger words containing $needle, 4..6 letters, that the book's
     * chain actually crosses out (so the blast fires), ordered earliest-dying
     * first so the trigger dies before its later-dying victims. @return string[]
     */
    private function pickTriggers(string $needle, array $chainRules, int $k): array
    {
        $cands = [];
        foreach ($this->dictionary->all() as $w) {
            $len = strlen($w);
            // 4..9 letters: long enough to read, short enough to sit in the grid.
            // Eliminability is enforced below (deathStep finite), so length is only
            // a preference -- rarer needles like DIAG need the wider range to find
            // any trigger at all.
            if ($len >= 4 && $len <= 9 && str_contains($w, $needle)) {
                $cands[] = $w;
            }
        }
        $cands = Rng::shuffle($cands, $this->seed + 4242);
        if ($chainRules !== []) {
            $withDeath = [];
            foreach ($cands as $w) {
                $d = $this->deathStep($w, $chainRules);
                if ($d !== PHP_INT_MAX) {
                    $withDeath[] = [$w, $d];
                }
            }
            usort($withDeath, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            $cands = array_map(static fn (array $x): string => $x[0], $withDeath);
        }
        return array_slice($cands, 0, $k);
    }

    /**
     * A book's spelling rules, in step order (the final sweep included), so the
     * planter can tell WHEN each word would be crossed out. Empty when no chain
     * was supplied (the single-book test path).
     *
     * @param array $steps the composed chain steps
     * @return array<int, SpellingRuleBase> step index => rule
     */
    private function reactionSpellingRules(array $steps): array
    {
        $out = [];
        foreach ($steps as $idx => $step) {
            if (!isset($step['rule']['type'])) {
                continue;
            }
            try {
                $rule = Engine::ruleFromConfig($step['rule']);
            } catch (\Throwable) {
                continue;
            }
            if ($rule instanceof SpellingRuleBase) {
                $out[$idx] = $rule;
            }
        }
        return $out;
    }

    /** The step at which the chain first crosses $word out (PHP_INT_MAX if never). */
    private function deathStep(string $word, array $chainRules): int
    {
        foreach ($chainRules as $idx => $rule) {
            if ($rule->failsWord($word)) {
                return $idx;
            }
        }
        return PHP_INT_MAX;
    }

    /**
     * Victim words that die LATER than $afterStep in the reaction chain -- still
     * standing when the trigger goes off, so the blast actually clears them --
     * 4..6 letters, unused. Scans a shuffled dictionary so victims vary book to
     * book, stopping once enough are found.
     *
     * @param array<string,bool> $used
     * @return string[]
     */
    private function lateVictimPool(array $used, int $afterStep, array $chainRules, string $needle): array
    {
        $pool = [];
        foreach (Rng::shuffle($this->dictionary->all(), $this->seed + 1717) as $w) {
            $len = strlen($w);
            // Exclude the needle so a victim is never itself an (unplanned) trigger.
            if ($len < 4 || $len > 6 || isset($used[$w]) || str_contains($w, $needle)) {
                continue;
            }
            $death = $this->deathStep($w, $chainRules);
            if ($death > $afterStep && $death !== PHP_INT_MAX) {
                $pool[] = $w;
                if (count($pool) >= 128) {
                    break;
                }
            }
        }
        return $pool;
    }

    /**
     * Eliminable neighbour words for the blocks: an adjacent vowel pair (so the
     * universal VowelRun absorber crosses them), 3..8 letters, not already used.
     *
     * @param array<string,bool> $used
     * @return string[]
     */
    private function neighbourPool(array $used): array
    {
        $pool = [];
        foreach ($this->dictionary->all() as $w) {
            $len = strlen($w);
            // Same eliminability guarantee as Mystery words: 4..6 letters with an
            // adjacent a/i/o/u pair, so the VowelRun absorber always crosses them.
            if ($len < 4 || $len > 6 || isset($used[$w]) || !preg_match('/[aiou]{2}/', $w)) {
                continue;
            }
            $pool[] = $w;
        }
        return Rng::shuffle($pool, $this->seed + 1717);
    }

    /**
     * 21 distinct Mystery words, each eliminable in any book (adjacent vowel
     * pair + <=6 letters, so the universal VowelRunRule always crosses it out),
     * with a usable vowel count, 'e'-free to keep them plain, and not equal to
     * any answer. Deterministic per seed.
     *
     * @return array<int,string> 1-indexed
     */
    private function pickMysteryWords(): array
    {
        $cols = $this->spec->cols;
        $rows = $this->spec->rows;
        $answerSet = array_fill_keys(array_map('strtolower', $this->answers), true);
        // No Mystery word may carry any reaction needle, or (planted and then
        // crossed in that book) it would be an unplanned trigger setting off a blast.
        $needles = array_values(array_unique(array_map(static fn (array $r): string => $r['needle'], $this->reactions)));
        $candidates = [];
        foreach ($this->dictionary->all() as $w) {
            $len = strlen($w);
            if ($len < 4 || $len > 6 || $len > $rows || isset($answerSet[$w]) || strpos($w, 'e') !== false) {
                continue;
            }
            $hasNeedle = false;
            foreach ($needles as $needle) {
                if (str_contains($w, $needle)) {
                    $hasNeedle = true;
                    break;
                }
            }
            if ($hasNeedle) {
                continue;
            }
            if (!preg_match('/[aiou]{2}/', $w)) {
                continue;
            }
            $vow = preg_match_all('/[aeiou]/', $w);
            if ($vow < 1 || $vow > $cols) {
                continue;
            }
            $candidates[] = $w;
        }
        $candidates = Rng::shuffle($candidates, $this->seed);
        if (count($candidates) < $this->totalBooks) {
            throw new \RuntimeException(sprintf(
                'Only %d eliminable Mystery candidates, need %d.',
                count($candidates),
                $this->totalBooks,
            ));
        }
        $picked = [];
        for ($n = 1; $n <= $this->totalBooks; $n++) {
            $picked[$n] = $candidates[$n - 1];
        }
        return $picked;
    }

    private function checksum(string $word): int
    {
        $sum = 0;
        foreach (str_split(strtolower($word)) as $ch) {
            if ($ch >= 'a' && $ch <= 'z') {
                $sum += ord($ch) - 96;
            }
        }
        return $sum;
    }

    private function letterCount(string $word): int
    {
        return strlen((string) preg_replace('/[^a-z]/', '', strtolower($word)));
    }

    private function vowelCount(string $word): int
    {
        return (int) preg_match_all('/[aeiou]/', strtolower($word));
    }
}
