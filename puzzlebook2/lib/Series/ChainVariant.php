<?php

declare(strict_types=1);

namespace Puzzlebook2\Series;

/**
 * Builds each book's elimination chain out of one shared pool, so no two books
 * are the same puzzle -- while keeping the Capstone's diagonal valid.
 *
 * Shape of a NORMAL book (books 1-20) -- about 35 printed rules:
 *   positions 1-7    fixed SPINE   -- the scarce/structural rules, always first,
 *                                     always in this order (they need a near-full
 *                                     pool, so they can't move late)
 *   positions 8-22   FLAVOR (15)   -- low-cardinality substring cards, in FIXED
 *                                     ascending-supply order (see note below).
 *                                     These are what "more rules, smaller bites"
 *                                     buys: fifteen surgical cuts of ~130-200
 *                                     cells each, so the chunky rules downstream
 *                                     shrink and the per-rule cardinality drops.
 *   positions 23-33  MIDDLE (11)   -- the same eleven rules in every book, but
 *                                     CYCLICALLY ROTATED by the book number, so
 *                                     each book eliminates in a different order
 *   position  34     VowelRun      -- the absorber (last keep_fraction), fixed
 *   position  35     WordLength7   -- the final sweep, fixed
 *   (an unprinted DecoyLength7 sits between 34 and 35)
 *
 * The CAPSTONE (book 21) is DELIBERATELY leaner -- no flavor tier, just the
 * 20-rule diagonal (SPINE + MIDDLE-in-diagonal-order + absorber + final). That
 * keeps the "20 books, 20 clues" diagonal exactly as elegant as before, and its
 * rules are the chunky ones on purpose. Because the Capstone's spelling rules
 * are a SUBSET of every normal book's, any answer that survives a normal book
 * also survives the Capstone -- so the shared survivor set the builder draws
 * answers from stays valid.
 *
 * Why the flavor tier keeps the Capstone sound: the diagonal borrows Rule N
 * from Book N, and the Capstone is assembled directly from SPINE + MIDDLE
 * (forCapstone), so the flavor cards -- which never appear in the Capstone --
 * cannot touch the diagonal's distinctness. Every rule the Capstone names still
 * appears in the book it is borrowed from (all 20 live in every normal book).
 *
 * Why the rotation keeps the diagonal distinct: positions in SPINE / absorber /
 * final are the same rule in every book, so along the diagonal they contribute
 * distinct types (one per position). The middle is 11 positions rotated by the
 * book number; because 11 is coprime with the rotation step, Book N's rule at
 * its diagonal position walks all 11 middle rules exactly once -- so the whole
 * diagonal is 20 DISTINCT rules, which is what ChainPlan requires.
 *
 * FLAVOR ORDER IS LOAD-BEARING. The cards are listed by ascending dictionary
 * supply on purpose. If card A's needle is a substring of card B's needle (e.g.
 * 'gh' inside 'ough'), then A matches a superset of B, so A is more abundant and
 * sorts LATER. Running the scarcer card first therefore guarantees no card ever
 * empties a later card's pool. Keep this list sorted by supply, scarcest first.
 *
 * keep_fractions are seed values; AutoTuner rebalances each book's chain for its
 * own order before it is built ("the software does the intelligent work").
 */
final class ChainVariant
{
    /** @var array<int,array{type:string,kf:float}> positions 1-7, fixed */
    private const SPINE = [
        ['type' => 'PalindromeRule', 'kf' => 0.9957],
        ['type' => 'UsesOnlyLettersRule', 'kf' => 0.9897],
        ['type' => 'CornerContainsLetterRule', 'kf' => 0.98298],
        ['type' => 'AlphabetAdjacentEndsRule', 'kf' => 0.966],
        ['type' => 'RepeatedLetterRule', 'kf' => 0.9909],
        ['type' => 'AlphabeticalOrderRule', 'kf' => 0.983],
        ['type' => 'LetterCountRule', 'kf' => 0.95002],
    ];

    /**
     * Low-cardinality substring cards, FIXED ascending-supply order (see the
     * class note: this order is what makes the family collision-free). Seed
     * keep_fractions were computed to take ~130-200 cells each against the pool
     * remaining after the spine (~10,800), every target comfortably under the
     * card's dictionary supply. The scarcest card (ough, supply 179) takes 134.
     *
     * None of these is subsumed by a rule elsewhere in the book, so each adds a
     * genuinely new cut -- NOT tch/nch/sch (all subsets of the middle's ch) nor
     * gh (a superset of ough/ght). bin/compute-incompatibilities.php is the
     * authority; the per-rule INCOMPATIBLE_WITH consts reflect it.
     *
     * @var array<int,array{type:string,kf:float}>
     */
    private const FLAVOR = [
        ['type' => 'ContainsOughRule', 'kf' => 0.98762], // supply  179
        ['type' => 'ContainsKnRule',   'kf' => 0.98138], // supply  324
        ['type' => 'ContainsWrRule',   'kf' => 0.98094], // supply  333
        ['type' => 'ContainsDgeRule',  'kf' => 0.98057], // supply  334
        ['type' => 'ContainsSqRule',   'kf' => 0.98018], // supply  349
        ['type' => 'ContainsThrRule',  'kf' => 0.97978], // supply  419
        ['type' => 'ContainsGhtRule',  'kf' => 0.97936], // supply  576
        ['type' => 'ContainsRhRule',   'kf' => 0.97893], // supply  596
        ['type' => 'ContainsSwRule',   'kf' => 0.97847], // supply  620
        ['type' => 'ContainsWhRule',   'kf' => 0.97800], // supply  729
        ['type' => 'ContainsOeRule',   'kf' => 0.97751], // supply  751
        ['type' => 'ContainsSkRule',   'kf' => 0.97699], // supply  776
        ['type' => 'ContainsFrRule',   'kf' => 0.97645], // supply  897
        ['type' => 'ContainsNkRule',   'kf' => 0.97588], // supply 1014
        ['type' => 'ContainsSlRule',   'kf' => 0.97528], // supply 1170
    ];

    /** @var array<int,array{type:string,kf:float}> the 11 rotated middle rules */
    private const MIDDLE = [
        ['type' => 'ConsonantRunRule', 'kf' => 0.9215],
        ['type' => 'EndsWithIngRule', 'kf' => 0.90894],
        ['type' => 'ContainsZRule', 'kf' => 0.90583],
        ['type' => 'DoubleEFewVowelsRule', 'kf' => 0.91838],
        ['type' => 'BothEndPairsInSetRule', 'kf' => 0.8868],
        ['type' => 'StartsWithTwoVowelsRule', 'kf' => 0.91771],
        ['type' => 'ContainsChRule', 'kf' => 0.85203],
        ['type' => 'FourConsonantsOneVowelRule', 'kf' => 0.8299],
        ['type' => 'WordLength10Rule', 'kf' => 0.80219],
        ['type' => 'AlternatingConsonantVowelRule', 'kf' => 0.79014],
        ['type' => 'DoubledConsonantRule', 'kf' => 0.62639],
    ];

    private const ABSORBER = ['type' => 'VowelRunRule', 'kf' => 0.03459];

    public static function middleCount(): int
    {
        return count(self::MIDDLE);
    }

    public static function flavorCount(): int
    {
        return count(self::FLAVOR);
    }

    public static function spineCount(): int
    {
        return count(self::SPINE);
    }

    /** A normal book: spine, the flavor tier, then the middle rotated so book N starts the cycle at offset N. */
    public static function forBook(int $bookNo, string $answer): array
    {
        $order = [];
        $n = count(self::MIDDLE);
        for ($q = 0; $q < $n; $q++) {
            $order[] = self::MIDDLE[($q + $bookNo) % $n];
        }
        return self::assemble($order, $answer, true);
    }

    /**
     * The Capstone: no flavor tier (kept lean and chunky on purpose). Its middle
     * is the diagonal -- Rule (8+q) is Book (8+q)'s rule at position 8+q, i.e.
     * MIDDLE[(2q + 8) % n] for q = 0..n-1, a permutation of the middle (11
     * coprime with 2), so the whole diagonal is distinct. Reproduced here
     * directly so the Capstone's printed chain IS the borrowed diagonal.
     */
    public static function forCapstone(string $answer): array
    {
        $order = [];
        $n = count(self::MIDDLE);
        for ($q = 0; $q < $n; $q++) {
            $order[] = self::MIDDLE[(2 * $q + 8) % $n];
        }
        return self::assemble($order, $answer, false);
    }

    /** @param array<int,array{type:string,kf:float}> $middleOrder */
    private static function assemble(array $middleOrder, string $answer, bool $withFlavor): array
    {
        $steps = [];
        foreach (self::SPINE as $r) {
            $steps[] = self::eliminate($r['type'], $r['kf']);
        }
        if ($withFlavor) {
            foreach (self::FLAVOR as $r) {
                $steps[] = self::eliminate($r['type'], $r['kf']);
            }
        }
        foreach ($middleOrder as $r) {
            $steps[] = self::eliminate($r['type'], $r['kf']);
        }
        $steps[] = self::eliminate(self::ABSORBER['type'], self::ABSORBER['kf']);
        $steps[] = [
            'kind' => 'eliminate',
            'printed' => false,
            'rule' => ['type' => 'DecoyLength7Rule'],
            'construction' => ['derived' => true],
        ];
        $steps[] = [
            'kind' => 'eliminate',
            'printed' => true,
            'final' => true,
            'rule' => ['type' => 'WordLength7Rule'],
        ];

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
}
