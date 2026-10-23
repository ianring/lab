<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every 8-letter word whose first four letters are an anagram of its last four (TEAMMATE). */
final class AnagramHalvesRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        return strlen($word) === 8 && WordTags::halvesAreAnagrams($word);
    }

    public function describe(): string
    {
        return '8-letter words whose first half is an anagram of the second half';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'This one is only for words of EXACTLY EIGHT letters. Split the word down the middle into two '
            . 'four-letter halves. Cross it out if the first half and the second half use exactly the same '
            . 'four letters as each other, just in a different order -- each half an anagram of the other '
            . '(REAPPEAR splits into REAP and PEAR; TEAMMATE into TEAM and MATE). Every other word is safe.';
    }
}
