<?php

declare(strict_types=1);

namespace Puzzlebook2\Feasibility;

final class FeasibilityReport
{
    /** @param string[] $problems */
    public function __construct(private array $problems)
    {
    }

    public function isFeasible(): bool
    {
        return $this->problems === [];
    }

    /** @return string[] */
    public function problems(): array
    {
        return $this->problems;
    }
}
