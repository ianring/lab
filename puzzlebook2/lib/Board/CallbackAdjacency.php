<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

/** For a geometry nobody's invented yet -- wrap an inline closure without the engine needing a named class for every possible pattern. */
final class CallbackAdjacency implements Adjacency
{
    /** @param \Closure(int, BoardSpec): array $fn */
    public function __construct(private \Closure $fn, private string $label)
    {
    }

    public function neighboursOf(int $cell, BoardSpec $spec): array
    {
        return ($this->fn)($cell, $spec);
    }

    public function describe(): string
    {
        return $this->label;
    }
}
