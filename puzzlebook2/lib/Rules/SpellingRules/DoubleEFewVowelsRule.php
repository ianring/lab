<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\PageCorners;
use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word with a double E and at most one OTHER vowel (COFFEE yes; FOURTEEN no). */
final class DoubleEFewVowelsRule extends SpellingRuleBase
{
    private const MAX_OTHER_VOWELS = 1;

    public function failsWord(string $word): bool
    {
        if (!WordTags::contains($word, 'ee')) {
            return false;
        }
        return WordTags::vowelCount($word) <= 2 + self::MAX_OTHER_VOWELS;
    }

    /**
     * Every match contains a literal "ee", so it can never satisfy a corner
     * that CornerContainsLetterRule's standing invariant requires to stay
     * E-free -- the search there is unwinnable by construction, so corners
     * are excluded from this rule's geometry outright (v1's avoidsCorners()).
     */
    protected function avoidedCells(BoardSpec $spec): array
    {
        return PageCorners::all($spec);
    }

    public function describe(): string
    {
        return 'words with a double E and at most ' . self::MAX_OTHER_VOWELS . ' other vowel';
    }
}
