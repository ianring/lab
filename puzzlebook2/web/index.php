<?php

declare(strict_types=1);

/**
 * The Eliminoid hasher: a public word-fingerprint checker.
 *
 * A player who has solved for a word types it in; this prints the word's
 * fingerprint. They compare it to the fingerprint printed beside the ANSWER
 * or MYSTERY box in their book. Match = right word.
 *
 *   fingerprint = Fingerprint::of( word )
 *
 * All the hashing lives in the shared Puzzlebook2\Fingerprint class
 * (lib/Fingerprint.php) so the website and the book print pipeline compute
 * byte-identical fingerprints -- salt, normalisation, and base alphabet are
 * defined there once. This page is just the public front end for it.
 */

require_once __DIR__ . '/../lib/Fingerprint.php';

use Puzzlebook2\Fingerprint;

$raw = isset($_GET['word']) ? substr((string) $_GET['word'], 0, 64) : '';
$word = Fingerprint::normalise($raw);
$hasInput = $raw !== '';
$hash = $word !== '' ? Fingerprint::of($raw) : '';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eliminoid &middot; Word Checker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Sans:wght@400;500&family=IBM+Plex+Mono:wght@500;600&display=swap">
<style>
  :root {
    --paper: #eef0e8;
    --ink: #22251f;
    --muted: #5f6458;
    --line: #c9cdbe;
    --accent: #7a3b2e;
    --panel: #f7f8f2;
    --match: #2f6b3a;
    color-scheme: light;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --paper: #1b1d18;
      --ink: #e9ebe1;
      --muted: #9aa08e;
      --line: #3a3d33;
      --accent: #d08b63;
      --panel: #23261f;
      --match: #8fcaa0;
      color-scheme: dark;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: var(--paper);
    color: var(--ink);
    font-family: 'IBM Plex Sans', system-ui, sans-serif;
    line-height: 1.5;
    padding: 7vh 20px 40px;
  }
  main { width: 100%; max-width: 540px; }
  .brand {
    font-family: 'Fraunces', Georgia, serif;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 14px;
    color: var(--accent);
    margin: 0 0 6px;
  }
  h1 {
    font-family: 'Fraunces', Georgia, serif;
    font-weight: 600;
    font-size: clamp(26px, 6vw, 36px);
    margin: 0 0 10px;
    line-height: 1.1;
  }
  p.lede { color: var(--muted); margin: 0 0 26px; font-size: 15px; }
  form { display: flex; gap: 10px; flex-wrap: wrap; }
  input[type=text] {
    flex: 1 1 220px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 20px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    padding: 12px 14px;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    background: var(--panel);
    color: var(--ink);
  }
  input[type=text]:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
  button {
    flex: 0 0 auto;
    font: inherit;
    font-weight: 500;
    padding: 12px 22px;
    border: none;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    cursor: pointer;
  }
  button:hover { filter: brightness(1.07); }
  .result {
    margin-top: 28px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    background: var(--panel);
    padding: 20px 22px;
  }
  .result .label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--muted);
    margin: 0 0 4px;
  }
  .result .word {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 22px;
    letter-spacing: 0.12em;
    margin: 0 0 16px;
  }
  .fingerprint {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 600;
    font-size: clamp(16px, 4.4vw, 21px);
    letter-spacing: 0.06em;
    word-break: break-all;
    color: var(--accent);
    margin: 0;
  }
  .hint { color: var(--muted); font-size: 13.5px; margin: 16px 0 0; }
  .empty { color: var(--muted); font-style: italic; }
  footer { margin-top: 34px; color: var(--muted); font-size: 12.5px; }
  footer code { font-family: 'IBM Plex Mono', monospace; }
</style>
</head>
<body>
<main>
  <p class="brand">Eliminoid</p>
  <h1>Word checker</h1>
  <p class="lede">Check if your word is correct by typing it in here. </p>

  <form method="get" action="">
    <input type="text" name="word" value="<?= h($raw) ?>"
           placeholder="" autocomplete="off" autocapitalize="characters"
           autofocus maxlength="64" aria-label="Your word">
    <button type="submit">Check</button>
  </form>

<?php if ($hasInput && $word !== ''): ?>
  <div class="result">
    <p class="label">Fingerprint for</p>
    <p class="word"><?= h($word) ?></p>
    <p class="fingerprint"><?= h(Fingerprint::grouped($hash)) ?></p>
    <p class="hint">If this matches the fingerprint in your book, then your answer is correct!</p>
  </div>
<?php elseif ($hasInput): ?>
  <div class="result">
    <p class="empty">Type a word made of letters to see its fingerprint.</p>
  </div>
<?php endif; ?>
</main>
</body>
</html>
