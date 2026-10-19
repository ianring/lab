<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Construction\UniquePageSignature;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;
use Puzzlebook\WordIndexCache;
use Puzzlebook\WordTags;

/**
 * "Find two words on the same page that share X, and cross out both."
 *
 * A whole family of rules is this shape, differing only in what X is:
 *
 *   anagrams of each other        the letters, sorted
 *   same first three letters      the first three letters
 *   same vowels in the same order the vowels, in order
 *   one is the other reversed     the word and its reversal, canonicalised
 *
 * A subclass supplies signatureOf() and describe(); everything else -- how the
 * rule is applied, how its pairs are placed, and how accidents are prevented
 * -- is handled once, here. Returning null from signatureOf() means the word
 * can't take part at all (too short to have a three-letter prefix, say).
 *
 * THE HARD PART IS ACCIDENTS, NOT PAIRS. With 120 words on a page,
 * coincidences are the norm rather than the exception -- English clusters
 * heavily on prefixes like "con" and "pre", so a page of random words throws
 * up unplanned matches constantly. Every one of them would cross out words
 * the build was relying on. So construct() registers a UniquePageSignature
 * invariant: each signature may appear once per page, except for the pairs
 * this rule planted deliberately. The planted pair is then the only match on
 * its page, and the rule removes exactly what it meant to.
 *
 * Both halves must still be standing for the pair to fire, and the rule is
 * applied in reading order. Because the planted pairs don't share cells with
 * each other, order never changes the outcome.
 */
abstract class PageSignaturePairRule implements RuleInterface, ConstructsCells
{
    /**
     * A ceiling on how many pairs any subclass ever places, scaled to stay
     * proportional to the book's page count -- see
     * MatchingNeighbourEndsRule::MAX_PAIRS_PER_PAGE for the full reasoning.
     * keep_fraction's own budget grows faster than this (it's a fraction of the
     * whole remaining pool), and each pair is a committed literal with no
     * fallback if it collides with something, so letting pair density rise with
     * book size means more expensive full-refill local retries as the book grows.
     */
    private const MAX_PAIRS_PER_PAGE = 1.2;

    /**
     * Built once and kept. Scanning 133,000 words and computing a signature for each
     * is not cheap -- sorting the letters of every word, for the anagram rule -- and
     * construct() runs once per build ATTEMPT, of which there can be hundreds. Without
     * this the index would be rebuilt every time and the build would appear to hang.
     * The rule object outlives the attempts, so an instance field is the right scope.
     *
     * @var array<string,string[]>|null signature => every word with it (2+ only)
     */
    private ?array $groupsCache = null;

    /** What two words must have in common to be a pair; null if the word can't take part. */
    abstract protected function signatureOf(string $word): ?string;

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $cellsPerPage = $grid->rows * $grid->cols;

        for ($page = 0; $page < $grid->pages; $page++) {
            $start = $page * $cellsPerPage;
            $bySignature = [];

            for ($offset = 0; $offset < $cellsPerPage; $offset++) {
                $cell = $start + $offset;
                if (!$keep[$cell]) {
                    continue;
                }
                $signature = $this->signatureOf($words[$cell]);
                if ($signature !== null) {
                    $bySignature[$signature][] = $cell;
                }
            }

            foreach ($bySignature as $cells) {
                if (count($cells) < 2) {
                    continue;
                }
                foreach ($cells as $cell) {
                    $keep[$cell] = false;
                }
            }
        }

        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $grid = $canvas->grid();
        $maxPairs = (int) round($grid->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = min(intdiv($context->budget(), 2), $maxPairs);
        if ($pairsWanted <= 0) {
            return;
        }

        $prefix = $context->spellingRulesBefore();

        // Every word sharing a signature with at least one other word, grouped by
        // that signature -- cached once (not per attempt): the word list and this
        // rule's OWN parameters are all this depends on, never anything an attempt
        // decides.
        $groups = $this->signatureGroups($context);
        if ($groups === []) {
            throw new \RuntimeException(
                "Step {$context->stepId()} needs pairs of words sharing a signature, but the word list "
                . "doesn't contain a single such pair. Loosen what counts as a match."
            );
        }

        // Pick the ACTUAL PAIRS first, before any geometry is decided, and reserve
        // both halves immediately. This is the fix for the failure mode that used to
        // dominate retries: picking a LEADER whose shape is just "has a partner
        // somewhere" only promises a follower EXISTS, not that it SURVIVED every
        // earlier rule, isn't already used by some other pair, or passes this
        // specific page's invariants -- any of which could fail only once fillWords()
        // reached the follower's cell, discarding whatever work the rest of the
        // attempt had already done. Validating both halves together, right here,
        // means a pair that makes it this far is REAL: both words already confirmed
        // to survive the prefix and be unclaimed, nothing left to discover later.
        $survives = array_flip($context->survivorsSoFar());
        $signatures = Rng::shuffle(array_keys($groups), $context->seed());

        $pairs = [];
        foreach ($signatures as $signature) {
            if (count($pairs) >= $pairsWanted) {
                break;
            }
            $available = [];
            foreach ($groups[$signature] as $word) {
                if (isset($survives[$word]) && !$canvas->isWordReserved($word)) {
                    $available[] = $word;
                }
            }
            if (count($available) < 2) {
                continue;
            }
            $available = Rng::shuffle($available, $context->seed());
            [$wordA, $wordB] = [$available[0], $available[1]];
            $canvas->reserveWord($wordA);
            $canvas->reserveWord($wordB);
            $pairs[] = [$wordA, $wordB];
        }

        if (count($pairs) < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs of words sharing a signature, but "
                . 'only ' . count($pairs) . ' are currently available (surviving the rules so far, not '
                . 'already claimed by another pair this attempt)'
            );
        }

        // Now find somewhere to put the pairs already in hand: two free cells per
        // pair, anywhere in the book. Each pair's page is chosen INDEPENDENTLY and at
        // random -- UniquePageSignature tracks exemptions per (page, signature), not
        // one exemption per page, so there's no reason to prefer packing one page
        // before moving to the next. A fresh shuffle per pair (seeded off both the
        // step and which pair this is) means pair N's page doesn't depend on where
        // pairs 1..N-1 landed, which is what actually scatters them through the book
        // instead of clustering into whichever pages a single shared order visits
        // first.
        $cellsPerPage = $grid->rows * $grid->cols;

        $exempt = [];
        $placed = 0;
        while ($placed < $pairsWanted) {
            [$wordA, $wordB] = $pairs[$placed];

            $pageOrder = Rng::shuffle(range(0, $grid->pages - 1), $context->seed() + $placed);
            $free = null;
            foreach ($pageOrder as $page) {
                // Every free cell on the page, in a SHUFFLED order -- not column 0
                // first. That preference existed to dodge left-neighbour collisions
                // before this rule had a real check for them; now that
                // hasOuterNeighbourCollision() below checks every candidate
                // regardless of column, the preference was pure liability: it
                // always tried column 0 top-to-bottom, so row 1's column-0 cell was
                // always the second candidate on every page -- right below the
                // corner CornerContainsLetterRule disproportionately fills with
                // 'e'-containing words, systematically clustering risk there
                // instead of spreading it randomly across the page.
                $cellsOnPage = Rng::shuffle(
                    range($page * $cellsPerPage, $page * $cellsPerPage + $cellsPerPage - 1),
                    $context->seed() + $placed + $page
                );
                $candidate = [];
                foreach ($cellsOnPage as $cell) {
                    if (count($candidate) >= 2) {
                        break;
                    }
                    if (!$canvas->isFree($cell)) {
                        continue;
                    }
                    // Checked against whichever of this pair's two words would
                    // land there (wordA for the first slot found, wordB for the
                    // second). Canvas::wordSatisfiesInvariants() covers every
                    // cross-cutting constraint ANY rule has registered (corners,
                    // opposing lengths, whatever a future rule invents) -- this
                    // rule knows nothing about any of them, it just asks. The
                    // matching-ends collision check stays separate since there's
                    // no invariant for it yet.
                    $word = $candidate === [] ? $wordA : $wordB;
                    if (!$canvas->wordSatisfiesInvariants($word, $cell)
                        || $this->hasOuterNeighbourCollision($canvas, $grid, $cell, $word)) {
                        continue;
                    }
                    $candidate[] = $cell;
                }
                if (count($candidate) === 2) {
                    $free = $candidate;
                    break;
                }
            }
            if ($free === null) {
                // Not one page in the whole book currently has two free cells left.
                break;
            }

            [$cellA, $cellB] = $free;

            $canvas->claimDoomed(
                $cellA,
                $context->stepId(),
                CellPlan::literal(
                    $wordA,
                    $prefix,
                    'pairA' . $context->stepId() . '-' . $cellA,
                    "step {$context->stepId()} [" . substr(strrchr(static::class, '\\'), 1)
                    . "]: {$this->describe()} (first word of the pair)"
                )
            );
            $canvas->claimDoomed(
                $cellB,
                $context->stepId(),
                CellPlan::literal(
                    $wordB,
                    $prefix,
                    'pairB' . $context->stepId() . '-' . $cellB,
                    "step {$context->stepId()} [" . substr(strrchr(static::class, '\\'), 1)
                    . "]: {$this->describe()} (second word of the pair)"
                )
            );

            $exempt[$cellA] = true;
            $exempt[$cellB] = true;
            // Deliberately NOT exempt from other rules' invariants (see
            // Canvas::exemptFromInvariants()'s docblock for when that IS right -- a
            // structural certainty like reversal-vs-anagram). A different page-pair
            // rule's word accidentally also matching THIS rule's signature is pure
            // chance, not certainty: a different seed can avoid it, so it should
            // retry, not be silently let through. Blanket-exempting this was exactly
            // what let an AnagramPairOnPageRule word get killed early by an
            // accidental SameVowelsOnPageRule match, orphaning its anagram partner.
            $placed++;
        }

        if ($placed < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs' worth of free cells across the "
                . "book, but only found room for {$placed}"
            );
        }

        // Nothing else anywhere may duplicate a signature on its page.
        $canvas->addInvariant(new UniquePageSignature(
            fn (string $word): ?string => $this->signatureOf($word),
            $this->describe(),
            $exempt
        ));
    }

    /**
     * True if placing $word at $cell would create an UNPLANNED matching-ends
     * pair with whatever is already pinned just outside either side -- the
     * cell to its left, or the cell to its right. Same reasoning and pattern as
     * MatchingNeighbourEndsRule::hasOuterNeighbourCollision(): only an
     * ALREADY-placed literal can be checked at all, and this rule's own two
     * words aren't chosen for their endpoints, so there's no "wanted" match to
     * exclude the way MatchingNeighbourEndsRule has to.
     */
    private function hasOuterNeighbourCollision(Canvas $canvas, Grid $grid, int $cell, string $word): bool
    {
        [, , $col] = $grid->coordsOf($cell);
        if ($col > 0) {
            $leftWord = $canvas->planFor($cell - 1)?->literalWord();
            if ($leftWord !== null && $word[0] === WordTags::lastLetter($leftWord)) {
                return true;
            }
        }
        if ($col < $grid->cols - 1) {
            $rightWord = $canvas->planFor($cell + 1)?->literalWord();
            if ($rightWord !== null && WordTags::lastLetter($word) === $rightWord[0]) {
                return true;
            }
        }
        return false;
    }

    public function constructionPriority(): int
    {
        // Needs two free cells on one page -- easy to satisfy, but it must choose
        // before the take-anything spelling rules empty the pages.
        return 45;
    }

    /**
     * Every word sharing a signature with at least one other word, grouped by that
     * signature. Groups of exactly one word are dropped -- a signature nothing else
     * shares can never be matched, so there's no reason to carry it forward.
     *
     * @return array<string,string[]>
     */
    private function signatureGroups(ConstructionContext $context): array
    {
        if ($this->groupsCache !== null) {
            return $this->groupsCache;
        }

        // describe() already renders this rule's own parameters into its text (e.g.
        // "4+ letters"), so class name + describe() uniquely identifies both WHICH
        // rule this is and WHAT it was configured with -- everything the result below
        // depends on besides the word list itself, which the hash covers.
        return $this->groupsCache = WordIndexCache::remember(
            static::class,
            $this->describe(),
            $context->wordPoolHash(),
            function () use ($context): array {
                $groups = [];
                foreach ($context->wordPool() as $word) {
                    $signature = $this->signatureOf($word);
                    if ($signature !== null) {
                        $groups[$signature][] = $word;
                    }
                }
                return array_filter($groups, static fn (array $words): bool => count($words) > 1);
            }
        );
    }
}
