<?php
declare(strict_types=1);

/**
 * Best next move, given a board.
 *
 *     api.php?board=<42 characters>[&piece=x|o]
 *
 * Columns in the reply are zero indexed from the left, matching the board
 * string. The page adds one before showing them to a person; nothing else
 * should have to guess which convention it is looking at.
 */

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/board.php';
require __DIR__ . '/lib/solver.php';

header('Content-Type: application/json');

/*
 * Every reply is HTTP 200, including the failures, and the ok field carries
 * the verdict instead.
 *
 * Apache in front of PHP-FPM with ProxyErrorOverride on — the usual shape of
 * these boxes — throws away the body of any 4xx or 5xx and substitutes its own
 * error page. A precise explanation of what was wrong with the board would
 * reach the caller as a bare "502 Bad Gateway". The status line is not worth
 * losing the message over.
 */
set_exception_handler(function ($error) {
    json_out(['ok' => false, 'error' => 'PHP exception: ' . $error->getMessage()
        . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')']);
});

$raw = p('board');
if ($raw === '') {
    json_out([
        'ok'    => false,
        'error' => 'Pass a board: ' . (ROWS * COLS) . ' characters of ".", "x" and "o", top row first.',
        'example' => board_new(),
    ]);
}

$board = board_parse($raw);
if ($board === null) {
    json_out([
        'ok'    => false,
        'error' => 'That is not a reachable position. It needs to be exactly ' . (ROWS * COLS)
                 . ' characters of ".", "x" and "o", with no piece resting above an empty cell '
                 . 'and no player more than one move ahead of the other.',
    ]);
}

// Whose move it is follows from the board, so the piece field is only ever a
// way of asking a hypothetical: "what would o play here?"
$piece = p('piece', board_turn($board));
if ($piece !== PIECE_HUMAN && $piece !== PIECE_BOT) {
    json_out(['ok' => false, 'error' => 'The piece must be "' . PIECE_HUMAN . '" or "' . PIECE_BOT . '".']);
}

$winner = board_winner($board);
if ($winner !== '') {
    json_out([
        'ok'     => false,
        'error'  => 'That game is over — "' . $winner . '" has four in a row.',
        'winner' => $winner,
        'board'  => $board,
    ]);
}

[$ok, $result, $why] = solver_move($board, $piece);
if (!$ok) {
    json_out(['ok' => false, 'error' => (string)$result, 'board' => $board]);
}

$col   = (int)$result;
$after = (string)board_drop($board, $col, $piece);

json_out([
    'ok'       => true,
    'column'   => $col,             // zero indexed from the left
    'piece'    => $piece,
    'strategy' => SOLVER_NAME,
    'why'      => $why,
    'legal'    => board_legal_columns($board),
    'board'    => $board,
    'after'    => $after,
    'wins'     => board_winner($after) === $piece,
]);
