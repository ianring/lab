<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

/**
 * BOOM: the original reaction -- the eight words around a BOOM word (a full 3x3
 * blast). Reactive: it sleeps until some other rule crosses the BOOM word, then
 * goes off. This is BoomReactionRule refolded onto the shared ReactionBlastRule
 * base; the behaviour (reactive, 3x3) is unchanged.
 */
final class BoomBlastRule extends ReactionBlastRule
{
    protected function needle(): string
    {
        return 'boom';
    }

    protected function blastOffsets(): array
    {
        return [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
    }

    public function ruleText(): string
    {
        return 'Watch for any word that has the letters `BOOM` in it. The instant such a word gets crossed out by any rule, it goes off like a bomb: also cross out the eight words immediately around it on the same page -- up, down, left, right, and the four diagonals. (A word near an edge simply has fewer neighbours to take out.) Keep an eye out, because a blast can clear words no other rule would ever reach.';
    }
}
