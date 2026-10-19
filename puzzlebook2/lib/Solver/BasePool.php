<?php

declare(strict_types=1);

namespace Puzzlebook2\Solver;

/**
 * A shared, cached, pre-shuffled word list -- e.g. "every word surviving
 * the first 11 spelling rules" -- with a resumable "floor" shared by every
 * cell that draws from it. Ported from v1's Dictionary::fillerFromPool():
 * without a shared floor, every cell re-scans the pool from position 0,
 * which at full book size is the difference between a build that finishes
 * and one that doesn't.
 *
 * The floor only ever advances past words that are globally CONSUMED right
 * now -- never past a word that merely failed one cell's own exclusions.
 * Because v2 backtracks (v1 never did), "consumed" is not monotone: every
 * floor advance is recorded on the Trail (PoolFloorEntry), so undoing a
 * decision rewinds the floor together with the claims it skipped past.
 * Without that, each backtrack would permanently strand the released words
 * below the floor and the pool's supply would only ever shrink.
 *
 * One BasePool is shared by every Domain with the same (prefix level,
 * shape signature) -- e.g. every ordinary ConsonantRunRule cell shares one
 * pool, not 500 separate copies.
 */
final class BasePool
{
    /** @var string[] */
    private array $words;

    private int $floor = 0;

    public function __construct(array $words, private ConsumedWords $consumed, private Trail $trail)
    {
        $this->words = $words;
    }

    /**
     * Advances the shared floor past anything now globally consumed
     * (recording the move on the trail), then scans forward for the first
     * word also passing every cell-specific exclusion. Returns null if none
     * exists from the floor onward -- the actual "domain empty" signal.
     *
     * @param \Closure[] $exclusions each (string $word): bool, true = excluded
     */
    public function firstMatch(array $exclusions): ?string
    {
        $n = count($this->words);
        $before = $this->floor;
        while ($this->floor < $n && $this->consumed->isConsumed($this->words[$this->floor])) {
            $this->floor++;
        }
        if ($this->floor !== $before) {
            $this->trail->push(new PoolFloorEntry($this, $before));
        }

        for ($pos = $this->floor; $pos < $n; $pos++) {
            $word = $this->words[$pos];
            if ($this->consumed->isConsumed($word)) {
                continue;
            }
            $ok = true;
            foreach ($exclusions as $excluded) {
                if ($excluded($word)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $word;
            }
        }
        return null;
    }

    /** Called only by PoolFloorEntry::undo(). */
    public function restoreFloor(int $floor): void
    {
        $this->floor = $floor;
    }

    /** Approximate remaining size, for MRV ordering -- not exact, not worth making exact. */
    public function estimatedSize(): int
    {
        return count($this->words) - $this->floor;
    }
}
