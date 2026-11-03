<?php

declare(strict_types=1);

namespace Puzzlebook2\Series;

use Puzzlebook2\Dictionary\RuleIndexCache;
use Puzzlebook2\Rules\SpellingRuleBase;
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

    /**
     * Curated family for each pool rule whose class name doesn't announce it.
     * The big families are inferred from the name (Contains* => substring,
     * Boggle* => boggle, Crossword* => crossword), so a new digraph / boggle /
     * crossword rule needs no entry here at all -- just drop the class in.
     */
    private const FAMILY = [
        'AbbaPatternRule' => 'shape', 'AnagramHalvesRule' => 'shape', 'RepeatedBlockRule' => 'shape',
        'PalindromeRule' => 'shape', 'ThreeDistinctLettersRule' => 'shape', 'LongIsogramRule' => 'shape',
        'NoVowelsRule' => 'letterset', 'MusicalNotesRule' => 'letterset', 'RstlneRule' => 'letterset',
        'UsesOnlyLettersRule' => 'letterset', 'StraightLineLettersRule' => 'letterset',
        'RepeatedLetterRule' => 'doubles', 'StackedDoublesRule' => 'doubles',
        'DoubleEFewVowelsRule' => 'doubles', 'DoubledConsonantRule' => 'doubles',
        'AlternatingVowelRule' => 'vcrun', 'ConsonantRunRule' => 'vcrun', 'FourConsonantsOneVowelRule' => 'vcrun',
        'AlternatingConsonantVowelRule' => 'vcrun', 'StartsWithTwoVowelsRule' => 'vcrun', 'VowelRunRule' => 'vcrun',
        'ReverseAlphabeticalOrderRule' => 'order', 'AlphabeticalOrderRule' => 'order',
        'FirstTwoEqualLastTwoRule' => 'ends', 'AlphabetAdjacentEndsRule' => 'ends',
        'BothEndPairsInSetRule' => 'ends', 'VowelBothEndsRule' => 'ends',
        'AllFiveVowelsRule' => 'hidden', 'ConsecutiveAlphabetRunRule' => 'hidden', 'HidesANumberRule' => 'hidden',
        'LetterCountRule' => 'length', 'WordLength10Rule' => 'length',
        'EndsWithIngRule' => 'ending',
    ];

    /** Selectable GEOMETRIC pool rules (opt-in; spelling rules are auto-discovered). type => [family, nominal supply]. */
    private const GEOMETRIC_POOL = [
        'CompoundTripleOnPageRule' => ['compound', 450],
    ];

    /** The four length finals (decoy + sweep) that are reserved, never selectable body rules. */
    private const FINAL_TYPES = [
        'DecoyLength6Rule', 'WordLength6Rule', 'DecoyLength7Rule', 'WordLength7Rule',
        'DecoyLength8Rule', 'WordLength8Rule', 'DecoyLength9Rule', 'WordLength9Rule',
    ];

    /** Rules used at most once across the whole series (scarce showpieces). Boggle* are all 'once' by convention. */
    private const SINGLE_USE = [
        'CrosswordNightRule', 'CrosswordCanoeRule', 'AbbaPatternRule', 'AnagramHalvesRule',
        'NoVowelsRule', 'RepeatedBlockRule', 'MusicalNotesRule', 'RstlneRule', 'CompoundTripleOnPageRule',
    ];

    /** Memoized derived pool: type => [family, supply, 'once'?]. @var array<string,array>|null */
    private static ?array $poolCache = null;
    /** @var string[]|null */
    private static ?array $wordList = null;
    private static ?string $wordListHash = null;

    /** Pairs that build fine together but overlap too much to be interesting in one book (not hard-incompatible). */
    private const AVOID_TOGETHER = [
        'DoubledConsonantRule' => ['StackedDoublesRule', 'RepeatedLetterRule'],
        'StackedDoublesRule' => ['DoubledConsonantRule'],
        'RepeatedLetterRule' => ['DoubledConsonantRule'],
        // CrosswordBaggyRule (_a__y) is ~81% a sub-case of FourConsonantsOneVowelRule:
        // its single vowel is usually the only vowel, so the 4C-1V rule eats most of
        // its catches when both share a book. Keep them apart so each pulls its own weight.
        'CrosswordBaggyRule' => ['FourConsonantsOneVowelRule'],
        'FourConsonantsOneVowelRule' => ['CrosswordBaggyRule'],
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
        foreach (self::pool() as $type => $meta) {
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
        return self::compose($bookNo, 30, array_keys(self::pool()), [], $answer, $bookNo === self::REACTION_BOOK);
    }

    /** The Capstone, standalone: leaner (fewer, chunkier rules). */
    public static function forCapstone(string $answer): array
    {
        return self::compose(9001, 20, array_keys(self::pool()), [], $answer, false);
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
            if (self::pool()[$s][0] === 'substring') {
                $substrings++;
            }
            if (self::pool()[$s][1] >= self::WORKHORSE_MIN_SUPPLY) {
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
            $fam = self::pool()[$cand][0];
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
            if (self::pool()[$cand][1] >= self::WORKHORSE_MIN_SUPPLY) {
                $workhorses++;
            }
        }

        // Guarantee enough high-supply rules for feasibility.
        if ($workhorses < 3) {
            foreach ($order as $cand) {
                if ($workhorses >= 3) {
                    break;
                }
                if (in_array($cand, $selected, true) || self::pool()[$cand][1] < self::WORKHORSE_MIN_SUPPLY || !$canAdd($cand)) {
                    continue;
                }
                $selected[] = $cand;
                $workhorses++;
            }
        }
        return $selected;
    }

    /**
     * Order the selection SCARCE-FIRST while keeping no two adjacent rules in the
     * same family. Supply-aware ordering matters because a scarce rule placed behind
     * a high-supply workhorse has most of its catches eaten before its turn -- e.g.
     * CrosswordBaggy saw only 5 of its 53 board matches survive to a late slot. So at
     * each step we take the lowest-supply next rule, EXCEPT when a family is big enough
     * that deferring it would strand it against the adjacency rule, in which case we
     * drain the most-plentiful family first (the old behaviour, kept for feasibility).
     * An occasional same-family repeat is tolerated as a last resort, exactly as before.
     *
     * @param string[] $types
     * @return string[]
     */
    private static function interleave(array $types, int $seed): array
    {
        $byFam = [];
        foreach ($types as $t) {
            $byFam[self::pool()[$t][0]][] = $t;
        }
        // Within a family, order so array_pop() yields the scarcest member first:
        // sort by supply descending, a deterministic shuffle breaking ties.
        foreach ($byFam as $f => $list) {
            $list = Rng::shuffle($list, $seed + (int) (crc32($f) & 0x7fffffff));
            usort($list, static fn (string $a, string $b): int => self::pool()[$b][1] <=> self::pool()[$a][1]);
            $byFam[$f] = $list;
        }

        $out = [];
        $lastFam = null;
        $n = count($types);
        while (count($out) < $n) {
            $remaining = $n - count($out);
            $globalMax = 0;
            foreach ($byFam as $list) {
                $globalMax = max($globalMax, count($list));
            }
            // Slack test: if even after spending this slot elsewhere the biggest family
            // can still be spaced out (2*max <= remaining), we are free to pick by supply.
            $free = 2 * $globalMax <= $remaining;

            $best = null;
            if ($free) {
                $bestSupply = PHP_INT_MAX; // scarce-first: lowest-supply next member
                foreach ($byFam as $f => $list) {
                    if ($f === $lastFam || $list === []) {
                        continue;
                    }
                    $supply = self::pool()[$list[count($list) - 1]][1];
                    if ($supply < $bestSupply) {
                        $bestSupply = $supply;
                        $best = $f;
                    }
                }
            } else {
                $bestCount = -1; // feasibility: drain the most-plentiful family first
                foreach ($byFam as $f => $list) {
                    if ($f === $lastFam || $list === []) {
                        continue;
                    }
                    if (count($list) > $bestCount) {
                        $bestCount = count($list);
                        $best = $f;
                    }
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
        $maxPer = 800;                // ceiling for any one body rule (~6.4% of board) -- no single rule dominates
        $floor = 120;                 // minimum budget for any body rule, so the middle never starves
        $reserve = 500;               // small leftover for the absorber to soak
        $target = (int) (self::BOARD_REF * 0.98) - $reserve;

        // Per-rule caps and supply weights.
        $caps = [];
        $weights = [];
        foreach ($types as $t) {
            $caps[$t] = min((int) floor(0.7 * self::pool()[$t][1]), $maxPer);
            $weights[$t] = self::pool()[$t][1];
        }

        // Seed every rule at the floor (clamped by its own cap, since a scarce rule
        // cannot supply more than ~0.7 of its dictionary cardinality), then water-fill
        // the remainder. Floor + a lower cap compress the spread from ~260x to <7x.
        $budgets = [];
        $remaining = $target;
        foreach ($types as $t) {
            $budgets[$t] = min($floor, $caps[$t]);
            $remaining -= $budgets[$t];
        }

        // Water-fill the remainder proportional to supply, respecting each rule's cap:
        // high-supply rules fill toward the cap, the rest is shared out by supply.
        $active = array_values(array_filter($types, static fn (string $t): bool => $budgets[$t] < $caps[$t]));
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
        // A pool rule may live under SpellingRules or GeometricRules (the
        // compound-triple showpiece is geometric), so look in both.
        foreach ([self::NS, 'Puzzlebook2\\Rules\\GeometricRules\\'] as $ns) {
            $const = $ns . $type . '::INCOMPATIBLE_WITH';
            if (defined($const)) {
                return constant($const);
            }
        }
        return [];
    }

    /**
     * The selectable pool, derived once: every concrete spelling rule (auto-
     * discovered from the SpellingRules directory, minus the reserved length
     * finals) plus the opt-in geometric pool rules. Each entry is
     * [family, supply, 'once'?] -- the exact shape the old hand-maintained POOL
     * constant had, but family comes from naming/curation and supply is computed
     * and cached, so adding a rule means dropping in the class (and, only for a
     * curated family, one FAMILY line) -- never hand-typing a cardinality.
     *
     * @return array<string,array{0:string,1:int,2?:string}>
     */
    private static function pool(): array
    {
        if (self::$poolCache !== null) {
            return self::$poolCache;
        }
        $finals = array_fill_keys(self::FINAL_TYPES, true);
        $out = [];
        foreach (glob(dirname(__DIR__) . '/Rules/SpellingRules/*.php') ?: [] as $file) {
            $type = basename($file, '.php');
            if (isset($finals[$type])) {
                continue;
            }
            $class = self::NS . $type;
            if (!class_exists($class)) {
                continue;
            }
            $rc = new \ReflectionClass($class);
            if (!$rc->isInstantiable() || !$rc->isSubclassOf(SpellingRuleBase::class)) {
                continue;
            }
            $entry = [self::familyOf($type), self::supplyFor($type)];
            if (self::isOnce($type)) {
                $entry[] = 'once';
            }
            $out[$type] = $entry;
        }
        foreach (self::GEOMETRIC_POOL as $type => [$family, $supply]) {
            $entry = [$family, $supply];
            if (self::isOnce($type)) {
                $entry[] = 'once';
            }
            $out[$type] = $entry;
        }
        return self::$poolCache = $out;
    }

    /** Family from the class name where it announces one, else the curated map; unknown is a loud error. */
    private static function familyOf(string $type): string
    {
        if (str_starts_with($type, 'Contains')) {
            return 'substring';
        }
        if (str_starts_with($type, 'Boggle')) {
            return 'boggle';
        }
        if (str_starts_with($type, 'Crossword')) {
            return 'crossword';
        }
        if (isset(self::FAMILY[$type])) {
            return self::FAMILY[$type];
        }
        if (isset(self::GEOMETRIC_POOL[$type])) {
            return self::GEOMETRIC_POOL[$type][0];
        }
        throw new \LogicException(
            "Pool rule '{$type}' has no family: name it Contains*/Boggle*/Crossword*, or add it to ChainVariant::FAMILY."
        );
    }

    private static function isOnce(string $type): bool
    {
        return str_starts_with($type, 'Boggle') || in_array($type, self::SINGLE_USE, true);
    }

    /**
     * How many dictionary words this rule crosses out -- the supply weight the
     * cascade orders and budgets by. Computed for spelling rules (and disk-cached
     * via RuleIndexCache, keyed on the rule's own code and the word list, so it is
     * recomputed only when one of those changes); declared for geometric pool
     * rules, whose "supply" is not a word-by-word cardinality.
     */
    private static function supplyFor(string $type): int
    {
        if (isset(self::GEOMETRIC_POOL[$type])) {
            return self::GEOMETRIC_POOL[$type][1];
        }
        $class = self::NS . $type;
        /** @var SpellingRuleBase $rule */
        $rule = new $class();
        $words = self::wordList();
        $cached = RuleIndexCache::remember(
            $class,
            'supply',
            self::wordListHash(),
            static function () use ($rule, $words): array {
                $n = 0;
                foreach ($words as $w) {
                    if ($rule->failsWord($w)) {
                        $n++;
                    }
                }
                return ['n' => $n];
            },
        );
        return (int) ($cached['n'] ?? 0);
    }

    /** @return string[] */
    private static function wordList(): array
    {
        if (self::$wordList === null) {
            $path = dirname(__DIR__, 2) . '/data/wordlist.txt';
            $raw = is_file($path) ? (string) file_get_contents($path) : '';
            self::$wordList = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn (string $w): bool => $w !== ''));
            self::$wordListHash = hash('xxh3', implode("\n", self::$wordList));
        }
        return self::$wordList;
    }

    private static function wordListHash(): string
    {
        self::wordList();
        return self::$wordListHash ?? '';
    }
}
