<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;

/**
 * A rule that is a pure function of a word's own spelling -- never its
 * position, never its neighbours. failsWord() is both halves at once: it's
 * what apply() (below) uses to solve the rule over the whole grid, and it's
 * exactly the check lib/ChainBuilder.php needs to build() a word that
 * satisfies or fails this rule on purpose. One method, read once, used both
 * ways -- the two can never quietly disagree about what the rule means.
 */
abstract class SpellingRule implements RuleInterface
{
    /** True if $word fails this rule and should be crossed out. */
    abstract public function failsWord(string $word): bool;

    /**
     * @param string[] $words
     * @param bool[] $keep
     * @return bool[]
     */
    public function apply(array $words, Grid $grid, array $keep): array
    {
        foreach ($words as $i => $word) {
            if ($keep[$i] && $this->failsWord($word)) {
                $keep[$i] = false;
            }
        }
        return $keep;
    }
}
