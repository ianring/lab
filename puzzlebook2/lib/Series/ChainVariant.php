<?php

declare(strict_types=1);

namespace Puzzlebook2\Series;

use Puzzlebook2\Solver\Rng;

/**
 * Composes a DIFFERENT, varied, interleaved elimination chain for every book.
 *
 * The rules of the whole library are tagged by MECHANISM FAMILY and by their
 * dictionary supply (how many words each can cross out). For each book we:
 *   1. SELECT a diverse subset (seeded by book number) -- Contains* substring
 *      cards are capped to a small minority, incompatible/overlapping pairs are
 *      never taken together, and a few high-supply "workhorses" are guaranteed
 *      so the cascade can tile the board.
 *   2. ORDER it by INTERLEAVING families so no two adjacent rules share a
 *      mechanism -- every rule feels different from the one before it, and you
 *      never see a run of Contains cards.
 *   3. SEED keep_fractions from supply (scarce rules take little, workhorses
 *      carry the bulk); AutoTuner polishes each book's cascade before it builds.
 * Each book ends with the universal VowelRun absorber + an unprinted decoy + the
 * WordLength7 final sweep (kept in every book so planted Mystery/acrostic/bomb
 * words -- all short, adjacent-vowel -- are reliably eliminable everywhere).
 *
 * Because every book draws a different subset in a different order, the books
 * are genuinely different puzzles; build-series.php picks each book's answer from
 * its OWN survivor set.
 */
final class ChainVariant
{
    /** The one book (1..20) that also carries the BOOM trigger-reaction. */
    public const REACTION_BOOK = 7;

    private const NS = 'Puzzlebook2\\Rules\\SpellingRules\\';
    /** Always present in every book -- it catches the planted Mystery / acrostic / bomb words, which are all adjacent-vowel pairs. */
    private const FORCED = 'VowelRunRule';

    /** One chosen per book as the absorber (the last keep_fraction step), so the penultimate rule varies. Only VERY high real supply ones, since the absorber must soak the leftover. */
    private const ABSORBERS = ['VowelBothEndsRule', 'LongIsogramRule', 'DoubledConsonantRule', 'WordLength10Rule', 'VowelRunRule'];

    /** [decoy, final sweep] pairs, one chosen per book, so the last rule AND the held-back decoys vary book to book. */
    private const FINALS = [
        ['DecoyLength6Rule', 'WordLength6Rule'],
        ['DecoyLength7Rule', 'WordLength7Rule'],
        ['DecoyLength8Rule', 'WordLength8Rule'],
        ['DecoyLength9Rule', 'WordLength9Rule'],
    ];

    /** Selectable rules: type => [family, dictionary supply]. (Absorber + final are reserved, not here.) */
    private const POOL = [
        'AbbaPatternRule' => ['shape', 18, 'once'],
        'AnagramHalvesRule' => ['shape', 31, 'once'],
        'NoVowelsRule' => ['letterset', 101, 'once'],
        'RepeatedBlockRule' => ['shape', 107, 'once'],
        'PalindromeRule' => ['shape', 108],
        'MusicalNotesRule' => ['letterset', 127, 'once'],
        'ContainsOughRule' => ['substring', 179],
        'ThreeDistinctLettersRule' => ['shape', 208],
        'RepeatedLetterRule' => ['doubles', 225],
        'UsesOnlyLettersRule' => ['letterset', 258],
        'ContainsSchRule' => ['substring', 266],
        'AlternatingVowelRule' => ['vcrun', 277],
        'ReverseAlphabeticalOrderRule' => ['order', 283],
        'ContainsKnRule' => ['substring', 324],
        'ContainsWrRule' => ['substring', 333],
        'ContainsDgeRule' => ['substring', 334],
        'ContainsSqRule' => ['substring', 349],
        'AlphabeticalOrderRule' => ['order', 380],
        'ContainsThrRule' => ['substring', 419],
        'AllFiveVowelsRule' => ['hidden', 548],
        'ContainsGhtRule' => ['substring', 576],
        'ContainsNchRule' => ['substring', 581],
        'ContainsRhRule' => ['substring', 596],
        'ContainsSwRule' => ['substring', 620],
        'ContainsTchRule' => ['substring', 635],
        'BoggleBoxRule' => ['boggle', 669, 'once'],
        'BoggleValeRule' => ['boggle', 614, 'once'],
        'BoggleReefRule' => ['boggle', 435, 'once'],
        'BoggleMoorRule' => ['boggle', 382, 'once'],
        'BoggleGladeRule' => ['boggle', 381, 'once'],
        'ContainsWhRule' => ['substring', 729],
        'ContainsOeRule' => ['substring', 751],
        'ContainsSkRule' => ['substring', 776],
        'AlphabetAdjacentEndsRule' => ['ends', 807],
        'ContainsFrRule' => ['substring', 897],
        'ConsecutiveAlphabetRunRule' => ['hidden', 967],
        'ContainsNkRule' => ['substring', 1014],
        'ContainsGhRule' => ['substring', 1050],
        'ContainsSlRule' => ['substring', 1170],
        'ContainsStrRule' => ['substring', 1171],
        'ContainsTionRule' => ['substring', 1310],
        'LetterCountRule' => ['length', 1331],
        'ContainsMbRule' => ['substring', 1476],
        'ContainsDrRule' => ['substring', 1545],
        'ContainsFlRule' => ['substring', 1597],
        'StackedDoublesRule' => ['doubles', 1602],
        'DoubleEFewVowelsRule' => ['doubles', 1674],
        'ContainsClRule' => ['substring', 1703],
        'ContainsQuRule' => ['substring', 1762],
        'ConsonantRunRule' => ['vcrun', 1938],
        'ContainsMpRule' => ['substring', 1938],
        'ContainsBrRule' => ['substring', 1953],
        'ContainsGrRule' => ['substring', 1982],
        'FourConsonantsOneVowelRule' => ['vcrun', 2142],
        'AlternatingConsonantVowelRule' => ['vcrun', 2148],
        'StartsWithTwoVowelsRule' => ['vcrun', 2269],
        'ContainsPlRule' => ['substring', 2435],
        'ContainsCrRule' => ['substring', 2577],
        'HidesANumberRule' => ['hidden', 2584],
        'ContainsSpRule' => ['substring', 2610],
        'ContainsScRule' => ['substring', 2716],
        'ContainsCkRule' => ['substring', 2867],
        'ContainsPhRule' => ['substring', 3128],
        'StraightLineLettersRule' => ['letterset', 3353],
        'EndsWithIngRule' => ['ending', 3715],
        'ContainsZRule' => ['substring', 3724],
        'ContainsPrRule' => ['substring', 3836],
        'ContainsBlRule' => ['substring', 3974],
        'ContainsShRule' => ['substring', 4700],
        'ContainsTrRule' => ['substring', 5281],
        'ContainsChRule' => ['substring', 5541],
        'BothEndPairsInSetRule' => ['ends', 5882],
        'VowelRunRule' => ['vcrun', 6579],
        'ContainsNgRule' => ['substring', 7181],
        'VowelBothEndsRule' => ['ends', 7786],
        'LongIsogramRule' => ['shape', 10600],
        'DoubledConsonantRule' => ['doubles', 21282],
        'WordLength10Rule' => ['length', 27928],
    ];

    /** Pairs that build fine together but overlap too much to be interesting in one book (not hard-incompatible). */
    private const AVOID_TOGETHER = [
        'DoubledConsonantRule' => ['StackedDoublesRule', 'RepeatedLetterRule'],
        'StackedDoublesRule' => ['DoubledConsonantRule'],
        'RepeatedLetterRule' => ['DoubledConsonantRule'],
    ];

    private const WORKHORSE_MIN_SUPPLY = 3000;
    private const BOARD_REF = 12500;

    /**
     * Compose the WHOLE series at once, so single-use (`once`) rules are consumed
     * across books -- each lands in exactly one book, which scatters the Boggle
     * boards and other showpieces. Reusable rules fill the rest, a different
     * subset and order per book. Returns [bookNo => chain] with a placeholder
     * answer (build-series picks each book's real answer from its own survivors).
     *
     * @return array<int,array{steps: array, answer: string}>
     */
    public static function composeSeries(int $total, int $capstoneBook): array
    {
        $once = [];
        $reuse = [];
        foreach (self::POOL as $type => $meta) {
            if (($meta[2] ?? 'reuse') === 'once') {
                $once[] = $type;
            } else {
                $reuse[] = $type;
            }
        }
        // Spread the single-use rules EVENLY across all books (shuffled first,
        // so which showpiece lands where varies) -- scattered every few books.
        $once = Rng::shuffle($once, 55555);
        $assigned = array_fill(1, $total, []);
        $n = max(1, count($once));
        foreach ($once as $i => $type) {
            $assigned[1 + (int) floor($i * $total / $n)][] = $type;
        }

        $chains = [];
        for ($n = 1; $n <= $total; $n++) {
            $count = $n === $capstoneBook ? 20 : 30;
            $chains[$n] = self::compose($n, $count, $reuse, $assigned[$n], 'placeholder', $n === self::REACTION_BOOK);
        }
        return $chains;
    }

    /** A single book, ignoring cross-book single-use (for inspection/tests). */
    public static function forBook(int $bookNo, string $answer): array
    {
        return self::compose($bookNo, 30, array_keys(self::POOL), [], $answer, $bookNo === self::REACTION_BOOK);
    }

    /** The Capstone, standalone: leaner (fewer, chunkier rules). */
    public static function forCapstone(string $answer): array
    {
        return self::compose(9001, 20, array_keys(self::POOL), [], $answer, false);
    }

    /**
     * Build one book: choose its absorber and final sweep (so its tail differs
     * from every other book's), force VowelRun in, fill the rest from $pool,
     * interleave the body, and assemble. The absorber is pulled out of the body
     * and placed last (as the absorber). $forcedOnce are this book's single-use
     * showpieces.
     *
     * @param string[] $pool        rule types this book may draw from
     * @param string[] $forcedOnce  single-use rules pre-assigned to this book
     */
    private static function compose(int $seed, int $count, array $pool, array $forcedOnce, string $answer, bool $withReaction): array
    {
        $absorber = self::ABSORBERS[$seed % count(self::ABSORBERS)];
        $finalPair = self::FINALS[(intdiv($seed, 2) + 1) % count(self::FINALS)];   // de-correlate from the absorber
        $forced = array_values(array_unique(array_merge([self::FORCED, $absorber], $forcedOnce)));
        $selected = self::fillSelection(Rng::shuffle($pool, $seed), $count, $forced);
        $body = array_values(array_filter($selected, static fn (string $t): bool => $t !== $absorber));
        $ordered = self::interleave($body, $seed);
        return self::assemble($ordered, $absorber, $finalPair, $answer, $withReaction);
    }

    /**
     * Pick $count diverse rules for a book: Contains* a minority, no two rules
     * that are incompatible or heavily overlapping, at least three workhorses so
     * the cascade has the supply to tile.
     *
     * @return string[] selected rule types
     */
    private static function fillSelection(array $order, int $count, array $selected = []): array
    {
        $maxSubstring = (int) ceil($count * 0.18);   // Contains* kept a clear minority
        $substrings = 0;
        $workhorses = 0;
        foreach ($selected as $s) {
            if (self::POOL[$s][0] === 'substring') {
                $substrings++;
            }
            if (self::POOL[$s][1] >= self::WORKHORSE_MIN_SUPPLY) {
                $workhorses++;
            }
        }

        $canAdd = static function (string $cand) use (&$selected): bool {
            $bad = array_merge(self::incompatibles($cand), self::AVOID_TOGETHER[$cand] ?? []);
            foreach ($selected as $s) {
                if (in_array($s, $bad, true) || in_array($cand, self::incompatibles($s), true)
                    || in_array($cand, self::AVOID_TOGETHER[$s] ?? [], true)) {
                    return false;
                }
            }
            return true;
        };

        foreach ($order as $cand) {
            if (count($selected) >= $count) {
                break;
            }
            if (in_array($cand, $selected, true)) {
                continue; // already seeded (a single-use rule assigned to this book)
            }
            $fam = self::POOL[$cand][0];
            if ($fam === 'substring' && $substrings >= $maxSubstring) {
                continue;
            }
            if (!$canAdd($cand)) {
                continue;
            }
            $selected[] = $cand;
            if ($fam === 'substring') {
                $substrings++;
            }
            if (self::POOL[$cand][1] >= self::WORKHORSE_MIN_SUPPLY) {
                $workhorses++;
            }
        }

        // Guarantee enough high-supply rules for feasibility.
        if ($workhorses < 3) {
            foreach ($order as $cand) {
                if ($workhorses >= 3) {
                    break;
                }
                if (in_array($cand, $selected, true) || self::POOL[$cand][1] < self::WORKHORSE_MIN_SUPPLY || !$canAdd($cand)) {
                    continue;
                }
                $selected[] = $cand;
                $workhorses++;
            }
        }
        return $selected;
    }

    /**
     * Order the selection so no two adjacent rules share a family (greedy: always
     * take the most-plentiful family that is not the previous one).
     *
     * @param string[] $types
     * @return string[]
     */
    private static function interleave(array $types, int $seed): array
    {
        $byFam = [];
        foreach ($types as $t) {
            $byFam[self::POOL[$t][0]][] = $t;
        }
        foreach ($byFam as $f => $list) {
            $byFam[$f] = Rng::shuffle($list, $seed + (int) (crc32($f) & 0x7fffffff));
        }

        $out = [];
        $lastFam = null;
        $n = count($types);
        while (count($out) < $n) {
            $best = null;
            $bestCount = -1;
            foreach ($byFam as $f => $list) {
                if ($f === $lastFam || $list === []) {
                    continue;
                }
                if (count($list) > $bestCount) {
                    $bestCount = count($list);
                    $best = $f;
                }
            }
            if ($best === null) { // only the previous family remains -- unavoidable repeat
                foreach ($byFam as $f => $list) {
                    if ($list !== []) {
                        $best = $f;
                        break;
                    }
                }
            }
            $out[] = array_pop($byFam[$best]);
            $lastFam = $best;
        }
        return $out;
    }

    /**
     * Seed keep_fractions by spreading the board across the body PROPORTIONAL to
     * supply, capped so no single rule takes a giant chunk. The point is to leave
     * the absorber only a small remainder: a rule that has to place ~4000 words is
     * both slow to construct and liable to run out of real (post-overlap) supply.
     * High-supply rules each carry up to MAX_PER_RULE; scarce rules take a little.
     */
    private static function seedKeepFractions(array $types): array
    {
        $maxPer = 1300;               // ceiling for any one body rule (~10% of board)
        $reserve = 500;               // small leftover for the absorber to soak
        $target = (int) (self::BOARD_REF * 0.98) - $reserve;

        // Per-rule caps and supply weights.
        $caps = [];
        $weights = [];
        foreach ($types as $t) {
            $caps[$t] = min((int) floor(0.7 * self::POOL[$t][1]), $maxPer);
            $weights[$t] = self::POOL[$t][1];
        }

        // Water-fill proportional to supply, respecting each rule's cap: high-supply
        // rules fill to the cap, the remainder is shared out by supply.
        $budgets = array_fill_keys($types, 0);
        $active = $types;
        $remaining = $target;
        for ($iter = 0; $iter < 20 && $remaining > 0 && $active !== []; $iter++) {
            $wsum = 0;
            foreach ($active as $t) {
                $wsum += $weights[$t];
            }
            if ($wsum <= 0) {
                break;
            }
            $next = [];
            $spent = 0;
            foreach ($active as $t) {
                $add = (int) round($remaining * $weights[$t] / $wsum);
                $room = $caps[$t] - $budgets[$t];
                if ($add >= $room) {
                    $budgets[$t] = $caps[$t];
                    $spent += $room;
                } else {
                    $budgets[$t] += $add;
                    $spent += $add;
                    $next[] = $t;
                }
            }
            $remaining -= $spent;
            if ($spent <= 0) {
                break;
            }
            $active = $next;
        }

        $kf = [];
        $rem = self::BOARD_REF;
        foreach ($types as $t) {
            $b = min(max(1, $budgets[$t]), $rem - 1);
            $kf[$t] = round(1 - $b / $rem, 5);
            $rem -= $b;
        }
        return $kf;
    }

    /**
     * @param string[] $orderedTypes
     * @return array{steps: array, answer: string}
     */
    private static function assemble(array $orderedTypes, string $absorber, array $finalPair, string $answer, bool $withReaction): array
    {
        $kf = self::seedKeepFractions($orderedTypes);
        $steps = [];
        foreach ($orderedTypes as $type) {
            $steps[] = self::eliminate($type, $kf[$type]);
        }
        $steps[] = self::eliminate($absorber, 0.04);   // absorber: ChainPlan overrides its budget to the remainder
        [$decoy, $final] = $finalPair;
        $steps[] = [
            'kind' => 'eliminate',
            'printed' => false,
            'rule' => ['type' => $decoy],
            'construction' => ['derived' => true],
        ];
        $steps[] = [
            'kind' => 'eliminate',
            'printed' => true,
            'final' => true,
            'rule' => ['type' => $final],
        ];
        if ($withReaction) {
            $steps[] = [
                'kind' => 'eliminate',
                'printed' => true,
                'rule' => ['type' => 'BoomReactionRule'],
                'construction' => ['derived' => true],
            ];
        }

        foreach ($steps as $i => &$step) {
            $step['id'] = $i + 1;
        }
        unset($step);

        return ['steps' => $steps, 'answer' => $answer];
    }

    private static function eliminate(string $type, float $keepFraction): array
    {
        return [
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => $type],
            'construction' => ['keep_fraction' => $keepFraction],
        ];
    }

    /** @return string[] the INCOMPATIBLE_WITH list declared on a rule class, if any. */
    private static function incompatibles(string $type): array
    {
        $fqcn = self::NS . $type;
        $const = $fqcn . '::INCOMPATIBLE_WITH';
        return defined($const) ? constant($const) : [];
    }
}
