<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

/**
 * Edge vs interior cells of each page. A cell is INTERIOR when all 8
 * king-move neighbours exist on its page -- the only cells a
 * surrounded-capture can ever fire on. Not a BoardSpec method, same
 * reasoning as PageCorners.
 */
final class PageEdges
{
    /** @return int[] cells with a full ring of 8 same-page neighbours. */
    public static function interior(BoardSpec $spec): array
    {
        $cells = [];
        for ($page = 0; $page < $spec->pages; $page++) {
            for ($row = 1; $row < $spec->rows - 1; $row++) {
                for ($col = 1; $col < $spec->cols - 1; $col++) {
                    $cells[] = $spec->linearIndex($page, $row, $col);
                }
            }
        }
        return $cells;
    }
}
