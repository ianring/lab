<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Solver\Rng;

/**
 * Cross out both words of any still-surviving pair on the same page where one
 * word is the other with its first letter removed -- SPARK/PARK, BRANCH/RANCH.
 *
 * INCOMPATIBLE with PeelingPairOnPageRule (they would police each other's plants).
 */
final class BeheadingPairOnPageRule extends PairOnPageRule
{
    public const INCOMPATIBLE_WITH = ['PeelingPairOnPageRule'];

    /** The shorter word must be at least this long, so the longer is 4+. */
    private const MIN_SHORT = 3;

    public function describe(): string
    {
        return 'pairs on one page where one word is the other with its first letter removed';
    }

    private static function beheads(string $long, string $short): bool
    {
        return strlen($short) >= self::MIN_SHORT && strlen($long) === strlen($short) + 1 && substr($long, 1) === $short;
    }

    protected function matches(string $a, string $b): bool
    {
        return self::beheads($a, $b) || self::beheads($b, $a);
    }

    protected function candidateGroups(array $prefixPool, int $seed): array
    {
        $inPool = [];
        foreach ($prefixPool as $w) {
            $inPool[$w] = true;
        }
        $groups = [];
        foreach ($inPool as $w => $_) {
            $short = substr($w, 1);
            if (self::beheads($w, $short) && isset($inPool[$short])) {
                $groups[] = [$w, $short];
            }
        }
        return Rng::shuffle($groups, $seed);
    }

    public function ruleText(): string
    {
        return 'On every page, find a pair of words, both still standing, where one word is exactly the other with its first letter taken off (`BREAD` and `READ`, or `CLOSE` and `LOSE`). Cross out both words of the pair.';
    }
}
