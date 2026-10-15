<?php

declare(strict_types=1);

/**
 * The puzzle itself: an ordered, FLAT list of steps. Each step does exactly
 * one thing -- it either eliminates words by one structural rule, or
 * reveals the next instruction by one structural reading of whatever
 * currently survives.
 *
 * The shape of this chain:
 *
 *   steps 1-5   printed rules, applied one after another, nothing hidden
 *   step 6      THE TWIST -- every 4th surviving word spells out step 7
 *   step 7      the hidden knight's-move rule those words describe
 *   step 8      a printed rule that clears the "key" words step 7 launched
 *               from, leaving exactly one word: the answer
 *
 * Note that the reveal is NOT the last step. Step 7 deliberately leaves the
 * five-letter key words standing -- they're the launch pads the knight's-move
 * rule fires from, so they can't be crossed out by it -- and step 8 is what
 * finally clears them. That ordering is the whole reason this is
 * constructible; see README, "How the knight's-move rule is built".
 *
 * This is the file to edit when you want to change what the puzzle *does*.
 * It does not say where any word sits on the page -- lib/ChainBuilder.php
 * derives all of that itself, guided by each eliminate step's
 * 'construction' hint:
 *
 *   'keep_fraction' => roughly the share of words entering this step that
 *     should survive it (so 0.10 removes ~90%). It sets the PACING; the
 *     exact survivor count is pinned by the knight's-move geometry, and the
 *     last spelling step absorbs the difference.
 *   'pairs' => true marks a rule (matching-neighbour-ends) that removes
 *     words two at a time, by deliberately placing some horizontally
 *     adjacent cells to match on purpose.
 *   'derived' => true marks a rule whose reach isn't a fraction of anything --
 *     how much it removes falls out of its own geometry. The knight's-move
 *     rule is one: its launch pads are placed first, and what it removes is
 *     whatever ends up a knight's move from one.
 *
 * Run `php bin/check.php` after editing this file -- it reports in about a
 * second whether the rule set can work, and what to change if not.
 *
 * Every rule is a check on a word's own letters or its position in the
 * grid -- never on what the word means -- so the puzzle can be solved by
 * counting and spelling alone, with no English fluency required. The
 * conventions below are settled here, and should be printed in the book's
 * own front matter so the reader has them too:
 *
 *   1. Reading order is page by page, then row by row, left to right.
 *   2. "Surviving" / "survivors" means words not yet crossed out.
 *   3. For the matching-neighbour-ends rule (step 5), a crossed-out word
 *      still counts as a neighbour -- a highlighter mark doesn't erase the
 *      printed word.
 *   4. For the knight's-move rule (step 7), the opposite: it says
 *      "SURVIVING five letter words", so a word already crossed out is NOT
 *      a launch pad. The rule says which it means.
 *   5. Y is never treated as a vowel.
 */

return [

    'steps' => [

        [
            'id' => 1,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'palindrome', 'params' => []],
            'rule_text' => 'Cross out every word that reads the same forwards and backwards.',
            // Kept small on purpose: English has only on the order of a hundred
            // palindromes of 3-9 letters, so asking this step to remove much more
            // than a couple of dozen exhausts the supply. bin/check.php reports the
            // exact number available if you change this.
            'construction' => ['keep_fraction' => 0.97],
        ],
        [
            'id' => 2,
            'kind' => 'eliminate',
            'printed' => true,
            // Four, not three: step 6 reveals the literal word "knight",
            // whose "ght" is a run of exactly three. See README.
            'rule' => ['type' => 'consonant_run', 'params' => ['length' => 4]],
            'rule_text' => 'Cross out every word with four consonants in a row (Y does not count as a vowel).',
            'construction' => ['keep_fraction' => 0.90],
        ],
        [
            'id' => 3,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'alphabet_adjacent_ends', 'params' => []],
            'rule_text' => 'Cross out every word whose first letter and last letter are next-door '
                . 'neighbours in the alphabet, in that order (A then B, B then C, and so on).',
            'construction' => ['keep_fraction' => 0.40],
        ],
        [
            'id' => 4,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'letter_at_position', 'params' => ['position' => 2, 'matches' => ['a']]],
            'rule_text' => 'Cross out every word whose 2nd letter is A.',
            'construction' => ['keep_fraction' => 0.40],
        ],
        [
            'id' => 5,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'matching_neighbour_ends', 'params' => []],
            'rule_text' => 'Cross out every pair of side-by-side words, in the same row, where the '
                . 'left word ends in the same letter the right word starts with. Cross out BOTH words '
                . 'of the pair. A word already crossed out still counts as a neighbour here.',
            'construction' => ['keep_fraction' => 0.55, 'pairs' => true],
        ],

        // The one twist: read every 4th surviving word. Those words, in
        // order, ARE the next rule -- real words, not a code, nothing to
        // look up.
        [
            'id' => 6,
            'kind' => 'reveal',
            'printed' => true,
            'reveal' => ['mechanism' => 'every_nth_word', 'params' => ['n' => 4, 'count' => 11]],
            'reveal_text' => 'Now read every 4th surviving word, counting from the start of the book. '
                . 'Those words are your next rule.',
            'expected' => 'eliminate any word knight chess move from surviving five letter words',
        ],

        // Step 7 is what step 6 spells out. "Knight chess move" is the
        // move a knight makes in chess: two cells one way, one cell the
        // other, staying on the same page.
        [
            'id' => 7,
            'kind' => 'eliminate',
            'printed' => false,
            'rule' => ['type' => 'knight_adjacent_surviving_length', 'params' => ['length' => 5]],
            'rule_text' => 'Cross out every surviving word that is a knight\'s chess move from a '
                . 'SURVIVING five-letter word on the same page. (A word already crossed out is not a '
                . 'launch pad for this rule -- it must still be standing.)',
            // 'derived' means this rule's reach isn't set by a keep_fraction -- how many
            // words it removes falls out of its own geometry (where the launch pads land).
            'construction' => ['derived' => true],
        ],

        // Step 8 clears the five-letter launch pads step 7 fired from --
        // they all end in E -- and nothing else is left but the answer.
        [
            'id' => 8,
            'kind' => 'eliminate',
            'printed' => true,
            'final' => true,
            'rule' => ['type' => 'length_and_ending', 'params' => ['length' => 5, 'ending' => 'e']],
            'rule_text' => 'Cross out every five-letter word ending in E. One word is now left standing: '
                . 'that is the answer.',
        ],
    ],

    'answer' => 'dragon',
];
