<?php

declare(strict_types=1);

namespace Puzzlebook\Reveals;

use Puzzlebook\Grid;

/** Reads the last surviving word on $page, searching from the bottom-right cell backwards. */
final class LastSurvivorOnPageReveal implements RevealInterface
{
    public function __construct(private int $page)
    {
    }

    public function reveal(array $words, Grid $grid, array $keep): array
    {
        for ($row = $grid->rows - 1; $row >= 0; $row--) {
            for ($col = $grid->cols - 1; $col >= 0; $col--) {
                $i = $grid->linearIndex($this->page, $row, $col);
                if ($keep[$i]) {
                    return ['text' => $words[$i], 'cells' => [$i]];
                }
            }
        }
        throw new \RuntimeException("No surviving word found on page {$this->page}.");
    }

    public function describe(): string
    {
        return 'the last surviving word on page ' . ($this->page + 1);
    }
}
