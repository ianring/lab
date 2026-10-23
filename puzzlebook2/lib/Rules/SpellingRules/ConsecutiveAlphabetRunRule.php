<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word hiding three consecutive alphabet letters in a row (DEFT, FIRST, STUDY). */
final class ConsecutiveAlphabetRunRule extends SpellingRuleBase
{
    private const RUN_LENGTH = 3;

    public function failsWord(string $word): bool
    {
        return WordTags::hasConsecutiveAlphabetRun($word, self::RUN_LENGTH);
    }

    public function describe(): string
    {
        return 'words with three consecutive alphabet letters sitting in a row';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word that contains THREE letters which run in a row in the alphabet AND sit next '
            . 'to each other in the word, in order -- ...DEF..., ...RST..., ...STU... (DEFT, FIRST, STUDY). '
            . 'The three must climb by one each step with no gap.';
    }
}
