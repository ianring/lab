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
 * a word of exactly $length letters that is ALSO still surviving. A word of
 * that length is NOT itself removed here.
 *
 * That exemption is what makes the step well-behaved. The launch pads are
 * the only thing the rule reads, and the rule never removes one, so the set
 * of launch pads is fixed for the whole step: a reader can work through it
 * in any order, crossing out as they go, and get the same answer. Nobody is
 * asked to remember a word they just struck through. The launch pads are
 * cleared by the following step, which removes every word of that length.
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
/** No card yet -- subclass with a real length to add one (the finale's "key" rule). */
abstract class KnightAdjacentSurvivingLengthRule implements RuleInterface, ConstructsCells
{
    protected function __construct(private int $length)
    {
    }

    public function length(): int
    {
        return $this->length;
    }

    public function apply(array $words, Grid $grid, array $keep): array
    {
        // Reading order, acting on what is standing at that moment -- what a reader
        // crossing out as they go actually does.
        //
        // A launch pad is never removed by its own step. That is what makes the
        // order irrelevant: the set of launch pads cannot change while the step is
        // being worked through, so it doesn't matter where the reader starts, and
        // nobody is ever asked to remember a word they have just crossed out. The
        // launch pads are cleared by the following step instead.
        foreach ($words as $i => $word) {
            if (!$keep[$i] || strlen($word) === $this->length) {
                continue;
            }
            foreach ($grid->knightNeighbours($i) as $neighbourIndex) {
                if ($keep[$neighbourIndex] && strlen($words[$neighbourIndex]) === $this->length) {
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

        // A victim: survives everything up to here, where a key kills it. No shape
        // requirement at all -- a victim that happens to be launch-pad length is
        // simply skipped by this step and cleared by the next one.
        $keyPlan = new CellPlan($survivesAll, $keyShape, 'key');
        $victimPlan = CellPlan::surviving($survivesAll, 'victim');

        $target = $context->targetSurvivors();
        $keyCells = [];

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

            // Reject the candidate unless everything it would kill is allowed to be
            // a survivor -- this is what protects the answer. Launch pads sitting
            // next to each other is fine: this step doesn't remove them.
            $blocked = false;
            $usable = [];
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
            $canvas->claimSurvivor($candidate, $finalStepId, $keyPlan, false);
            $keyCells[$candidate] = true;
            foreach ($usable as $n) {
                $canvas->claimSurvivor($n, $context->stepId(), $victimPlan, true);
            }
        }

        if ($keyCells === []) {
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

    public function describe(): string
    {
        return "words a knight's move from a surviving {$this->length}-letter word "
            . "({$this->length}-letter words themselves are spared)";
    }
}
