<?php

declare(strict_types=1);

/**
 * How the book is physically shaped: the grid's size, and one optional knob
 * for keeping a letter out of the filler.
 *
 * Rule semantics live in config/chain.php, and nothing here places any
 * individual word -- ChainBuilder works out which cell plays which role
 * itself, from the keys' knight's-move geometry and each step's
 * 'construction' hints. The reveal's target words come from its own
 * 'expected' text.
 */

return [

    // Full size: 100 pages x 20 rows x 6 columns = 12,000 words.
    // The 9 x 15 x 6 prototype (810 words) is the quick setting to fall back to
    // while testing a change -- a build at full size takes much longer, so prove
    // a new rule works small before scaling it up.
    'grid' => [
        'pages' => 100,
        'rows' => 20,
        'cols' => 6,
    ],

    // Keeps every filler word from starting with this letter. An earlier
    // version of the finale needed 'k' held back, so the only K-words in the
    // book were the ones placed on purpose; the current knight's-move rule
    // launches from five-letter words ending in E instead, and doesn't care
    // about initials. Left empty so the whole word list is available --
    // set it to a letter if a future rule needs one held in reserve.
    'filler_excluded_initial' => '',
];
