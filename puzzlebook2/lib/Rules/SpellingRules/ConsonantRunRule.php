<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every word that does NOT contain the letter Y but has four or
 * more consonants in a row. MONSTROUS goes (the run NSTR, no Y anywhere);
 * EVERYTHING is safe -- RYTH is a four-consonant run (Y counts as a
 * consonant), but containing a Y is exactly what spares it. The Y clause
 * both narrows the catch (raw supply drops from ~7,700 to ~1,900) and
 * gives the reader a quick first check before counting consonants.
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
}
