<?php

declare(strict_types=1);

namespace Puzzlebook2\Chain;

/**
 * An immutable snapshot of which cells are still standing when a step
 * begins. Every rule reads the SNAPSHOT, never live state -- the book's
 * one reading convention (a pair's two halves must count each other as
 * surviving simultaneously; a captured cell can't un-surround its own
 * neighbour mid-pass), ported from v1 where each apply() copied the mask.
 */
final class KeepMask
{
    /** @param bool[] $keep */
    public function __construct(private array $keep)
    {
    }

    public static function allKept(int $size): self
    {
        return new self(array_fill(0, $size, true));
    }

    public function isKept(int $cell): bool
    {
        return $this->keep[$cell] ?? false;
    }

    /** @return bool[] */
    public function toArray(): array
    {
        return $this->keep;
    }

    public function countKept(): int
    {
        return count(array_filter($this->keep));
    }

    /** A new mask with $cells crossed out -- the step's output, built from this snapshot. */
    public function without(array $cells): self
    {
        $next = $this->keep;
        foreach ($cells as $c) {
            $next[$c] = false;
        }
        return new self($next);
    }
}
