<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every word that does NOT contain the letter Y but has four or
 * more consonants in a row. MONSTROUS goes (the run NSTR, no Y anywhere);
 * YOUNGSTER is safe -- it has the run NGST, but it contains a Y, and that
 * alone spares it. (Example chosen so the saving Y sits outside the run:
 * a word like EVERYTHING, where the Y is part of the run itself, muddies
 * what the reader is being asked to check.) The Y clause both narrows the
 * catch (raw supply ~7,700 -> ~1,900) and gives the reader a quick first
 * check before counting consonants.
 */
final class ConsonantRunRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        if (str_contains($word, 'y')) {
            return false;
        }
        return WordTags::hasConsonantRun($word, 4);
    }

    public function describe(): string
    {
        return 'Y-less words with 4+ consonants in a row';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Eliminate every word that does NOT contain the letter Y but has four consonants in a row. MONSTROUS goes: it has the run NSTR and no Y anywhere. YOUNGSTER stays: it has the run NGST, but it contains a Y.';
    }
}
