<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Reveals\AcrosticFirstCellPerPageReveal;
use Puzzlebook\Reveals\ColumnLastLettersReveal;
use Puzzlebook\Reveals\EveryNthWordReveal;
use Puzzlebook\Reveals\LastSurvivorOnPageReveal;
use Puzzlebook\Reveals\RevealInterface;
use Puzzlebook\Rules\AlphabetAdjacentEndsRule;
use Puzzlebook\Rules\AlphabeticalOrderRule;
use Puzzlebook\Rules\BothEndsInSetRule;
use Puzzlebook\Rules\ConsonantRunRule;
use Puzzlebook\Rules\ConditionalCellRule;
use Puzzlebook\Rules\ContainsSubstringRule;
use Puzzlebook\Rules\LetterCountRule;
use Puzzlebook\Rules\EveryNthSurvivorRule;
use Puzzlebook\Rules\KnightAdjacentInitialRule;
use Puzzlebook\Rules\KnightAdjacentSurvivingLengthRule;
use Puzzlebook\Rules\LengthAndEndingRule;
use Puzzlebook\Rules\LetterAtPositionRule;
use Puzzlebook\Rules\MatchingNeighbourEndsRule;
use Puzzlebook\Rules\PalindromeRule;
use Puzzlebook\Rules\RestrictedAlphabetLengthRule;
use Puzzlebook\Rules\RuleInterface;
use Puzzlebook\Rules\SameCellNextPageRule;
use Puzzlebook\Rules\VowelRunRule;

/**
 * Turns a step's plain-array config (as written in config/chain.php) into
 * the real Rule or Reveal object, then runs it. Each rule or reveal's
 * actual meaning lives in its own class, under lib/Rules/ or lib/Reveals/
 * -- this file is just the registry mapping a config's 'type' or
 * 'mechanism' string to the class that implements it, so config/chain.php
 * never has to spell out a class name directly.
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
    /** @param array{type:string,params?:array} $rule */
    public static function ruleFromConfig(array $rule): RuleInterface
    {
        $p = $rule['params'] ?? [];

        return match ($rule['type']) {
            'palindrome' => new PalindromeRule(),
            'consonant_run' => new ConsonantRunRule($p['length']),
            'vowel_run' => new VowelRunRule($p['length'], $p['max_length'] ?? null),
            'letter_count' => new LetterCountRule($p['letter'], $p['count']),
            'conditional_cell' => new ConditionalCellRule($p['trigger'] ?? [0, 0], $p['target'] ?? [0, -1]),
            'same_cell_next_page' => new SameCellNextPageRule(
                $p['letters'] ?? ['j'],
                $p['position'] ?? 2,
                $p['page_offset'] ?? 1,
                $p['remove_trigger'] ?? true
            ),
            'alphabet_adjacent_ends' => new AlphabetAdjacentEndsRule(),
            'alphabetical_order' => new AlphabeticalOrderRule($p['min_length'] ?? null),
            'both_ends_in_set' => new BothEndsInSetRule($p['letters'], $p['max_length'] ?? null),
            'contains_substring' => new ContainsSubstringRule($p['needle']),
            'letter_at_position' => new LetterAtPositionRule($p['position'], $p['matches'], $p['length'] ?? null),
            'length_and_ending' => new LengthAndEndingRule($p['length'], $p['ending']),
            'every_nth_survivor' => new EveryNthSurvivorRule($p['n']),
            'knight_adjacent' => new KnightAdjacentInitialRule($p['initials']),
            'knight_adjacent_surviving_length' => new KnightAdjacentSurvivingLengthRule($p['length']),
            'matching_neighbour_ends' => new MatchingNeighbourEndsRule(),
            'restricted_alphabet_length' => new RestrictedAlphabetLengthRule($p['length'], $p['letters']),
            default => throw new \InvalidArgumentException("Unknown rule type '{$rule['type']}'"),
        };
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
     * @param array{type:string,params?:array} $rule
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
