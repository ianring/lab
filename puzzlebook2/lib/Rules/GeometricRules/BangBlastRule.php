<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** BANG: a big cross -- orthogonal reach of two in every direction from a BANG word. */
final class BangBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'bang';
    }

    protected function blastOffsets(): array
    {
        return [[-1, 0], [-2, 0], [1, 0], [2, 0], [0, -1], [0, -2], [0, 1], [0, 2]];
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `BANG` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out the words up to two steps away straight up, down, left, and right of it on the same page -- a large plus-shaped blast (no diagonals).';
    }
}
