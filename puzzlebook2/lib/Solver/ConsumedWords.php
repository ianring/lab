<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * The single, shared "which real words are already used somewhere on the
 * board" set -- shared by reference (object identity) across every
 * BasePool, since a word consumed by one pool must be invisible to every
 * other pool too. Consuming and releasing are both O(1); releasing is what
 * makes backtracking's undo correct (a word freed by backtracking really
 * does become available again, immediately, to whatever pool wants it).
 */
final class ConsumedWords
{
    /** @var array<string,true> */
    private array $used = [];

    public function isConsumed(string $word): bool
    {
        return isset($this->used[$word]);
    }

    public function consume(string $word): void
    {
        $this->used[$word] = true;
    }

    public function release(string $word): void
    {
        unset($this->used[$word]);
    }
}
