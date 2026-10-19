<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

/**
 * A named set of cells a rule's pattern applies to -- a single cell for a
 * spelling rule, two for a pair ('top'/'bottom' or 'left'/'right'), nine
 * for surrounded-capture (a center plus its eight neighbours, roles like
 * 'center', 'n0'..'n7'). The roles exist so a rule's own predicate and
 * constraintsFor() can refer to "the top one" without caring what linear
 * cell index that happens to be.
 */
final class Scope
{
    /** @param array<string,int> $cellsByRole */
    public function __construct(public readonly array $cellsByRole)
    {
    }

    /** @return int[] */
    public function cells(): array
    {
        return array_values($this->cellsByRole);
    }

    public function cell(string $role): int
    {
        if (!isset($this->cellsByRole[$role])) {
            throw new \InvalidArgumentException("Scope has no role '{$role}'.");
        }
        return $this->cellsByRole[$role];
    }
}
