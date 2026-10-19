<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

final class KingAdjacency implements Adjacency
{
    public function neighboursOf(int $cell, BoardSpec $spec): array
    {
        [$page, $row, $col] = $spec->coordsOf($cell);
        $neighbours = [];
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) {
                    continue;
                }
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 0 || $r >= $spec->rows || $c < 0 || $c >= $spec->cols) {
                    continue;
                }
                $neighbours[] = $spec->linearIndex($page, $r, $c);
            }
        }
        return $neighbours;
    }

    public function describe(): string
    {
        return '8 surrounding cells, king-move (same page)';
    }
}
