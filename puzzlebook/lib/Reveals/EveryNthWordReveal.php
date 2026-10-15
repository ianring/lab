<?php

declare(strict_types=1);

namespace Puzzlebook\Reveals;

use Puzzlebook\Grid;

/** Reads every $n-th surviving word, counting from the start of the book, up to $count of them. */
final class EveryNthWordReveal implements RevealInterface
{
    public function __construct(private int $n, private int $count)
    {
    }

    public function reveal(array $words, Grid $grid, array $keep): array
    {
        $cells = [];
        $rank = 0;
        foreach ($words as $i => $word) {
            if (!$keep[$i]) {
                continue;
            }
            $rank++;
            if ($rank % $this->n === 0) {
                $cells[] = $i;
                if (count($cells) >= $this->count) {
                    break;
                }
            }
        }
        return ['text' => implode(' ', array_map(static fn (int $i): string => $words[$i], $cells)), 'cells' => $cells];
    }

    public function describe(): string
    {
        return "every {$this->n}th surviving word, counting from the start of the book"
            . ($this->count > 1 ? " (first {$this->count} such words)" : ' (the first one found)');
    }
}
