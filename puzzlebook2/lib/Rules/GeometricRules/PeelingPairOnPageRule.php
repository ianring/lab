<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Solver\Rng;

/**
 * Cross out both words of any still-surviving pair on the same page where one
 * word is the other with BOTH its first and last letters removed -- PLANET/LANE,
 * SHARES/HARE.
 */
final class PeelingPairOnPageRule extends PairOnPageRule
{
    /** The shorter word must be at least this long, so the longer is 4+. */
    private const MIN_SHORT = 3;

    public function describe(): string
    {
        return 'pairs on one page where one word is the other with its first and last letters removed';
    }

    private static function peels(string $long, string $short): bool
    {
        return strlen($short) >= self::MIN_SHORT && strlen($long) === strlen($short) + 2 && substr($long, 1, -1) === $short;
    }

    protected function matches(string $a, string $b): bool
    {
        return self::peels($a, $b) || self::peels($b, $a);
    }

    protected function candidateGroups(array $prefixPool, int $seed): array
    {
        $inPool = [];
        foreach ($prefixPool as $w) {
            $inPool[$w] = true;
        }
        $groups = [];
        foreach ($inPool as $w => $_) {
            $short = substr($w, 1, -1);
            if (self::peels($w, $short) && isset($inPool[$short])) {
                $groups[] = [$w, $short];
            }
        }
        return Rng::shuffle($groups, $seed);
    }

    public function ruleText(): string
    {
        return 'On every page, find a pair of words, both still standing, where one word is exactly the other with both its first and last letters taken off (`PLANET` and `LANE`, or `SHARES` and `HARE`). Cross out both.';
    }
}
