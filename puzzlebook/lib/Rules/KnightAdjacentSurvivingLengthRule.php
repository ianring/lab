<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Construction\Canvas;
use Puzzlebook\Construction\CellPlan;
use Puzzlebook\Construction\ConstructionContext;
use Puzzlebook\ConstructionRetry;
use Puzzlebook\Grid;

/**
 * Cross out every surviving word that is a knight's move (as in chess) from
 * a word of exactly $length letters that is ALSO still surviving.
 *
 * Unlike the other positional rules, a crossed-out word does NOT count as a
 * neighbour here -- the rule says "surviving", so a word already struck
 * through is not a launch pad. apply() therefore reads a snapshot of $keep
 * taken before the rule runs, not $keep as it's updated mid-pass, so the
 * result can't depend on iteration order.
 *
 * CONSTRUCTION -- keys first, victims derived.
 *
 * The obvious way to build this is to decide which words survive and then
 * look for a launch pad near each one. That does not work: it's a global
 * covering problem (the surviving set must cover itself), survivors are
 * sparse, and a cell has only 6-8 knight-neighbours, so most survivors end
 * up with nowhere to put a launch pad.
 *
 * So construct() inverts it. It places the KEYS wherever they fit, and the
 * set of words surviving to this rule is then simply: the keys, plus
 * everything a knight's move from a key. Nothing to cover, nothing to search
 * for, every decision local. The answer survives because the builder forbids
 * its neighbourhood from holding survivors, and a key is only accepted if
 * nothing it would kill lands in there either.
 *
 * Keys outlive this rule by design -- a rule can't cross out the thing it
 * fires from -- so they're handed to the final step to clear, shaped to be
 * exactly what that step removes.
 */
final class KnightAdjacentSurvivingLengthRule implements RuleInterface, ConstructsCells
{
    public function __construct(private int $length)
    {
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        $keepBefore = $keep;
        foreach ($words as $i => $word) {
            if (!$keepBefore[$i]) {
                continue;
            }
            foreach ($grid->knightNeighbours($i) as $neighbourIndex) {
                if ($keepBefore[$neighbourIndex] && strlen($words[$neighbourIndex]) === $this->length) {
                    $keep[$i] = false;
                    break;
                }
            }
        }
        return $keep;
    }

    public function construct(Canvas $canvas, ConstructionContext $context): void
    {
        $finalRule = $context->finalRule();
        $finalStepId = $context->finalStepId();
        if ($finalRule === null || $finalStepId === null) {
            throw new \RuntimeException(
                "The knight's-move rule leaves its launch-pad words standing, so the chain needs a final "
                . 'step whose rule removes them. Add one marked \'final\' => true.'
            );
        }

        $grid = $canvas->grid();
        $survivesAll = $context->spellingRulesTotal();
        $length = $this->length;

        // A launch pad: the right length, and shaped so the final step is what clears it.
        $keyShape = static fn (string $word): bool => strlen($word) === $length && $finalRule->failsWord($word);

        // A victim: survives everything up to here, where a key kills it. Kept clear of
        // the launch-pad shape, so the only words the final step removes are the keys.
        $victimShape = static fn (string $word): bool => !$finalRule->failsWord($word);

        $target = $context->targetSurvivors();
        $placedAny = false;

        foreach ($canvas->freeCellsShuffled($context->seed()) as $candidate) {
            if ($canvas->survivorCount() >= $target) {
                break;
            }
            if (!$canvas->isFree($candidate) || !$canvas->mayBeSurvivor($candidate)) {
                continue;
            }

            $neighbours = $grid->knightNeighbours($candidate);
            // A cell near a page edge reaches too few others to be worth a key.
            if (count($neighbours) < 5) {
                continue;
            }

            // Reject the candidate unless everything it would kill is allowed to be a
            // survivor and is still free -- this is what protects the answer.
            $usable = [];
            $blocked = false;
            foreach ($neighbours as $n) {
                if (!$canvas->mayBeSurvivor($n)) {
                    $blocked = true;
                    break;
                }
                if ($canvas->isFree($n)) {
                    $usable[] = $n;
                }
            }
            if ($blocked) {
                continue;
            }

            // The key is NOT replaceable: this rule fires from it, so its shape is
            // load-bearing. Its victims are, so the reveal can use them.
            $canvas->claimSurvivor(
                $candidate,
                $finalStepId,
                new CellPlan($survivesAll, $keyShape, 'key'),
                false
            );
            foreach ($usable as $n) {
                $canvas->claimSurvivor(
                    $n,
                    $context->stepId(),
                    new CellPlan($survivesAll, $victimShape, 'victim'),
                    true
                );
            }
            $placedAny = true;
        }

        if (!$placedAny) {
            throw new ConstructionRetry(
                "step {$context->stepId()} found nowhere to put a launch-pad word"
            );
        }
        if ($canvas->survivorCount() < $target) {
            throw new ConstructionRetry(
                "step {$context->stepId()} kept only " . $canvas->survivorCount()
                . " words standing, but {$target} are needed"
            );
        }
    }

    public function constructionPriority(): int
    {
        // This rule DEFINES which words survive to the end of the book, so it has to
        // choose its cells before anything else takes them.
        return 10;
    }
}
