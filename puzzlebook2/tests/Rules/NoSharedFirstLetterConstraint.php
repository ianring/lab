<?php

declare(strict_types=1);

namespace Puzzlebook2Tests\Rules;

use Puzzlebook2\Constraints\Constraint;
use Puzzlebook2\Solver\BoardView;

final class NoSharedFirstLetterConstraint implements Constraint
{
    /**
     * @param int[] $watched
     * @param string[] $forbiddenFirstLetters
     */
    public function __construct(
        private array $watched,
        private array $forbiddenFirstLetters,
    ) {
    }

    public function violatedBy(int $cell, string $candidate, BoardView $view, ?string $claimantRuleId = null): bool
    {
        return in_array($candidate[0], $this->forbiddenFirstLetters, true);
    }

    public function watchedCells(): array
    {
        return $this->watched;
    }

    public function describe(): string
    {
        return 'first letter must differ from an adjacent reserved pair word';
    }
}
