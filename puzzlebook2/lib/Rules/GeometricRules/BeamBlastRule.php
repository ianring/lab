<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** BEAM: a horizontal ray -- every other word on the trigger's row, right across the page. */
final class BeamBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'beam';
    }

    protected function blastOffsets(): array
    {
        $offsets = [];
        // Reach the whole row; off-page columns are clipped by the base.
        for ($dc = -24; $dc <= 24; $dc++) {
            if ($dc !== 0) {
                $offsets[] = [0, $dc];
            }
        }
        return $offsets;
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `BEAM` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out every other word on the same row of that page -- the beam shoots all the way left and right across the row.';
    }
}
