<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * The book's fixed page/row/column shape, and the arithmetic that turns a
 * (page, row, col) into the single linear reading-order position used
 * everywhere else -- plus the one piece of geometry the knight's-move rule
 * needs: which cells are a chess knight's move away, staying on the same
 * page.
 */
final class Grid
{
    public int $pages;
    public int $rows;
    public int $cols;

    public function __construct(int $pages, int $rows, int $cols)
    {
        $this->pages = $pages;
        $this->rows = $rows;
        $this->cols = $cols;
    }

    public function size(): int
    {
        return $this->pages * $this->rows * $this->cols;
    }

    /** Linear index (0-based) in book reading order: page, then row, then column. */
    public function linearIndex(int $page, int $row, int $col): int
    {
        return ($page * $this->rows + $row) * $this->cols + $col;
    }

    /** @return array{0:int,1:int,2:int} [page, row, col], all 0-based. */
    public function coordsOf(int $linearIndex): array
    {
        $col = $linearIndex % $this->cols;
        $rowIndex = intdiv($linearIndex, $this->cols);
        $row = $rowIndex % $this->rows;
        $page = intdiv($rowIndex, $this->rows);
        return [$page, $row, $col];
    }

    /** @return int[] linear indices of the in-bounds knight-move neighbours on the same page. */
    public function knightNeighbours(int $linearIndex): array
    {
        [$page, $row, $col] = $this->coordsOf($linearIndex);
        $offsets = [
            [-2, -1], [-2, 1], [-1, -2], [-1, 2],
            [1, -2], [1, 2], [2, -1], [2, 1],
        ];
        $neighbours = [];
        foreach ($offsets as $offset) {
            $r = $row + $offset[0];
            $c = $col + $offset[1];
            if ($r < 0 || $r >= $this->rows || $c < 0 || $c >= $this->cols) {
                continue;
            }
            $neighbours[] = $this->linearIndex($page, $r, $c);
        }
        return $neighbours;
    }
}
