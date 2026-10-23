<?php

declare(strict_types=1);

namespace Puzzlebook2\Chain;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Turns a step's plain-array config (as written in config/chain.json) into
 * the real Rule object, and applies a rule's elimination to a keep-mask.
 * This -- and the Rule classes it dispatches to -- is the only place a rule
 * MEANS anything: bin/build.php, bin/check.php, ChainRunner, and
 * preview/api.php all call it, so a rule can't quietly mean something different
 * depending on who's asking. The browser never re-implements a rule.
 *
 * A rule's 'type' is the short name of its class under
 * Puzzlebook2\Rules\SpellingRules or ...\GeometricRules -- the clue library
 * IS those directories. Adding a new card never touches this file.
 */
final class Engine
{
    private const NAMESPACES = [
        'Puzzlebook2\\Rules\\SpellingRules\\',
        'Puzzlebook2\\Rules\\GeometricRules\\',
    ];

    /** @param array{type:string} $rule */
    public static function ruleFromConfig(array $rule): Rule
    {
        foreach (self::NAMESPACES as $ns) {
            $class = $ns . $rule['type'];
            if (is_a($class, Rule::class, true) && (new \ReflectionClass($class))->isInstantiable()) {
                return new $class();
            }
        }
        throw new \InvalidArgumentException(
            "Unknown rule type '{$rule['type']}' -- it must be the short name of a concrete, zero-argument "
            . 'Rule class under Puzzlebook2\\Rules\\SpellingRules or Puzzlebook2\\Rules\\GeometricRules.'
        );
    }

    /**
     * Apply one rule to a keep-mask: the union of eliminatedCells() over
     * every scope, all evaluated against the ENTERING mask (snapshot
     * semantics -- a pair's halves count each other as standing
     * simultaneously; a capture can't un-surround a neighbour mid-pass).
     *
     * @param string[] $words
     * @param bool[] $keep
     * @param array{type:string} $ruleConfig
     * @return bool[]
     */
    public static function applyRule(array $words, BoardSpec $spec, array $keep, array $ruleConfig): array
    {
        return self::apply(self::ruleFromConfig($ruleConfig), new FilledBoard($spec, $words), $keep);
    }

    /**
     * @param bool[] $keep
     * @return bool[]
     */
    public static function apply(Rule $rule, FilledBoard $board, array $keep): array
    {
        $mask = new KeepMask($keep);
        $out = $keep;
        foreach ($rule->allScopes($board->spec()) as $scope) {
            foreach ($rule->eliminatedCells($board, $scope, $mask) as $cell) {
                $out[$cell] = false;
            }
        }
        return $out;
    }

    /** @param bool[] $keep */
    public static function countSurvivors(array $keep): int
    {
        return count(array_filter($keep));
    }

    /**
     * @param bool[] $keep
     * @return int[] linear indices still kept
     */
    public static function survivingIndices(array $keep): array
    {
        $indices = [];
        foreach ($keep as $i => $k) {
            if ($k) {
                $indices[] = $i;
            }
        }
        return $indices;
    }
}
