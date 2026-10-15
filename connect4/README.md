# Connect 4

A connect four board drawn in text, with a back end that picks the computer's
move. No stylesheet, no JavaScript, no images — the page is characters, line
breaks and links.

## Playing

Open `index.php`. Click a numbered column header to drop your piece. You are
`X` and move first; the computer is `O` and answers immediately. The four
squares that win get brackets around them.

The whole game lives in the query string:

```
index.php?b=......................................xo..
```

Six rows of seven, top row first, `.` for empty. There is no session and no
file on disk, so a position is a URL — bookmark it, mail it to someone, or
edit it by hand to set up a puzzle. The address bar is the save file.

Applying a move redirects to the resulting position, so a refresh re-shows the
same board rather than asking the computer to roll again, and the back button
walks back through the game one move at a time.

## The API

```
GET|POST api.php?board=<42 characters>[&piece=x|o]
```

`piece` is optional; whose turn it is follows from the board, and passing it
only asks a hypothetical ("what would `o` play here?"). Columns are **zero
indexed** from the left, matching positions in the board string — the page
adds one before showing them to a person.

```json
{
  "ok": true,
  "column": 3,
  "piece": "x",
  "strategy": "random",
  "why": "Picked at random from 7 open columns.",
  "legal": [0, 1, 2, 3, 4, 5, 6],
  "board": "..........................................",
  "after": "......................................x...",
  "wins": false
}
```

Failures answer `ok: false` with an `error`, and always as HTTP 200 — Apache
in front of PHP-FPM with `ProxyErrorOverride` on discards the body of any 4xx
or 5xx and serves its own error page instead, which would turn a precise
complaint about the board into a bare "502 Bad Gateway".

A board is rejected unless it could actually occur in a game: right length,
right alphabet, no piece resting above an empty cell, and neither player more
than one move ahead of the other. Both callers take the board from user input,
so this checks reachability rather than just shape.

## The solver is a placeholder

`lib/solver.php` picks at random from the columns with room in them. It plays
exactly as badly as that sounds — it will not build a four of its own and will
not notice yours. It is behind a real signature (board and piece in, column
and an explanation out), so replacing the guess with a search touches that one
file. The `why` field is already carried through to the API and the page, for
when there is something worth explaining.

Everything it needs is in `lib/board.php` — legal columns, dropping, win
detection, and `board_winning_line()` for finding a completed four.

## Layout

Each cell is drawn three characters wide so the column headers sit over the
columns they drop into. With no stylesheet the browser uses its default
proportional font, where `.`, `X` and `O` are different widths, so the grid
will not be perfectly square. One `style="font-family:monospace"` on the body
fixes it, if you ever want to spend a stylesheet's worth of markup on it.

## Deploying

```
rsync -avh -e ssh /Users/ian/Documents/Repos/github/ianring/lab/connect4 root@i-a-n.ca:/root/lab
```

That line is in `../up.sh`. Nothing is written at runtime, so there is no data
directory to preserve and no permissions to set — an upload is the whole
deployment.
