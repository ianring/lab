<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;
use Puzzlebook\WordIndexCache;
use Puzzlebook\WordTags;

/**
 * Cross out every horizontally adjacent pair of STILL-SURVIVING words, in
 * the same row, where the left word's last letter equals the right word's
 * first letter -- disqualifying both words of the pair.
 *
 * BOTH HALVES MUST STILL BE STANDING. An earlier version read whatever was
 * printed, crossed out or not, which quietly assumed the reader marks up the
 * book with something translucent. Plenty of people will reach for a marker
 * instead, and then an eliminated word is simply gone -- so a rule that
 * needs its letters is unsolvable. No rule in this book requires reading a
 * word that has already been crossed out.
 *
 * Reading from a snapshot matters here, not just for tidiness: the two halves
 * have to count each other as surviving simultaneously. Checking live state
 * mid-pass would let the left word's removal stop the right one from being
 * removed, making the outcome depend on scan order.
 *
 * CONSTRUCTION. Picks genuine PAIRS of real words up front -- same fix, same
 * reasoning as PageSignaturePairRule: a left word whose last letter equals a
 * right word's first letter, BOTH confirmed to survive the prefix and be
 * unclaimed, before any cell geometry is decided. An earlier version instead
 * resolved the right half at FILL time from whatever the left cell turned out
 * to hold (CellPlan::matchingRightHalf()) -- that trusted the dictionary to
 * find a partner when it got there rather than proving one exists, and (with
 * several OTHER pair rules now active at once) that trust turned out to be
 * exactly where an orphaned half could slip through. Both halves are now
 * placed as literal words, which also means both get the normal invariant
 * protection every literal cell gets -- an accidental collision with another
 * rule's pair now retries instead of silently succeeding.
 */
final class MatchingNeighbourEndsRule implements RuleInterface, ConstructsCells
{
    /**
     * Letters a deliberate pair may join on -- i.e. letters that enough English words
     * both end with and begin with for the right half to be findable. Excludes
     * j, k, q, u, v, x, y and z: all of them are scarce as initials, and a pair that
     * joined on one would need a right-hand word from a pool of a few dozen.
     */
    private const USABLE_JOIN_LETTERS = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'w',
    ];

    /**
     * A ceiling on how many pairs this rule ever places, scaled to stay
     * PROPORTIONAL to the book's page count rather than fixed. keep_fraction's
     * own budget also scales with the book, but faster than this -- it's a
     * fraction of the whole remaining pool, so it roughly triples from 30 pages
     * to 100. Each pair is a committed literal with no fallback if its right
     * neighbour collides, so the odds of AT LEAST ONE colliding rise with the
     * pair count too -- and more collisions means more expensive local retries
     * (a full refill, since fillWords() has no checkpoints of its own), which is
     * what turned a working 30-page build into a 100-page build that didn't
     * converge in two hours. Scaling the TOTAL count by page count keeps the
     * collision density -- and so the retry cost -- roughly constant as the book
     * grows, instead of worsening with it. Anything keep_fraction asks for
     * beyond this falls through to the leftover-cell absorber, which has
     * genuine fallback.
     *
     * This is NOT a per-page quota -- it only sets how many pairs exist in
     * total. WHERE they land stays fully random across the whole book:
     * freeHorizontalPairs() builds its candidate list across every page before
     * shuffling it once, so consumption order has no notion of "page" at all.
     * Some pages may get several pairs, others none.
     */
    private const MAX_PAIRS_PER_PAGE = 1.2;

    /** @var array<string,string[]>|null first letter => every word starting with it */
    private ?array $byFirstLetterCache = null;

    public function apply(array $words, Grid $grid, array $keep): array
    {
        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                for ($col = 0; $col < $grid->cols - 1; $col++) {
                    $left = $grid->linearIndex($page, $row, $col);
                    $right = $grid->linearIndex($page, $row, $col + 1);
                    if (!$keep[$left] || !$keep[$right]) {
                        continue;
                    }
                    if (WordTags::lastLetter($words[$left]) === WordTags::firstLetter($words[$right])) {
                        $keep[$left] = false;
                        $keep[$right] = false;
                    }
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        // This rule always takes two cells at a time, so an odd budget can't be met
        // exactly; the builder accounts for that when it works out the pacing.
        $maxPairs = (int) round($canvas->grid()->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = min(intdiv($context->budget(), 2), $maxPairs);
        if ($pairsWanted <= 0) {
            return;
        }

        $byFirstLetter = $this->firstLetterGroups($context);
        $survives = array_flip($context->survivorsSoFar());
        $leftCandidates = Rng::shuffle($context->survivorsSoFar(), $context->seed());

        $pairs = [];
        foreach ($leftCandidates as $left) {
            if (count($pairs) >= $pairsWanted) {
                break;
            }
            if ($canvas->isWordReserved($left)) {
                continue;
            }
            $joinLetter = WordTags::lastLetter($left);
            if (!in_array($joinLetter, self::USABLE_JOIN_LETTERS, true)) {
                continue;
            }
            $right = null;
            foreach ($byFirstLetter[$joinLetter] ?? [] as $candidate) {
                if ($candidate === $left || !isset($survives[$candidate]) || $canvas->isWordReserved($candidate)) {
                    continue;
                }
                $right = $candidate;
                break;
            }
            if ($right === null) {
                continue;
            }
            $canvas->reserveWord($left);
            $canvas->reserveWord($right);
            $pairs[] = [$left, $right];
        }

        if (count($pairs) < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs of words where one ends on the "
                . 'letter the other starts with, but only ' . count($pairs) . ' are currently available '
                . '(surviving the rules so far, not already claimed by another pair this attempt)'
            );
        }

        $candidates = $canvas->freeHorizontalPairs($context->seed());

        // freeHorizontalPairs() only guarantees the two SLOT cells are free -- it
        // doesn't know, or check, what's already pinned in the cell just outside
        // either end. Two of THIS rule's own pairs can land back-to-back in the
        // same row (its slots are column-aligned 0-1, 2-3, 4-5), or this rule's
        // pair can land next to an earlier-constructed rule's pair -- either way,
        // nobody notices until fillWords() tries to place the word and finds an
        // unplanned match already locked in next door. That used to surface only
        // at fill time, which (with local retry) means redoing the ENTIRE fill
        // pass just to retry one pair -- the single most expensive way to recover
        // from something checkable right here, for free, before any cell is
        // claimed. Checking literalWord() (not staticFirstLetter()) covers both
        // this rule's own already-placed pairs and any other rule's: ordinary
        // filler cells have no literal word yet, so they can't be checked and
        // correctly don't block anything.
        $grid = $canvas->grid();
        $prefix = $context->spellingRulesBefore();
        $placed = 0;
        $candidateIndex = 0;
        for ($n = 0; $n < $pairsWanted; $n++) {
            [$wordLeft, $wordRight] = $pairs[$n];

            $left = $right = null;
            while ($candidateIndex < count($candidates)) {
                [$candidateLeft, $candidateRight] = $candidates[$candidateIndex];
                $candidateIndex++;
                // One generic check covers every cross-cutting constraint ANY
                // rule has registered (corners, opposing lengths, whatever a
                // future rule invents) -- this rule knows nothing about any of
                // them, it just asks. See Canvas::wordSatisfiesInvariants().
                if (!$canvas->wordSatisfiesInvariants($wordLeft, $candidateLeft)
                    || !$canvas->wordSatisfiesInvariants($wordRight, $candidateRight)) {
                    continue;
                }
                if (!$this->hasOuterNeighbourCollision($canvas, $grid, $candidateLeft, $candidateRight, $wordLeft, $wordRight)) {
                    $left = $candidateLeft;
                    $right = $candidateRight;
                    break;
                }
            }
            if ($left === null) {
                break;
            }
            $placed++;

            $canvas->claimDoomed(
                $left,
                $context->stepId(),
                CellPlan::literal(
                    $wordLeft,
                    $prefix,
                    'pairleft' . $context->stepId() . '-' . $left,
                    "step {$context->stepId()}: {$this->describe()} (left word)"
                )
            );
            // The right half WANTS to start on the left half's last letter -- that
            // collision is deliberate, so it needs allowsLeftMatch => true, same as
            // CellPlan::matchingRightHalf() always set. CellPlan::literal() always
            // sets it false, so this one is built directly instead of through that
            // factory.
            $canvas->claimDoomed(
                $right,
                $context->stepId(),
                new CellPlan(
                    $prefix,
                    static fn (string $candidate): bool => $candidate === $wordRight,
                    'pairright' . $context->stepId() . '-' . $right,
                    $wordRight,
                    $wordRight[0],
                    null,
                    true,
                    false,
                    "step {$context->stepId()}: {$this->describe()} (right word)"
                )
            );
        }

        if ($placed < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} side-by-side pairs of free cells with no "
                . 'outer-neighbour collision, but only found room for ' . $placed
            );
        }
    }

    /**
     * True if placing $wordLeft/$wordRight at $left/$right would create an
     * UNPLANNED matching-ends pair with whatever is already pinned just outside
     * either end -- the cell before $left, or the cell after $right. Only a cell
     * that already has a literal word (another rule's pair, or one of this
     * rule's own earlier pairs this same attempt) can be checked at all; ordinary
     * filler has no word yet, so it never blocks a placement here -- that risk is
     * exactly what fillWords()'s own neighbour-avoidance logic already handles
     * when it gets there.
     */
    private function hasOuterNeighbourCollision(
        Canvas $canvas,
        Grid $grid,
        int $left,
        int $right,
        string $wordLeft,
        string $wordRight
    ): bool {
        [, , $leftCol] = $grid->coordsOf($left);
        if ($leftCol > 0) {
            $outerWord = $canvas->planFor($left - 1)?->literalWord();
            if ($outerWord !== null && WordTags::lastLetter($outerWord) === $wordLeft[0]) {
                return true;
            }
        }
        [, , $rightCol] = $grid->coordsOf($right);
        if ($rightCol < $grid->cols - 1) {
            $outerWord = $canvas->planFor($right + 1)?->literalWord();
            if ($outerWord !== null && $outerWord[0] === WordTags::lastLetter($wordRight)) {
                return true;
            }
        }
        return false;
    }

    public function constructionPriority(): int
    {
        return 50;
    }

    public function describe(): string
    {
        return "side-by-side SURVIVING pairs where the left word ends on the right word's first letter";
    }

    /**
     * Every word in the dictionary, grouped by its first letter -- cached once (not
     * per attempt), the same reasoning as PageSignaturePairRule::signatureGroups().
     *
     * @return array<string,string[]>
     */
    private function firstLetterGroups(ConstructionContext $context): array
    {
        if ($this->byFirstLetterCache !== null) {
            return $this->byFirstLetterCache;
        }

        return $this->byFirstLetterCache = WordIndexCache::remember(
            self::class,
            $this->describe(),
            $context->wordPoolHash(),
            static function () use ($context): array {
                $groups = [];
                foreach ($context->wordPool() as $word) {
                    $groups[WordTags::firstLetter($word)][] = $word;
                }
                return $groups;
            }
        );
    }
}
