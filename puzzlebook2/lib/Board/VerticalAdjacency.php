<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

final class VerticalAdjacency implements Adjacency
{
    public function neighboursOf(int $cell, BoardSpec $spec): array
    {
        [, $row] = $spec->coordsOf($cell);
        $neighbours = [];
        if ($row > 0) {
            $neighbours[] = $cell - $spec->cols;
        }
        if ($row < $spec->rows - 1) {
            $neighbours[] = $cell + $spec->cols;
        }
        return $neighbours;
    }

    public function describe(): string
    {
        return 'vertically adjacent (same page, same column, row ± 1)';
    }
}
