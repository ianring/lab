<?php

declare(strict_types=1);

namespace Puzzlebook\Reveals;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/** Reads the last letters of the surviving words down column $col of $page, across $rows, optionally reversed. */
final class ColumnLastLettersReveal implements RevealInterface
{
    /** @param int[] $rows */
    public function __construct(private int $page, private int $col, private array $rows, private bool $reverse = false)
    {
    }

    public function reveal(array $words, Grid $grid, array $keep): array
    {
        $orderedRows = $this->reverse ? array_reverse($this->rows) : $this->rows;
        $cells = [];
        foreach ($orderedRows as $row) {
            $i = $grid->linearIndex($this->page, $row, $this->col);
            if (!$keep[$i]) {
                throw new \RuntimeException(
                    "Expected a surviving word at page {$this->page} row {$row} col {$this->col} for a column reveal."
                );
            }
            $cells[] = $i;
        }
        $letters = implode('', array_map(static fn (int $i): string => WordTags::lastLetter($words[$i]), $cells));
        return ['text' => $letters, 'cells' => $cells];
    }

    public function describe(): string
    {
        $rows = implode(', ', array_map(static fn (int $r): int => $r + 1, $this->rows));
        return 'the last letters of the surviving words down column ' . ($this->col + 1)
            . ' of page ' . ($this->page + 1)
            . " (rows {$rows})"
            . ($this->reverse ? ', read backwards' : ', read top to bottom');
    }
}
