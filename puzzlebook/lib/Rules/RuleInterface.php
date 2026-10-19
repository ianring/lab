<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\Grid;

/**
 * One elimination rule, fully self-contained: everything about what it
 * means lives in its own class file, next to its build()-side counterpart
 * where it has one (see SpellingRule).
 *
 * A "keep-mask" is a bool[] the same length as the word grid: true means
 * the word at that position has not been crossed out yet.
 */
interface RuleInterface
{
    /**
     * @param string[] $words
     * @param bool[] $keep
     * @return bool[]
     */
    public function apply(array $words, Grid $grid, array $keep): array;

    /**
     * What this rule crosses out, in a few words, with its parameters filled in --
     * "4+ consonants in a row", "both ends in QWERTYUIOP, max 7 letters".
     *
     * This is for whoever is working ON the book, not reading it: bin/check.php and
     * bin/solve.php print it so a chain of a dozen rules is legible at a glance,
     * without cross-referencing config/chain.json. The reader-facing wording is the
     * step's own 'rule_text', which is a full instruction and says more.
     *
     * Phrase it as the thing being removed, so it reads after "crosses out".
     */
    public function describe(): string;
}
