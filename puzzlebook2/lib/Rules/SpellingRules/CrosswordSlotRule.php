<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Dictionary\WordTags;
use Puzzlebook2\Rules\SpellingRuleBase;

/**
 * A crossword-style fill-in: cross out every word that fits a fixed slot, like
 * a crossword blank with some letters already written in. The slot is a string
 * of lowercase fixed letters and '_' blanks -- "_a__e_" means a six-letter word
 * with A as the 2nd letter and E as the 5th, anything in the blanks (MASTER,
 * MARKET, GARDEN). It is a pure positional check: a solver counts letters and
 * reads off positions, no knowledge of what the word means required, which is
 * exactly the accessibility bar every spelling rule has to clear.
 *
 * failsWord() derives entirely from pattern(), and the printed instruction
 * renders that same pattern(), so the logic and the text can never drift apart.
 * Each concrete class is one immutable slot; a different slot is a different
 * class. The class NAME is only a mnemonic (a representative word that fits),
 * like the Boggle boards' place-names -- the slot in pattern() is the rule.
 */
abstract class CrosswordSlotRule extends SpellingRuleBase
{
    /** The slot: lowercase fixed letters, '_' for a blank (any letter). */
    abstract protected function pattern(): string;

    /**
     * Recognizable words that fit the slot, for the printed instruction only.
     * Hand-picked (not drawn from the dictionary) so the examples a reader sees
     * are familiar words, never the obscure fragments the slot also catches.
     *
     * @return string[]
     */
    abstract protected function examples(): array;

    final public function failsWord(string $word): bool
    {
        return WordTags::matchesPattern($word, $this->pattern());
    }

    public function describe(): string
    {
        return 'words that fit the pattern ' . $this->rendered();
    }

    public function ruleText(): string
    {
        return sprintf(
            'Cross out every %d-letter word that fits the pattern `%s` -- each dash is any one letter, and the printed letters must sit in exactly those positions (%s).',
            strlen($this->pattern()),
            $this->rendered(),
            implode(', ', array_map('strtoupper', $this->examples())),
        );
    }

    /** The slot as a spaced, upper-cased template with dashes for blanks: "_a__e_" -> "- A - - E -". */
    private function rendered(): string
    {
        $cells = [];
        foreach (str_split($this->pattern()) as $ch) {
            $cells[] = $ch === '_' ? '-' : strtoupper($ch);
        }
        return implode(' ', $cells);
    }
}
