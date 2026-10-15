<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * A tiny seeded, deterministic shuffle. Not cryptographic -- just enough to
 * scatter filler words so the book doesn't visibly betray that it was built
 * from an alphabetically sorted word list, and so decoys of one kind don't
 * land at a suspiciously regular interval. Same seed always gives the same
 * order, so a build stays reproducible.
 */
final class Rng
{
    /**
     * @param mixed[] $items
     * @return mixed[]
     */
    public static function shuffle(array $items, int $seed): array
    {
        $state = $seed === 0 ? 1 : $seed;
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
}
