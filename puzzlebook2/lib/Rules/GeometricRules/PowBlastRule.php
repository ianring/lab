<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/** POW: a diagonal punch -- the four words touching a POW word at its corners. */
final class PowBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'pow';
    }

    protected function blastOffsets(): array
    {
        return [[-1, -1], [-1, 1], [1, -1], [1, 1]];
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `POW` in it. The instant such a word gets crossed out by any rule -- no matter when it happens -- it triggers: also cross out the four words that touch it only at a corner (the four diagonal neighbours on the same page).';
    }
}
