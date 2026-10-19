<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

final class KnightAdjacency implements Adjacency
{
    private const OFFSETS = [
        [-2, -1], [-2, 1], [-1, -2], [-1, 2],
        [1, -2], [1, 2], [2, -1], [2, 1],
    ];

    public function neighboursOf(int $cell, BoardSpec $spec): array
    {
        [$page, $row, $col] = $spec->coordsOf($cell);
        $neighbours = [];
        foreach (self::OFFSETS as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            if ($r < 0 || $r >= $spec->rows || $c < 0 || $c >= $spec->cols) {
                continue;
            }
            $neighbours[] = $spec->linearIndex($page, $r, $c);
        }
        return $neighbours;
    }

    public function describe(): string
    {
        return "chess knight's move (same page)";
    }
}
