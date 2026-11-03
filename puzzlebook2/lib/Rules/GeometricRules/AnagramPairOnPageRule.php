<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Solver\Rng;

/**
 * Cross out both words of any still-surviving pair on the same page that are
 * anagrams of each other (4+ letters) -- LISTEN/SILENT, DUSTY/STUDY.
 */
final class AnagramPairOnPageRule extends PairOnPageRule
{
    private const MIN_LENGTH = 4;

    /** @var array<string,string> memoized word => sorted-letter signature (pure) */
    private static array $sigCache = [];

    private static function signatureOf(string $word): ?string
    {
        if (strlen($word) < self::MIN_LENGTH) {
            return null;
        }
        if (!isset(self::$sigCache[$word])) {
            $letters = str_split($word);
            sort($letters);
            self::$sigCache[$word] = implode('', $letters);
        }
        return self::$sigCache[$word];
    }

    protected function maxPairsPerPage(): float
    {
        return 1.2;
    }

    public function describe(): string
    {
        return 'pairs of anagrams on the same page (' . self::MIN_LENGTH . '+ letters)';
    }

    protected function matches(string $a, string $b): bool
    {
        $sa = self::signatureOf($a);
        return $sa !== null && $sa === self::signatureOf($b);
    }

    protected function candidateGroups(array $prefixPool, int $seed): array
    {
        $groups = [];
        foreach ($prefixPool as $w) {
            $sig = self::signatureOf($w);
            if ($sig !== null) {
                $groups[$sig][] = $w;
            }
        }
        $groups = array_values(array_filter($groups, static fn (array $ws): bool => count($ws) > 1));
        return Rng::shuffle($groups, $seed);
    }

    public function ruleText(): string
    {
        return 'On every page, find every pair of words that are both still standing and are anagrams of each other (`LISTEN` and `SILENT`, or `DUSTY` and `STUDY`). Cross out both words of the pair.';
    }
}
