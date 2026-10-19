<?php

declare(strict_types=1);

namespace Puzzlebook2\Board;

/**
 * A pluggable "what counts as near this cell" relationship. A new geometric
 * rule is a new Adjacency (or a CallbackAdjacency closure) -- never a change
 * to BoardSpec itself. BoardSpec only ever answers "where is this cell";
 * what's "near" it is entirely this interface's job.
 */
interface Adjacency
{
    /** @return int[] in-bounds neighbour cell indices. */
    public function neighboursOf(int $cell, BoardSpec $spec): array;

    public function describe(): string;
}
