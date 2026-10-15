<?php
declare(strict_types=1);

/**
 * The board, and the rules that go with it.
 *
 * A board is 42 characters: six rows of seven, top row first, '.' for empty
 * and 'x' or 'o' for a piece. Flat text rather than a nested array because the
 * board travels in a URL and in a JSON field, and a string needs no encoding
 * step at either end — what you read in the address bar is the position.
 *
 * Nothing here knows about HTML or about choosing a move. Both the page and
 * the API sit on top of this file, which is the only reason they can never
 * disagree about whether someone has won.
 */

const COLS = 7;
const ROWS = 6;

const CELL_EMPTY  = '.';
const PIECE_HUMAN = 'x';
const PIECE_BOT   = 'o';

/** An empty board. */
function board_new(): string
{
    return str_repeat(CELL_EMPTY, ROWS * COLS);
}

/**
 * Validate a board that arrived from outside, and hand back the clean version.
 * Null means "this is not a position that could occur in a game".
 *
 * Both callers take the board from user input — the query string on the page,
 * a request field in the API — so this checks reachability, not just shape. A
 * piece hanging in mid-air or a player who has moved three times more than the
 * other is someone editing the URL, and the answer to that is a fresh board
 * rather than a confident reply about a position that cannot exist.
 */
function board_parse(?string $raw): ?string
{
    if (!is_string($raw) || strlen($raw) !== ROWS * COLS) {
        return null;
    }
    if (preg_match('/[^.xo]/', $raw)) {
        return null;
    }

    // Gravity: within a column, no piece may sit above an empty cell.
    for ($c = 0; $c < COLS; $c++) {
        $hole = false;
        for ($r = ROWS - 1; $r >= 0; $r--) {
            if ($raw[$r * COLS + $c] === CELL_EMPTY) {
                $hole = true;
            } elseif ($hole) {
                return null;
            }
        }
    }

    // Turn order: x moves first, so x is either level with o or one ahead.
    $x = substr_count($raw, PIECE_HUMAN);
    $o = substr_count($raw, PIECE_BOT);
    if ($o > $x || $x - $o > 1) {
        return null;
    }

    return $raw;
}

/** The piece at a square. */
function board_cell(string $board, int $row, int $col): string
{
    return $board[$row * COLS + $col];
}

/** Which columns still have room, left to right, zero indexed. */
function board_legal_columns(string $board): array
{
    $open = [];
    for ($c = 0; $c < COLS; $c++) {
        if ($board[$c] === CELL_EMPTY) {   // the top cell of the column
            $open[] = $c;
        }
    }
    return $open;
}

/**
 * Drop a piece down a column. Null if the column is out of range or full,
 * which is the same answer the caller wants in both cases: don't do that.
 */
function board_drop(string $board, int $col, string $piece): ?string
{
    if ($col < 0 || $col >= COLS) {
        return null;
    }
    for ($r = ROWS - 1; $r >= 0; $r--) {
        $i = $r * COLS + $col;
        if ($board[$i] === CELL_EMPTY) {
            $board[$i] = $piece;
            return $board;
        }
    }
    return null;
}

/** Whose turn it is, worked out from the board alone. */
function board_turn(string $board): string
{
    return substr_count($board, PIECE_HUMAN) <= substr_count($board, PIECE_BOT)
        ? PIECE_HUMAN
        : PIECE_BOT;
}

/** True once no column has room. */
function board_is_full(string $board): bool
{
    return board_legal_columns($board) === [];
}

/**
 * The winning piece, or '' if nobody has four in a row yet.
 *
 * Every square is treated as the top-left end of a run of four in one of four
 * directions. The opposite directions are covered because the square at the
 * other end of the run gets its own turn as the starting point.
 */
function board_winner(string $board): string
{
    $line = board_winning_line($board);
    return $line === [] ? '' : $board[$line[0]];
}

/**
 * The four squares that won, as indexes into the board string, or [] if the
 * game is still going. The page uses this to point at the run it found —
 * "you win" is a lot less satisfying without showing which four did it.
 */
function board_winning_line(string $board): array
{
    $directions = [[0, 1], [1, 0], [1, 1], [1, -1]];   // right, down, and both diagonals

    for ($r = 0; $r < ROWS; $r++) {
        for ($c = 0; $c < COLS; $c++) {
            $piece = $board[$r * COLS + $c];
            if ($piece === CELL_EMPTY) {
                continue;
            }
            foreach ($directions as [$dr, $dc]) {
                $endRow = $r + 3 * $dr;
                $endCol = $c + 3 * $dc;
                if ($endRow >= ROWS || $endCol < 0 || $endCol >= COLS) {
                    continue;   // the run would leave the board
                }
                $line = [];
                for ($step = 0; $step < 4; $step++) {
                    $line[] = ($r + $step * $dr) * COLS + ($c + $step * $dc);
                }
                if ($board[$line[1]] === $piece
                    && $board[$line[2]] === $piece
                    && $board[$line[3]] === $piece) {
                    return $line;
                }
            }
        }
    }
    return [];
}

/** True once the position is settled, either way. */
function board_is_over(string $board): bool
{
    return board_winner($board) !== '' || board_is_full($board);
}
