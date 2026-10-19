<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

/**
 * The book's fixed page/row/column shape and the arithmetic that turns a
 * (page, row, col) into the single linear reading-order position used
 * everywhere else. Deliberately knows nothing about adjacency, corners, or
 * any other geometric relationship a rule might care about -- that
 * knowledge belongs to Adjacency implementations, not here. v1's Grid
 * accreted one method per rule that needed a geometry (knightNeighbours(),
 * surroundingNeighbours(), allCorners()); this class exists specifically so
 * that never happens again.
 */
final class BoardSpec
{
    public function __construct(
        public readonly int $pages,
        public readonly int $rows,
        public readonly int $cols,
    ) {
        if ($pages < 1 || $rows < 1 || $cols < 1) {
            throw new \InvalidArgumentException('pages, rows, and cols must all be at least 1.');
        }
    }

    public function size(): int
    {
        return $this->pages * $this->rows * $this->cols;
    }

    public function linearIndex(int $page, int $row, int $col): int
    {
        return ($page * $this->rows + $row) * $this->cols + $col;
    }

    /** @return array{0:int,1:int,2:int} [page, row, col], all 0-based. */
    public function coordsOf(int $cell): array
    {
        $col = $cell % $this->cols;
        $rowIndex = intdiv($cell, $this->cols);
        $row = $rowIndex % $this->rows;
        $page = intdiv($rowIndex, $this->rows);
        return [$page, $row, $col];
    }

    public function allCells(): array
    {
        return range(0, $this->size() - 1);
    }
}
