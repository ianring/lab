<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Construction\NoContainmentOnPage;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;
use Puzzlebook\WordIndexCache;

/**
 * Cross out both words of any pair on the same page where one word appears
 * whole inside the other -- ROCK inside SPROCKET, CAT inside CATALOG.
 *
 * For the reader this prunes about as well as the reversal rule: you read a
 * word, run your eye over the handful of real words hiding in it, and almost
 * always find none. Only when something does turn up do you go looking for it
 * on the page.
 *
 * The four-letter floor is what keeps it sane. Short words hide everywhere --
 * THE, AND and ONE are inside hundreds of longer words -- so without one
 * almost every page would collapse. At four letters the find is rare enough
 * to be worth hunting for.
 *
 * CONSTRUCTION. Unlike the four signature-based pair rules, containment has
 * no canonical key: ROCK and SPROCKET share nothing to hash on, so accidental
 * pairs can only be caught by comparing against the words already on a page.
 * See NoContainmentOnPage.
 */
final class ContainedWordOnPageRule implements RuleInterface, ConstructsCells
{
    /**
     * Built once and kept. This is the most expensive index in the project --
     * enumerating the substrings of 133,000 words and checking each against the
     * dictionary -- and construct() runs once per build ATTEMPT. Rebuilding it every
     * time would make the build look like it had hung.
     *
     * @var array<string,bool>|null
     */
    private ?array $outerCache = null;

    private const MIN_LENGTH = 4;

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $cellsPerPage = $grid->rows * $grid->cols;

        for ($page = 0; $page < $grid->pages; $page++) {
            $start = $page * $cellsPerPage;

            $live = [];
            for ($offset = 0; $offset < $cellsPerPage; $offset++) {
                $cell = $start + $offset;
                if ($keep[$cell]) {
                    $live[$cell] = $words[$cell];
                }
            }

            foreach ($live as $cellA => $wordA) {
                foreach ($live as $cellB => $wordB) {
                    if ($cellA === $cellB || strlen($wordB) < self::MIN_LENGTH) {
                        continue;
                    }
                    if (strlen($wordB) >= strlen($wordA)) {
                        continue;
                    }
                    if (str_contains($wordA, $wordB)) {
                        $keep[$cellA] = false;
                        $keep[$cellB] = false;
                    }
                }
            }
        }

        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $pairsWanted = intdiv($context->budget(), 2);
        if ($pairsWanted <= 0) {
            return;
        }

        $grid = $canvas->grid();
        $cellsPerPage = $grid->rows * $grid->cols;
        $prefix = $context->spellingRulesBefore();
        $minLength = self::MIN_LENGTH;

        // Which words CAN be the outer half: those with a real dictionary word of at
        // least $minLength hiding inside them SOMEWHERE in the full word list. This
        // is only a pre-filter, not proof a pair is available -- the word that's
        // hiding inside might not survive every rule before this step, or might
        // already be claimed by another pair this attempt -- but it's prefix-
        // independent, so it's exactly what's worth the expense of caching: every
        // substring of every word enumerated once, kept on disk AND on this
        // instance, rather than rebuilt per attempt or per bin/check.php run.
        if ($this->outerCache === null) {
            $this->outerCache = WordIndexCache::remember(
                self::class,
                $this->describe(),
                $context->wordPoolHash(),
                static function () use ($context, $minLength): array {
                    $inPool = array_fill_keys($context->wordPool(), true);
                    $outer = [];
                    foreach ($context->wordPool() as $word) {
                        $len = strlen($word);
                        if ($len <= $minLength) {
                            continue;
                        }
                        for ($start = 0; $start <= $len - $minLength; $start++) {
                            for ($take = $minLength; $start + $take < $len; $take++) {
                                if (isset($inPool[substr($word, $start, $take)])) {
                                    $outer[$word] = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    return $outer;
                }
            );
        }
        $outer = $this->outerCache;

        if ($outer === []) {
            throw new \RuntimeException(
                "Step {$context->stepId()} needs words with a shorter word hiding inside them, and the "
                . 'word list has none at this minimum length.'
            );
        }

        // Pick the ACTUAL PAIRS first, before any geometry is decided, and reserve
        // both halves immediately -- same fix, same reasoning, as
        // PageSignaturePairRule: a pair validated here is REAL, both words already
        // confirmed to survive the prefix and be unclaimed, nothing left to
        // discover (and possibly fail on) once fillWords() reaches the follower.
        $survives = array_flip($context->survivorsSoFar());
        $outerCandidates = [];
        foreach ($context->survivorsSoFar() as $word) {
            if (isset($outer[$word])) {
                $outerCandidates[] = $word;
            }
        }
        $outerCandidates = Rng::shuffle($outerCandidates, $context->seed());

        $pairs = [];
        foreach ($outerCandidates as $host) {
            if (count($pairs) >= $pairsWanted) {
                break;
            }
            $inner = $this->findAvailableInnerWord($host, $survives, $canvas, $minLength);
            if ($inner === null) {
                continue;
            }
            $canvas->reserveWord($host);
            $canvas->reserveWord($inner);
            $pairs[] = [$host, $inner];
        }

        if (count($pairs) < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs of words, one hiding inside the "
                . 'other, but only ' . count($pairs) . ' are currently available (surviving the rules so '
                . 'far, not already claimed by another pair this attempt)'
            );
        }

        // Each pair's page is chosen INDEPENDENTLY and at random -- NoContainmentOnPage's
        // exemptions are per CELL, not per page, so there's no reason to prefer packing
        // one page before moving to the next. A fresh shuffle per pair (seeded off both
        // the step and which pair this is) means pair N's page doesn't depend on where
        // pairs 1..N-1 landed, which is what actually scatters them through the book
        // instead of clustering into whichever pages a single shared order visits first.
        $shortClass = substr(strrchr(static::class, '\\'), 1);
        $exempt = [];
        $placed = 0;
        while ($placed < $pairsWanted) {
            $pageOrder = Rng::shuffle(range(0, $grid->pages - 1), $context->seed() + $placed);
            $free = null;
            foreach ($pageOrder as $page) {
                // Column 0 first: a cell with no left neighbour at all can never
                // accidentally collide with one, which is the dominant risk for a
                // predetermined word -- it has no flexibility to try something else
                // the way ordinary filler does when a candidate would collide. Column
                // 0 isn't always free, so this still falls back to any free cell.
                $candidate = [];
                for ($row = 0; $row < $grid->rows && count($candidate) < 2; $row++) {
                    $cell = $page * $cellsPerPage + $row * $grid->cols;
                    if ($canvas->isFree($cell)) {
                        $candidate[] = $cell;
                    }
                }
                for ($offset = 0; $offset < $cellsPerPage && count($candidate) < 2; $offset++) {
                    $cell = $page * $cellsPerPage + $offset;
                    if ($canvas->isFree($cell) && !in_array($cell, $candidate, true)) {
                        $candidate[] = $cell;
                    }
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

            [$host, $inner] = $pairs[$placed];
            [$leader, $follower] = $free;

            $canvas->claimDoomed(
                $leader,
                $context->stepId(),
                CellPlan::literal(
                    $host,
                    $prefix,
                    'holderA' . $context->stepId() . '-' . $leader,
                    "step {$context->stepId()} [{$shortClass}]: {$this->describe()} (the outer word)"
                )
            );

            $canvas->claimDoomed(
                $follower,
                $context->stepId(),
                CellPlan::literal(
                    $inner,
                    $prefix,
                    'holderB' . $context->stepId() . '-' . $follower,
                    "step {$context->stepId()} [{$shortClass}]: {$this->describe()} (the word hiding inside cell {$leader})"
                )
            );

            $exempt[$leader] = true;
            $exempt[$follower] = true;
            // Deliberately NOT exempt from other rules' invariants -- e.g. the inner
            // word's own length coincidentally matching length_by_page's target for
            // whatever page it lands on. That's pure chance, not certainty: a
            // different seed picks different words and a different page, so it
            // should retry rather than be silently let through (see the matching
            // fix and its rationale in PageSignaturePairRule::construct()).
            $placed++;
        }

        if ($placed < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs' worth of free cells across the "
                . "book, but only found room for {$placed}"
            );
        }

        $canvas->addInvariant(new NoContainmentOnPage($minLength, $cellsPerPage, $exempt));
    }

    /**
     * The first word in $survives, not already reserved, that's a genuine substring
     * of $host at least $minLength long -- or null if nothing qualifies. Mirrors the
     * enumeration that built $outerCache, but checked against the PREFIX-survivor
     * pool and this attempt's reservations rather than the whole dictionary, since
     * $outerCache only promises something hides inside $host somewhere in the full
     * word list, not that it's still available right now.
     *
     * @param array<string,int> $survives word => anything, used only as a hash set
     */
    private function findAvailableInnerWord(string $host, array $survives, Canvas $canvas, int $minLength): ?string
    {
        $len = strlen($host);
        for ($start = 0; $start <= $len - $minLength; $start++) {
            for ($take = $minLength; $start + $take < $len; $take++) {
                $candidate = substr($host, $start, $take);
                if (isset($survives[$candidate]) && !$canvas->isWordReserved($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    public function constructionPriority(): int
    {
        // Two free cells on one page, like the other pair rules.
        return 45;
    }

    public function describe(): string
    {
        return 'pairs on the same page where one word hides inside the other (' . self::MIN_LENGTH . '+ letters)';
    }
}
