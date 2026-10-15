<?php
declare(strict_types=1);

/**
 * The board, drawn in text.
 *
 * There is no stylesheet, no script and no widget of any kind: the page is
 * characters, line breaks and links. The whole game state lives in the query
 * string, so there is no session and no file on disk to keep — every position
 * is a URL you can bookmark, mail to someone, or edit by hand to set up a
 * puzzle. The address bar is the save file.
 *
 * Each cell is drawn three characters wide so that the column headers line up
 * over the columns they drop into.
 */

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/board.php';
require __DIR__ . '/lib/solver.php';

/* ---- work out the position before anything is printed ------------------ */

$given = q('b');
$board = board_parse($given);

// A board that failed to parse is worth saying out loud rather than quietly
// resetting: it is almost always a hand-edited URL, and silence looks like a bug.
$note  = ($given !== '' && $board === null) ? 'That board did not make sense, so here is a new one.' : '';
if ($board === null) {
    $board = board_new();
}

/*
 * A move arrives as ?drop=N against the board that was on screen. Applying it
 * and then redirecting to the resulting position means a refresh re-shows the
 * same board instead of asking the computer to roll again, and the back button
 * walks back through the game one move at a time.
 */
$drop = q('drop');
if ($drop !== '' && ctype_digit($drop) && !board_is_over($board) && board_turn($board) === PIECE_HUMAN) {
    $after = board_drop($board, (int)$drop, PIECE_HUMAN);
    if ($after !== null) {
        $board = $after;
        $last  = '';

        if (!board_is_over($board)) {
            [$ok, $result] = solver_move($board, PIECE_BOT);
            if ($ok) {
                $board = (string)board_drop($board, (int)$result, PIECE_BOT);
                $last  = '&last=' . (int)$result;
            }
        }

        header('Location: index.php?b=' . rawurlencode($board) . $last);
        exit;
    }
}

$winner  = board_winner($board);
$winning = array_flip(board_winning_line($board));
$over    = board_is_over($board);
$legal   = board_legal_columns($board);

$lastCol = q('last');
$lastCol = ($lastCol !== '' && ctype_digit($lastCol) && (int)$lastCol < COLS) ? (int)$lastCol : null;

/* ---- drawing ------------------------------------------------------------ */

/** One cell, always three characters wide. The four that won wear brackets. */
function cell(string $piece, bool $won): string
{
    $glyph = $piece === PIECE_HUMAN ? 'X' : ($piece === PIECE_BOT ? 'O' : '.');
    return $won ? '(' . $glyph . ')' : '&nbsp;' . $glyph . '&nbsp;';
}

$url = '?b=' . rawurlencode($board);

?><!doctype html>
<html>
<head><title>Connect 4</title></head>
<body>
<span>CONNECT&nbsp;4</span><br>
<br>
<?php
/* The header: a link per column that still has room, plain text otherwise. */
foreach (range(0, COLS - 1) as $c) {
    $label = (string)($c + 1);
    if (!$over && in_array($c, $legal, true)) {
        echo '<a href="' . h($url . '&drop=' . $c) . '">[' . $label . ']</a>';
    } else {
        echo '<span>&nbsp;' . $label . '&nbsp;</span>';
    }
}
echo "<br>\n";

for ($r = 0; $r < ROWS; $r++) {
    echo '<span>';
    for ($c = 0; $c < COLS; $c++) {
        $i = $r * COLS + $c;
        echo cell(board_cell($board, $r, $c), isset($winning[$i]));
    }
    echo "</span><br>\n";
}

echo '<span>' . str_repeat('=', COLS * 3) . "</span><br>\n";
?>
<br>
<?php
if ($winner === PIECE_HUMAN) {
    echo '<span>You win. Four in a row.</span><br>';
} elseif ($winner === PIECE_BOT) {
    echo '<span>The computer wins. It was not even trying.</span><br>';
} elseif ($over) {
    echo '<span>A draw. The board is full.</span><br>';
} else {
    if ($lastCol !== null) {
        echo '<span>The computer played column ' . ($lastCol + 1) . '.</span><br>';
    }
    echo '<span>You are X. Pick a column above.</span><br>';
}

if ($note !== '') {
    echo '<br><span>' . h($note) . '</span><br>';
}
?>
<br>
<a href="index.php">New game</a><br>
<a href="<?= h('api.php?board=' . rawurlencode($board)) ?>">Ask the API what it would play</a><br>
</body>
</html>
