<?php

declare(strict_types=1);

namespace Puzzlebook\Reveals;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/** Reads the first letter of the first surviving word on each of $pages, in page order. */
final class AcrosticFirstCellPerPageReveal implements RevealInterface
{
    /** @param int[] $pages */
    public function __construct(private array $pages)
    {
    }

    public function reveal(array $words, Grid $grid, array $keep): array
    {
        $cells = [];
        foreach ($this->pages as $page) {
            $found = null;
            for ($row = 0; $row < $grid->rows && $found === null; $row++) {
                for ($col = 0; $col < $grid->cols; $col++) {
                    $i = $grid->linearIndex($page, $row, $col);
                    if ($keep[$i]) {
                        $found = $i;
                        break;
                    }
                }
            }
            if ($found === null) {
                throw new \RuntimeException("No surviving word found on page {$page} for an acrostic reveal.");
            }
            $cells[] = $found;
        }
        $letters = implode('', array_map(static fn (int $i): string => WordTags::firstLetter($words[$i]), $cells));
        return ['text' => $letters, 'cells' => $cells];
    }

    public function describe(): string
    {
        $pages = implode(', ', array_map(static fn (int $p): int => $p + 1, $this->pages));
        return "the first surviving word on each of pages {$pages}, read as initials in page order";
    }
}
