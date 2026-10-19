<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word in which SOME single consonant appears four or more
 * times -- any consonant, not a nominated one. That catches POSSESSED (four
 * S) and ASSESSES.
 *
 * Different from LetterCountRule, which is about one specific letter you name
 * in the instruction. Here the reader is looking for repetition itself, which
 * is a different kind of search: you can't scan for a shape, you have to
 * actually tally.
 *
 * Restricted to consonants because "the same letter four times" and "the
 * same consonant four times" give different answers for words like OSSEOUS,
 * and the printed instruction has to say which. A vowel- or any-letter
 * version would be a different card, subclassing this one with its own
 * letter set.
 *
 * Expensive-ish for the reader: tallying doesn't short-circuit the way a
 * pattern check does, since you can't rule a word out until you've counted
 * it. Better placed later in the book than early.
 */
final class RepeatedLetterRule extends SpellingRule
{
    private const CONSONANTS = [
        'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm',
        'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z',
    ];
    private const TIMES = 4;

    public function failsWord(string $word): bool
    {
        return WordTags::maxRepeatAmong($word, self::CONSONANTS) >= self::TIMES;
    }

    public function describe(): string
    {
        return 'words where one consonant appears ' . self::TIMES . '+ times';
    }
}
