<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out every word spelled using nothing but $letters -- optionally only
 * when the word is exactly $length letters long.
 *
 * The letter set is config, so one rule covers a whole family of printed
 * instructions that have nothing to do with each other as far as the code is
 * concerned:
 *
 *   - letters whose capital forms are mirror-symmetric (B C D E H I K O X)
 *   - letters drawn with straight strokes only (A E F H I K L M N T V W X Y Z)
 *   - letters worth one point in Scrabble (A E I L N O R S T U)
 *
 * The RULE_TEXT has to name the letters explicitly, because the reader can't
 * be expected to work out which ones the author had in mind -- and shouldn't
 * have to. Anything about shapes or symmetry is a description of why that set
 * was chosen, not something the reader needs to reason about.
 *
 * $length narrows a very greedy rule into a precise one; see
 * LetterAtPositionRule for the same idea applied to a single position.
 */
final class UsesOnlyLettersRule extends SpellingRule
{
    /** @param string[] $letters */
    public function __construct(private array $letters, private ?int $length = null)
    {
    }

    public function failsWord(string $word): bool
    {
        if ($this->length !== null && strlen($word) !== $this->length) {
            return false;
        }
        return WordTags::usesOnlyLetters($word, $this->letters);
    }

    public function describe(): string
    {
        return ($this->length === null ? 'words' : "{$this->length}-letter words")
            . ' spelled only from ' . strtoupper(implode('', $this->letters));
    }
}
