<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word in which SOME single letter appears $times or more --
 * any letter, not a nominated one. At four, over the consonants, that catches
 * POSSESSED (four S) and ASSESSES.
 *
 * Different from LetterCountRule, which is about one specific letter you name
 * in the instruction. Here the reader is looking for repetition itself, which
 * is a different kind of search: you can't scan for a shape, you have to
 * actually tally.
 *
 * $group narrows which letters count -- 'consonant', 'vowel' or 'any'. The
 * instruction must say which, since "the same letter four times" and "the
 * same consonant four times" give different answers for words like OSSEOUS.
 *
 * Expensive-ish for the reader: tallying doesn't short-circuit the way a
 * pattern check does, since you can't rule a word out until you've counted
 * it. Better placed later in the book than early.
 */
final class RepeatedLetterRule extends SpellingRule
{
    private const VOWELS = ['a', 'e', 'i', 'o', 'u'];
    private const CONSONANTS = [
        'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm',
        'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z',
    ];

    /** @var string[] */
    private array $letters;

    public function __construct(private int $times, private string $group = 'consonant')
    {
        $this->letters = match ($group) {
            'consonant' => self::CONSONANTS,
            'vowel' => self::VOWELS,
            'any' => array_merge(self::CONSONANTS, self::VOWELS),
            default => throw new \InvalidArgumentException(
                "Letter group '{$group}' isn't one of 'consonant', 'vowel' or 'any'."
            ),
        };
    }

    public function failsWord(string $word): bool
    {
        return WordTags::maxRepeatAmong($word, $this->letters) >= $this->times;
    }

    public function describe(): string
    {
        $what = $this->group === 'any' ? 'letter' : $this->group;
        return "words where one {$what} appears {$this->times}+ times";
    }
}
