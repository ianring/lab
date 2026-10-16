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
 *   steps 1-13   printed rules, applied one after another, nothing hidden
 *   step 14      THE TWIST -- every 4th surviving word spells out step 15
 *   step 15      the hidden knight's-move rule those words describe
 *   step 16      a printed rule that clears the "key" words step 15 launched
 *                from, leaving exactly one word: the answer
 *
 * Note that the reveal is NOT the last step. Step 11 deliberately leaves the
 * five-letter key words standing -- they're the launch pads the knight's-move
 * rule fires from, so it can't cross them out -- and step 12 is what finally
 * clears them. See README, "How the knight's-move rule is built".
 *
 * ORDERING. Rules are roughly in order of how SCARCE their matching words
 * are, scarcest first. A rule only ever sees words the earlier rules left
 * behind, so a rule with few matches in the dictionary needs to run while the
 * pool is still full. The greedy ones (both-ends-on-the-top-row especially)
 * go late, where there's still plenty for them to catch.
 *
 * This is the file to edit when you want to change what the puzzle *does*.
 * It does not say where any word sits on the page -- lib/ChainBuilder.php
 * derives all of that, guided by each eliminate step's 'construction' hint:
 *
 *   'keep_fraction' => roughly the share of words entering this step that
 *     should survive it (so 0.90 removes ~10%). It sets the PACING; the
 *     exact survivor count is pinned by the knight's-move geometry, and the
 *     last keep_fraction step -- the pair rule -- absorbs the difference.
 *     A rule's supply of matching words does NOT grow with the book, so a
 *     scarce rule's keep_fraction has to rise towards 1 as the book grows.
 *   'pairs' => true marks a rule (matching-neighbour-ends) that removes
 *     words two at a time, by deliberately placing some horizontally
 *     adjacent cells to match on purpose.
 *   'derived' => true marks a rule whose reach isn't a fraction of anything --
 *     how much it removes falls out of its own geometry.
 *
 * Run `php bin/check.php` after editing this file. It reports whether the
 * rule set can work, and for any rule asking for more words than exist, the
 * keep_fraction that would fit.
 *
 * Every rule is a check on a word's own letters or its position in the
 * grid -- never on what the word means -- so the puzzle can be solved by
 * counting and spelling alone, with no English fluency required. The
 * conventions below are settled here, and should be printed in the book's
 * own front matter so the reader has them too:
 *
 *   1. Reading order is page by page, then row by row, left to right.
 *   2. "Surviving" / "survivors" means words not yet crossed out.
 *   3. NO RULE EVER ASKS YOU TO READ A WORD YOU HAVE ALREADY CROSSED OUT.
 *      Once a word is gone it is gone: it can't trigger a later rule, and it
 *      can't be half of a pair. Rules that look at neighbouring cells only
 *      ever consider neighbours still standing.
 *
 *      This is a constraint on the BOOK, not just a convention for the
 *      reader. People mark up puzzles with whatever is to hand, and a marker
 *      obliterates a word rather than veiling it -- so any rule needing the
 *      letters of an eliminated word would be literally unsolvable for them.
 *      An earlier version of the side-by-side rule got this wrong.
 *
 *      Noticing that a word has been crossed out is still fair game: you can
 *      see a struck word is struck without reading it, which is why step 11
 *      works.
 *   4. Y is never treated as a vowel.
 */

return [

    'steps' => [

        [
            'id' => 1,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'palindrome', 'params' => []],
            'rule_text' => 'Cross out every word that reads the same forwards and backwards.',
            // The scarcest rule in the book: English has only on the order of a
            // hundred palindromes of 3-9 letters, however big the book gets.
            'construction' => ['keep_fraction' => 0.994],
        ],
        [
            'id' => 2,
            'kind' => 'eliminate',
            'printed' => true,
            // Four, not three: step 10 reveals the literal word "knight", whose
            // "ght" is a run of exactly three. See README.
            'rule' => ['type' => 'consonant_run', 'params' => ['length' => 4]],
            'rule_text' => 'Cross out every word with four consonants in a row (Y does not count as a vowel).',
            'construction' => ['keep_fraction' => 0.98],
        ],
        [
            'id' => 3,
            'kind' => 'eliminate',
            'printed' => true,
            // Restricted to six-letter words on purpose. Unrestricted, "second
            // letter is A" removed over 9,000 words in one step, which is no fun
            // to work through; the length makes it a precise rule instead.
            'rule' => [
                'type' => 'letter_at_position',
                'params' => ['position' => 2, 'matches' => ['a'], 'length' => 6],
            ],
            'rule_text' => 'Cross out every six-letter word whose 2nd letter is A.',
            'construction' => ['keep_fraction' => 0.95],
        ],
        [
            'id' => 4,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'contains_substring', 'params' => ['needle' => 'z']],
            'rule_text' => 'Cross out every word containing the letter Z.',
            'construction' => ['keep_fraction' => 0.93],
        ],
        [
            'id' => 5,
            'kind' => 'eliminate',
            'printed' => true,
            // Four letters and up. Short words land in alphabetical order by chance
            // very often, and the minimum is also what lets the revealed word "any"
            // through -- it would otherwise be crossed out long before it's read.
            'rule' => ['type' => 'alphabetical_order', 'params' => ['min_length' => 4]],
            'rule_text' => 'Cross out every word of FOUR OR MORE letters whose letters are in '
                . 'alphabetical order from first to last, never going backwards (ALMOST, BEGIN, CHIMP). '
                . 'A repeated letter does not count as going backwards, so BLESS and ALLOT are crossed '
                . 'out too. Words of three letters or fewer are safe.',
            // 0.97, not 0.94: bin/check.php measured only 365 words in the list that
            // pass the earlier rules and fail this one, and 0.94 asked for 620. With
            // the four-letter floor this is a genuinely scarce rule, so it stays small
            // and step 9 picks up the slack.
            'construction' => ['keep_fraction' => 0.97],
        ],
        [
            'id' => 6,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'contains_substring', 'params' => ['needle' => 'ee']],
            'rule_text' => 'Cross out every word containing a double E.',
            'construction' => ['keep_fraction' => 0.92],
        ],
        [
            'id' => 7,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'alphabet_adjacent_ends', 'params' => []],
            'rule_text' => 'Cross out every word whose first letter and last letter are next-door '
                . 'neighbours in the alphabet, in that order (A then B, B then C, and so on).',
            'construction' => ['keep_fraction' => 0.88],
        ],
        [
            'id' => 8,
            'kind' => 'eliminate',
            'printed' => true,
            // Seven letters or fewer. The cap is what lets the revealed word
            // "eliminate" through -- it starts and ends with E, both top-row, so
            // unrestricted this rule would cross it out before it's ever read.
            'rule' => [
                'type' => 'both_ends_in_set',
                'params' => [
                    'letters' => ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
                    'max_length' => 7,
                ],
            ],
            'rule_text' => 'Cross out every word of SEVEN LETTERS OR FEWER whose first letter and last '
                . 'letter are BOTH on the top row of a keyboard: Q W E R T Y U I O P. Longer words are '
                . 'safe.',
            // The greediest rule here, and deliberately placed last among the
            // spelling rules so it has a large pool to work on.
            'construction' => ['keep_fraction' => 0.60],
        ],
        [
            'id' => 9,
            'kind' => 'eliminate',
            'printed' => true,
            // The other very greedy rule, and the one carrying the load that step 5
            // can't. English is full of adjacent vowel pairs, so the supply is deep
            // and this can take a big share without running short.
            // The cap lets the revealed word "positioned" through -- 10 letters, with
            // "io" in the middle. Note it now has a real cost: since the pool includes
            // 10-letter words, this rule can no longer touch any of them, so they're
            // over-represented among late survivors. That's fair to the reader (the
            // printed rule says so) but it does mean this rule is less greedy than its
            // keep_fraction suggests.
            'rule' => ['type' => 'vowel_run', 'params' => ['length' => 2, 'max_length' => 9]],
            'rule_text' => 'Cross out every word of NINE LETTERS OR FEWER with two vowels side by side '
                . '(Y does not count as a vowel), as in RAIN, HOUSE or QUIET.',
            'construction' => ['keep_fraction' => 0.50],
        ],
        [
            'id' => 10,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'letter_count', 'params' => ['letter' => 'i', 'count' => 3]],
            'rule_text' => 'Cross out every word containing three or more of the letter I, wherever they '
                . 'sit in the word (INITIAL, IGNITION, INVITING).',
            // Few words fit three of one letter into nine, so this is a variety rule
            // rather than a thinning one -- counting occurrences is a different motion
            // for the reader than spotting a pattern.
            'construction' => ['keep_fraction' => 0.985],
        ],
        [
            'id' => 11,
            'kind' => 'eliminate',
            'printed' => true,
            // A new SHAPE of rule: its trigger is whether a cell has already been
            // crossed out, so the reader's own progress is the input. It can't be
            // worked out by looking at the page -- only by having done the earlier
            // steps. Reach is bounded by the page count (one word per page at most),
            // so it's 'derived' rather than a fraction of anything.
            'rule' => [
                'type' => 'conditional_cell',
                'params' => ['trigger' => [0, 0], 'target' => [0, -1]],
            ],
            'rule_text' => 'On every page: if the word in the TOP LEFT corner is already crossed out, '
                . 'cross out the word in the TOP RIGHT corner too. If the top left word is still '
                . 'standing, leave the top right one alone.',
            'construction' => ['derived' => true],
        ],
        [
            'id' => 12,
            'kind' => 'eliminate',
            'printed' => true,
            // Reaches ACROSS pages -- every other rule works within one. The reader
            // holds a position in mind and turns the page to act on it.
            //
            // 'letters' and 'position' are the tuning knobs. J at position 2 is very
            // rare (order of a hundred words in 133,000), so this is a variety rule at
            // these settings; switching to vowels, or to position 1, would make it a
            // heavy one. bin/check.php reports the real supply.
            'rule' => [
                'type' => 'same_cell_next_page',
                'params' => [
                    'letters' => ['j'],
                    'position' => 2,
                    'page_offset' => 1,
                    'remove_trigger' => true,
                ],
            ],
            'rule_text' => 'Find every word still standing whose 2nd letter is J. Cross out that word, '
                . 'and also cross out the word in the same row and column on the NEXT page.',
            'construction' => ['keep_fraction' => 0.99],
        ],
        [
            'id' => 13,
            'kind' => 'eliminate',
            'printed' => true,
            'rule' => ['type' => 'matching_neighbour_ends', 'params' => []],
            'rule_text' => 'Find every pair of side-by-side words, in the same row, where BOTH are '
                . 'still standing and the left word ends in the same letter the right word starts '
                . 'with. Cross out both words of the pair.',
            // Absorbs whatever the rules above leave behind, since it's the last step
            // with a keep_fraction and a pair can be made from any two words.
            'construction' => ['keep_fraction' => 0.55, 'pairs' => true],
        ],

        // The one twist: read every 4th surviving word. Those words, in
        // order, ARE the next rule -- real words, not a code, nothing to
        // look up.
        [
            'id' => 14,
            'kind' => 'reveal',
            'printed' => true,
            'reveal' => ['mechanism' => 'every_nth_word', 'params' => ['n' => 4, 'count' => 13]],
            'reveal_text' => 'Now read every 4th surviving word, counting from the start of the book. '
                . 'Those words are your next rule.',
            'expected' => 'eliminate any word positioned chess knight move away from surviving five letter words',
        ],

        // Step 15 is what step 14 spells out. "Chess knight move" is the move
        // a knight makes in chess: two cells one way, one cell the other,
        // staying on the same page.
        [
            'id' => 15,
            'kind' => 'eliminate',
            'printed' => false,
            'rule' => ['type' => 'knight_adjacent_surviving_length', 'params' => ['length' => 5]],
            'rule_text' => 'Cross out every surviving word that is a knight\'s chess move from a '
                . 'SURVIVING five-letter word on the same page. (A word already crossed out is not a '
                . 'launch pad for this rule -- it must still be standing.)',
            'construction' => ['derived' => true],
        ],

        // Step 16 clears the five-letter launch pads step 15 fired from --
        // they all end in E -- and nothing is left but the answer.
        [
            'id' => 16,
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
