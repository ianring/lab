<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * A tiny seeded, deterministic shuffle. Not cryptographic -- just enough to
 * scatter filler words so the book doesn't visibly betray that it was built
 * from an alphabetically sorted word list. Same seed always gives the same
 * order, so a build stays reproducible -- a property the solver's
 * resume-by-index backtracking also depends on.
 *
 * The seed is scrambled (splitmix32-style finalizer) before driving the
 * xorshift32 stream: raw xorshift's first outputs are strongly correlated
 * for nearby seeds, and nearby seeds are the NORM here (slot seeds differ
 * by small arithmetic offsets), which without mixing made "different seeds"
 * produce identical short shuffles.
 */
final class Rng
{
    /**
     * @param mixed[] $items
     * @return mixed[]
     */
    public static function shuffle(array $items, int $seed): array
    {
        $state = self::mix($seed);
        $next = static function () use (&$state): float {
            // xorshift32
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= ($state >> 17);
            $state ^= ($state << 5) & 0xFFFFFFFF;
            $state &= 0xFFFFFFFF;
            return $state / 0xFFFFFFFF;
        };

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = min($i, (int) floor($next() * ($i + 1)));
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /** splitmix32-style avalanche: every input bit affects every output bit. */
    private static function mix(int $seed): int
    {
        $z = ($seed + 0x9E3779B9) & 0xFFFFFFFF;
        $z = (($z ^ ($z >> 16)) * 0x21F0AAAD) & 0xFFFFFFFF;
        $z = (($z ^ ($z >> 15)) * 0x735A2D97) & 0xFFFFFFFF;
        $z ^= ($z >> 15);
        return $z === 0 ? 1 : $z;
    }
}
