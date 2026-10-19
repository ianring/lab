<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\Construction\NoUnplannedVerticalEndingMatch;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;
use Puzzlebook\Rng;
use Puzzlebook\WordIndexCache;
use Puzzlebook\WordTags;

/**
 * Cross out every vertically adjacent pair of STILL-SURVIVING words, in the
 * same column, where both words end in the same letter -- disqualifying
 * both. The vertical sibling of MatchingNeighbourEndsRule.
 *
 * BOTH HALVES MUST STILL BE STANDING, read from a snapshot -- same reading
 * convention as every other pair rule in this book.
 *
 * CONSTRUCTION. Picks genuine PAIRS of real words sharing a last letter up
 * front -- same fix, same reasoning as PageSignaturePairRule and (as of the
 * same fix) MatchingNeighbourEndsRule: both words confirmed to survive the
 * prefix and be unclaimed, before any cell geometry is decided, then placed
 * as literal values so they get the normal invariant protection every
 * literal cell gets. Unlike MatchingNeighbourEndsRule's pair, this one needs
 * no allowsLeftMatch trick -- that mechanism only guards HORIZONTAL
 * neighbours, and this pair is vertical, so it's invisible to it either way.
 */
final class SameEndingVerticalPairRule implements RuleInterface, ConstructsCells
{
    /**
     * Letters common enough as word-ENDINGS that a second word sharing one is
     * findable. Excludes j, q, u, v, x, z as rare word-final letters.
     */
    private const USABLE_ENDING_LETTERS = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'k', 'l', 'm',
        'n', 'o', 'p', 'r', 's', 't', 'w', 'y',
    ];

    /**
     * A ceiling on how many pairs this rule ever places, scaled to stay
     * proportional to the book's page count -- see
     * MatchingNeighbourEndsRule::MAX_PAIRS_PER_PAGE for the full reasoning.
     * keep_fraction's own budget grows faster than this (it's a fraction of the
     * whole remaining pool), and each pair is a committed literal with no
     * fallback if a neighbour collides, so letting pair density rise with book
     * size means more expensive full-refill local retries as the book grows.
     */
    private const MAX_PAIRS_PER_PAGE = 1.2;

    /** @var array<string,string[]>|null last letter => every word ending with it */
    private ?array $byLastLetterCache = null;

    public function apply(array $words, Grid $grid, array $keep): array
    {
        for ($page = 0; $page < $grid->pages; $page++) {
            for ($col = 0; $col < $grid->cols; $col++) {
                for ($row = 0; $row < $grid->rows - 1; $row++) {
                    $top = $grid->linearIndex($page, $row, $col);
                    $bottom = $grid->linearIndex($page, $row + 1, $col);
                    if (!$keep[$top] || !$keep[$bottom]) {
                        continue;
                    }
                    if (WordTags::lastLetter($words[$top]) === WordTags::lastLetter($words[$bottom])) {
                        $keep[$top] = false;
                        $keep[$bottom] = false;
                    }
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $maxPairs = (int) round($canvas->grid()->pages * self::MAX_PAIRS_PER_PAGE);
        $pairsWanted = min(intdiv($context->budget(), 2), $maxPairs);
        if ($pairsWanted <= 0) {
            return;
        }

        $byLastLetter = $this->lastLetterGroups($context);
        $survives = array_flip($context->survivorsSoFar());
        $topCandidates = Rng::shuffle($context->survivorsSoFar(), $context->seed());

        $pairs = [];
        foreach ($topCandidates as $top) {
            if (count($pairs) >= $pairsWanted) {
                break;
            }
            if ($canvas->isWordReserved($top)) {
                continue;
            }
            $letter = WordTags::lastLetter($top);
            if (!in_array($letter, self::USABLE_ENDING_LETTERS, true)) {
                continue;
            }
            $bottom = null;
            foreach ($byLastLetter[$letter] ?? [] as $candidate) {
                if ($candidate === $top || !isset($survives[$candidate]) || $canvas->isWordReserved($candidate)) {
                    continue;
                }
                $bottom = $candidate;
                break;
            }
            if ($bottom === null) {
                continue;
            }
            $canvas->reserveWord($top);
            $canvas->reserveWord($bottom);
            $pairs[] = [$top, $bottom];
        }

        if (count($pairs) < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} pairs of words ending in the same "
                . 'letter, but only ' . count($pairs) . ' are currently available (surviving the rules '
                . 'so far, not already claimed by another pair this attempt)'
            );
        }

        $candidates = $canvas->freeVerticalPairs($context->seed());

        // Same gap as MatchingNeighbourEndsRule's horizontal placement: freeVerticalPairs()
        // only guarantees the two SLOT cells are free, not that the row above top or below
        // bottom is safe. If that outer row already holds another rule's (or this rule's own
        // earlier) pinned word ending in the SAME letter, this rule's own apply() scan would
        // cross out three words in a column instead of the two construction accounted for --
        // only discoverable at fill time otherwise, which costs a full refill to recover from.
        $grid = $canvas->grid();
        $prefix = $context->spellingRulesBefore();
        $placed = 0;
        $candidateIndex = 0;
        $partnerOf = [];
        for ($n = 0; $n < $pairsWanted; $n++) {
            [$wordTop, $wordBottom] = $pairs[$n];

            $top = $bottom = null;
            while ($candidateIndex < count($candidates)) {
                [$candidateTop, $candidateBottom] = $candidates[$candidateIndex];
                $candidateIndex++;
                // One generic check covers every cross-cutting constraint ANY
                // rule has registered (corners, opposing lengths, whatever a
                // future rule invents) -- this rule knows nothing about any of
                // them, it just asks. See Canvas::wordSatisfiesInvariants().
                if (!$canvas->wordSatisfiesInvariants($wordTop, $candidateTop)
                    || !$canvas->wordSatisfiesInvariants($wordBottom, $candidateBottom)) {
                    continue;
                }
                if (!$this->hasOuterNeighbourCollision($canvas, $grid, $candidateTop, $candidateBottom, $wordTop, $wordBottom)) {
                    $top = $candidateTop;
                    $bottom = $candidateBottom;
                    break;
                }
            }
            if ($top === null) {
                break;
            }
            $placed++;

            $canvas->claimDoomed(
                $top,
                $context->stepId(),
                CellPlan::literal(
                    $wordTop,
                    $prefix,
                    'vpairtop' . $context->stepId() . '-' . $top,
                    "step {$context->stepId()}: {$this->describe()} (top word)"
                )
            );
            $canvas->claimDoomed(
                $bottom,
                $context->stepId(),
                CellPlan::literal(
                    $wordBottom,
                    $prefix,
                    'vpairbottom' . $context->stepId() . '-' . $bottom,
                    "step {$context->stepId()}: {$this->describe()} (bottom word)"
                )
            );
            $partnerOf[$top] = $bottom;
            $partnerOf[$bottom] = $top;
        }

        if ($placed < $pairsWanted) {
            throw new ConstructionRetry(
                "step {$context->stepId()} wanted {$pairsWanted} vertically adjacent pairs of free cells "
                . 'with no outer-neighbour collision, but only found room for ' . $placed
            );
        }

        // Registered regardless, same reasoning as NoLengthAdjacentToOpposingLiteral:
        // this rule's OWN apply() scans the whole grid for a shared ending letter on
        // EVERY vertically adjacent pair, so the risk of an unrelated word (ordinary
        // filler, another rule's reserved pair-half) accidentally matching one of
        // THESE reserved words exists regardless of how many pairs were placed.
        // Unlike MatchingNeighbourEndsRule's matching-ends criterion (natively
        // protected by fillWords()'s own $leftLast/$rightFirst check), this rule had
        // NO fill-time protection at all before this -- the actual cause of the
        // "orphaned half" failures that only ever showed up as an expensive,
        // unattributed wrong-survivor-count retry.
        $canvas->addInvariant(new NoUnplannedVerticalEndingMatch($canvas, $partnerOf));
    }

    /**
     * True if placing $wordTop/$wordBottom at $top/$bottom would create an
     * UNPLANNED third match with whatever is already pinned just outside either
     * end -- the row above $top, or the row below $bottom. Mirrors
     * MatchingNeighbourEndsRule::hasOuterNeighbourCollision() exactly, one row
     * instead of one column.
     */
    private function hasOuterNeighbourCollision(
        Canvas $canvas,
        Grid $grid,
        int $top,
        int $bottom,
        string $wordTop,
        string $wordBottom
    ): bool {
        [, $topRow] = $grid->coordsOf($top);
        if ($topRow > 0) {
            $outerWord = $canvas->planFor($top - $grid->cols)?->literalWord();
            if ($outerWord !== null && WordTags::lastLetter($outerWord) === WordTags::lastLetter($wordTop)) {
                return true;
            }
        }
        [, $bottomRow] = $grid->coordsOf($bottom);
        if ($bottomRow < $grid->rows - 1) {
            $outerWord = $canvas->planFor($bottom + $grid->cols)?->literalWord();
            if ($outerWord !== null && WordTags::lastLetter($outerWord) === WordTags::lastLetter($wordBottom)) {
                return true;
            }
        }
        return false;
    }

    public function constructionPriority(): int
    {
        // Low enough to run before AnagramPairOnPageRule/MatchingNeighbourEndsRule
        // (45/50), same reasoning as CornerContainsLetterRule/LongShortNeighbourRule:
        // this rule's NoUnplannedVerticalEndingMatch invariant already exists by the
        // time those rules check Canvas::wordSatisfiesInvariants() before committing
        // a literal word, instead of only catching the collision after the fact.
        return 22;
    }

    public function describe(): string
    {
        return 'vertically adjacent SURVIVING pairs on a page where both words end in the same letter';
    }

    /**
     * Every word in the dictionary, grouped by its last letter -- cached once (not
     * per attempt), the same reasoning as PageSignaturePairRule::signatureGroups().
     *
     * @return array<string,string[]>
     */
    private function lastLetterGroups(ConstructionContext $context): array
    {
        if ($this->byLastLetterCache !== null) {
            return $this->byLastLetterCache;
        }

        return $this->byLastLetterCache = WordIndexCache::remember(
            self::class,
            $this->describe(),
            $context->wordPoolHash(),
            static function () use ($context): array {
                $groups = [];
                foreach ($context->wordPool() as $word) {
                    $groups[WordTags::lastLetter($word)][] = $word;
                }
                return $groups;
            }
        );
    }
}
