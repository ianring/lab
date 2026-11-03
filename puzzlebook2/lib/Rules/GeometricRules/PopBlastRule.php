<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** POP: a small burst -- the four words orthogonally touching a POP word (up, down, left, right). */
final class PopBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'pop';
    }

    protected function blastOffsets(): array
    {
        return [[-1, 0], [1, 0], [0, -1], [0, 1]];
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `POP` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out the four words directly above, below, left, and right of it on the same page (not the diagonals).';
    }
}
