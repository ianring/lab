<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

/** A committed scope: which cells, which words, which rule, at which decision level. */
final class Reservation
{
    /** @param array<string,string> $words role => word */
    public function __construct(
        public readonly Scope $scope,
        public readonly array $words,
        public readonly Rule $rule,
        public readonly int $decisionLevel,
    ) {
    }

    public function wordAt(int $cell): string
    {
        foreach ($this->scope->cellsByRole as $role => $c) {
            if ($c === $cell) {
                return $this->words[$role];
            }
        }
        throw new \InvalidArgumentException("Cell {$cell} is not part of this reservation's scope.");
    }
}
