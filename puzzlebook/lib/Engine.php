<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Reveals\AcrosticFirstCellPerPageReveal;
use Puzzlebook\Reveals\ColumnLastLettersReveal;
use Puzzlebook\Reveals\EveryNthWordReveal;
use Puzzlebook\Reveals\LastSurvivorOnPageReveal;
use Puzzlebook\Reveals\RevealInterface;
use Puzzlebook\Rules\RuleInterface;

/**
 * Turns a step's plain-array config (as written in config/chain.json) into
 * the real Rule or Reveal object, then runs it.
 *
 * A rule's 'type' is simply the short name of its class under
 * Puzzlebook\Rules -- the clue library IS the lib/Rules/ directory, and
 * every card in it is a self-contained, zero-argument class (see
 * config/README.md). Adding a new card never touches this file: there is
 * no registry to update, just a class to add.
 *
 * Reveals (lib/Reveals/) are a separate, currently-inactive mechanism and
 * keep their own 'mechanism' + 'params' shape below.
 *
 * This -- and the classes it dispatches to -- is the only place a rule or
 * reveal *means* anything. It runs exclusively on the server:
 * bin/build.php, bin/solve.php, and web/api.php all call it. The browser
 * never re-implements a rule; web/index.html is a thin renderer that calls
 * web/api.php for every step and displays whatever it's told.
 *
 * A "keep-mask" is a bool[] the same length as the word grid: true means
 * the word at that position has not been crossed out yet.
 */
final class Engine
{
    /** @param array{type:string} $rule */
    public static function ruleFromConfig(array $rule): RuleInterface
    {
        $class = __NAMESPACE__ . '\\Rules\\' . $rule['type'];
        if (!is_a($class, RuleInterface::class, true) || !(new \ReflectionClass($class))->isInstantiable()) {
            throw new \InvalidArgumentException(
                "Unknown rule type '{$rule['type']}' -- it must be the short name of a concrete, "
                . 'zero-argument class under Puzzlebook\\Rules.'
            );
        }
        return new $class();
    }

    /** @param array{mechanism:string,params?:array} $reveal */
    public static function revealFromConfig(array $reveal): RevealInterface
    {
        $p = $reveal['params'] ?? [];

        return match ($reveal['mechanism']) {
            'every_nth_word' => new EveryNthWordReveal($p['n'], $p['count']),
            'acrostic_first_cell_per_page' => new AcrosticFirstCellPerPageReveal($p['pages']),
            'last_survivor_on_page' => new LastSurvivorOnPageReveal($p['page']),
            'column_last_letters' => new ColumnLastLettersReveal(
                $p['page'],
                $p['col'],
                $p['rows'],
                $p['reverse'] ?? false
            ),
            default => throw new \InvalidArgumentException("Unknown reveal mechanism '{$reveal['mechanism']}'"),
        };
    }

    /**
     * @param string[] $words
     * @param bool[] $keep
     * @param array{type:string} $rule
     * @return bool[]
     */
    public static function applyRule(array $words, Grid $grid, array $keep, array $rule): array
    {
        return self::ruleFromConfig($rule)->apply($words, $grid, $keep);
    }

    /**
     * @param string[] $words
     * @param bool[] $keep
     * @param array{mechanism:string,params?:array} $reveal
     * @return array{text:string, cells:int[]}
     */
    public static function computeReveal(array $words, Grid $grid, array $keep, array $reveal): array
    {
        return self::revealFromConfig($reveal)->reveal($words, $grid, $keep);
    }

    /** @param array{mechanism:string,params?:array} $reveal */
    public static function describeReveal(array $reveal): string
    {
        return self::revealFromConfig($reveal)->describe();
    }

    public static function countSurvivors(array $keep): int
    {
        $count = 0;
        foreach ($keep as $k) {
            if ($k) {
                $count++;
            }
        }
        return $count;
    }

    /** @return int[] linear indices still kept. */
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
