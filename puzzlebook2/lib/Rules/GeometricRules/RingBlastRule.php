<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/**
 * RING: the radius-2 shell -- the sixteen words forming a square ring two steps
 * out from a RING word (the border of a 5x5 block, leaving the inner 3x3
 * untouched).
 */
final class RingBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'ring';
    }

    protected function blastOffsets(): array
    {
        $offsets = [];
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                // Chebyshev distance exactly 2: the shell, not the filled block.
                if (max(abs($dr), abs($dc)) === 2) {
                    $offsets[] = [$dr, $dc];
                }
            }
        }
        return $offsets;
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `RING` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out the sixteen words that form a square ring two steps out from it on the same page (the border of the 5x5 block around it, leaving the eight words immediately touching it alone).';
    }
}
