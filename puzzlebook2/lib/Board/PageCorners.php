<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

/**
 * The four corner cells of every page. Deliberately NOT a BoardSpec method
 * (v1's Grid accreted one such method per rule's geometry); any rule that
 * cares about corners asks here.
 */
final class PageCorners
{
    /** @return int[] */
    public static function all(BoardSpec $spec): array
    {
        $corners = [];
        for ($page = 0; $page < $spec->pages; $page++) {
            $corners[] = $spec->linearIndex($page, 0, 0);
            $corners[] = $spec->linearIndex($page, 0, $spec->cols - 1);
            $corners[] = $spec->linearIndex($page, $spec->rows - 1, 0);
            $corners[] = $spec->linearIndex($page, $spec->rows - 1, $spec->cols - 1);
        }
        return $corners;
    }
}
