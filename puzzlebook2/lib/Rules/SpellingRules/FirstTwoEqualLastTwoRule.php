<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * Cross out every word of 5+ letters whose first two letters are the same pair,
 * in the same order, as its last two -- RESTORE (RE...RE), CHURCH (CH...CH),
 * DECADE (DE...DE). Pure letters: read the opening pair, read the closing pair,
 * compare -- no meaning needed. The 5-letter floor keeps the two pairs from
 * overlapping and keeps the catch on recognizable words rather than tiny
 * reduplications like PIPI or ADAD.
 */
final class FirstTwoEqualLastTwoRule extends SpellingRuleBase
{
    private const MIN_LENGTH = 5;

    public function failsWord(string $word): bool
    {
        return strlen($word) >= self::MIN_LENGTH && substr($word, 0, 2) === substr($word, -2);
    }

    public function describe(): string
    {
        return 'words of ' . self::MIN_LENGTH . '+ letters whose first two letters match their last two (RESTORE, CHURCH)';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Cross out every word of five or more letters whose first two letters are exactly the same, and in the same order, as its last two letters (`RESTORE` begins and ends with `RE`; `CHURCH` with `CH`; `DECADE` with `DE`; `ONION` with `ON`).';
    }
}
