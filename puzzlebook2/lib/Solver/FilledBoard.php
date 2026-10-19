<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

use Puzzlebook2\Board\BoardSpec;

/** An immutable, fully-solved board -- every cell has a word. Used by Rule::eliminates() and ChainRunner. */
final class FilledBoard
{
    /** @param string[] $words */
    public function __construct(
        private BoardSpec $spec,
        private array $words,
    ) {
        if (count($words) !== $spec->size()) {
            throw new \InvalidArgumentException('FilledBoard requires exactly one word per cell.');
        }
    }

    public function spec(): BoardSpec
    {
        return $this->spec;
    }

    public function wordAt(int $cell): string
    {
        return $this->words[$cell];
    }

    /** @return string[] */
    public function allWords(): array
    {
        return $this->words;
    }
}
