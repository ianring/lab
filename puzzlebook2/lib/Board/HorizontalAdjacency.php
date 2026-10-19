<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

final class HorizontalAdjacency implements Adjacency
{
    public function neighboursOf(int $cell, BoardSpec $spec): array
    {
        [, , $col] = $spec->coordsOf($cell);
        $neighbours = [];
        if ($col > 0) {
            $neighbours[] = $cell - 1;
        }
        if ($col < $spec->cols - 1) {
            $neighbours[] = $cell + 1;
        }
        return $neighbours;
    }

    public function describe(): string
    {
        return 'horizontally adjacent (same page, same row, col ± 1)';
    }
}
