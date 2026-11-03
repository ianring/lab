<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/**
 * DIAG: the full diagonal rays -- every word along both diagonals through a
 * DIAG word, all the way to the page edges (an X stretching out from it).
 * DIAG is a rare trigger (~20 words), so it suits a single-use showpiece.
 */
final class DiagBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'diag';
    }

    protected function blastOffsets(): array
    {
        $offsets = [];
        // Both diagonals to the edges; off-page cells are clipped by the base.
        for ($k = 1; $k <= 24; $k++) {
            $offsets[] = [$k, $k];
            $offsets[] = [$k, -$k];
            $offsets[] = [-$k, $k];
            $offsets[] = [-$k, -$k];
        }
        return $offsets;
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `DIAG` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out every word along the two diagonals running through it, all the way to the edges of the page -- a big X.';
    }
}
