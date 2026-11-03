<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Solver\Rng;

/**
 * Cross out both words of any still-surviving pair on the same page where one
 * word is the other spelled backwards (4+ letters) -- STRAW/WARTS, DIAPER/
 * REPAID, LIVED/DEVIL.
 *
 * INCOMPATIBLE with AnagramPairOnPageRule: every reversal pair is also an
 * anagram pair, so the two would police each other's plants.
 */
final class SemordnilapPairOnPageRule extends PairOnPageRule
{
    /** @see \Puzzlebook2\Chain\ChainPlan incompatibility check. */
    public const INCOMPATIBLE_WITH = ['AnagramPairOnPageRule'];

    private const MIN_LENGTH = 4;

    public function describe(): string
    {
        return 'pairs of words on the same page where one is the other reversed (' . self::MIN_LENGTH . '+ letters)';
    }

    protected function matches(string $a, string $b): bool
    {
        return strlen($a) >= self::MIN_LENGTH && $a !== $b && $b === strrev($a);
    }

    protected function candidateGroups(array $prefixPool, int $seed): array
    {
        $inPool = [];
        foreach ($prefixPool as $w) {
            if (strlen($w) >= self::MIN_LENGTH) {
                $inPool[$w] = true;
            }
        }
        $groups = [];
        foreach ($inPool as $w => $_) {
            $r = strrev($w);
            if ($r !== $w && $w < $r && isset($inPool[$r])) {
                $groups[] = [$w, $r];
            }
        }
        return Rng::shuffle($groups, $seed);
    }

    public function ruleText(): string
    {
        return 'On every page, look for a pair of words, both still standing, where one word spelled backwards is exactly the other word (`STRAW` and `WARTS`, or `DIAPER` and `REPAID`). Cross out both words of the pair.';
    }
}
