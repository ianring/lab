<?php
declare(strict_types=1);

/**
 * Choosing a move.
 *
 * Right now it picks at random from the columns with room in them, which is a
 * placeholder and plays like one. It is kept behind a function with a real
 * signature — board and piece in, column and an explanation out — so that
 * replacing the guess with a search changes this file and nothing else.
 *
 * The explanation travels with the move on purpose. Once there is something
 * worth explaining ("blocks x's open three on column 5"), the page and the API
 * already have somewhere to put it.
 */

require_once __DIR__ . '/board.php';

/** What the current move-picker calls itself, for the API to report. */
const SOLVER_NAME = 'random';

/**
 * Pick a column for $piece to play on $board.
 *
 * @return array [ok, column|error message, why]
 */
function solver_move(string $board, string $piece): array
{
    if (board_winner($board) !== '') {
        return [false, 'That game is already won.', ''];
    }

    $legal = board_legal_columns($board);
    if ($legal === []) {
        return [false, 'The board is full.', ''];
    }

    // random_int rather than rand: no reason to reach for the weaker one, and
    // this is the seam where a real search will land later anyway.
    $col = $legal[random_int(0, count($legal) - 1)];

    return [true, $col, 'Picked at random from ' . count($legal) . ' open column'
        . (count($legal) === 1 ? '' : 's') . '.'];
}
