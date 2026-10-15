<?php

declare(strict_types=1);

namespace Puzzlebook\Reveals;

use Puzzlebook\Grid;

/** One reveal mechanism, fully self-contained in its own class file. */
interface RevealInterface
{
    /**
     * @param string[] $words
     * @param bool[] $keep
     * @return array{text:string, cells:int[]} $cells is in the order its
     *     entries contribute to $text (so $cells[0] produced $text[0], and
     *     so on) -- useful for a caller that wants to highlight exactly
     *     which words or letters were read, and in what order.
     */
    public function reveal(array $words, Grid $grid, array $keep): array;

    /**
     * A plain-English description of what this reveal reads, with its real
     * parameters filled in -- so a build or solve report can show the
     * pattern being followed, not just the text it produced.
     */
    public function describe(): string;
}
