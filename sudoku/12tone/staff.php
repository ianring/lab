<?php
declare(strict_types=1);

/**
 * Musical-notation view of a 12x12 sudoku: each row is a treble staff of twelve
 * measures, one whole note per filled cell, pitched chromatically 0=C .. B=B.
 *
 *   staff.php?difficulty=hard
 *   staff.php?difficulty=hard&seed=12345         reproducible
 *   staff.php?difficulty=hard&seed=12345&solved=1
 *   staff.php?...&format=svg                     the vector on its own
 *   staff.php?...&format=ly                      the LilyPond source
 *   staff.php?...&format=pdf                     vector PDF
 */

require __DIR__ . '/lib/errors.php';
require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';
require __DIR__ . '/lib/PuzzleStore.php';
require __DIR__ . '/lib/PuzzleView.php';
require __DIR__ . '/lib/PuzzleLink.php';
require __DIR__ . '/lib/StaffRenderer.php';

@set_time_limit(240);

const CACHE_DIR = __DIR__ . '/cache';

$variant    = Variant::get((string) ($_GET['variant'] ?? Variant::DEFAULT));
$difficulty = (string) ($_GET['difficulty'] ?? 'medium');
if (!isset(SudokuEngine::DIFFICULTIES[$difficulty])) {
    $difficulty = 'medium';
}
$seed      = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : null;
$solved    = !empty($_GET['solved']);
$format    = (string) ($_GET['format'] ?? 'html');
$staffSize = max(8, min(24, (int) ($_GET['size'] ?? StaffRenderer::DEFAULT_STAFF_SIZE)));
$clef      = StaffRenderer::clefName((string) ($_GET['clef'] ?? StaffRenderer::DEFAULT_CLEF));
$spelling  = isset(Variant::SPELLINGS[(string) ($_GET['spelling'] ?? '')]) ? (string) $_GET['spelling'] : 'sharps';
$rangeMode = StaffRenderer::rangeMode((string) ($_GET['range'] ?? StaffRenderer::DEFAULT_RANGE), $variant);

StaffRenderer::useCacheDir(CACHE_DIR);
$store = new PuzzleStore(CACHE_DIR . '/puzzles');

$genT0        = microtime(true);
$puzzleCached = $seed !== null && $store->has($variant->key, $difficulty, $seed);
$puzzle       = $store->get($variant->key, $difficulty, $seed);
$errors       = $store->errorsFor($puzzle);
$genTook      = microtime(true) - $genT0;

$ly = StaffRenderer::lilypond($puzzle, $solved, $staffSize, null, $clef, $spelling, $rangeMode);

if ($format === 'ly') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $ly;
    exit;
}

$t0     = microtime(true);
$render = StaffRenderer::render($ly, CACHE_DIR, $format === 'pdf' ? 'pdf' : 'svg');
$took   = microtime(true) - $t0;

if ($format === 'svg' || $format === 'pdf') {
    if (!$render['ok']) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo "LilyPond failed:\n\n" . $render['log'];
        exit;
    }
    header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'image/svg+xml'));
    echo $render['content'];
    exit;
}

// Only needed for the HTML view - the raw svg/pdf/ly responses exit above.
$engine = SudokuEngine::forVariant($variant->key);
$steps  = $store->steps($puzzle);
$tally  = PuzzleView::tally($steps);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** Query string for this puzzle, with overrides applied. */
function link_to(array $over = []): string
{
    global $variant, $difficulty, $puzzle, $solved, $staffSize, $clef, $spelling, $rangeMode;
    $q = array_merge([
        'variant'    => $variant->key,
        'difficulty' => $difficulty,
        'seed'       => $puzzle->meta['seed'],
        'solved'     => $solved ? 1 : 0,
        'size'       => $staffSize,
        'clef'       => $clef,
        'spelling'   => $spelling,
        'range'      => $rangeMode,
    ], $over);
    return '?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>12-Tone Sudoku &mdash; <?= h($puzzle->difficultyLabel()) ?></title>
<style>
  :root {
    --ink: #1c1c1e;
    --dim: #6b6b70;
    --faint: #96969c;
    --line: #c9c9cf;
    --rule: #1c1c1e;
    --bg: #fbfbfa;
    --panel: #ffffff;
    --accent: #8a4b2a;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 2rem 1.25rem 4rem;
    background: var(--bg); color: var(--ink);
    font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
  }
  .wrap { max-width: 66rem; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin: 0 0 .25rem; letter-spacing: -.01em; }
  .sub { color: var(--dim); margin: 0 0 1.75rem; font-size: .9rem; }

  form { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
         background: var(--panel); border: 1px solid var(--line);
         border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1.5rem; }
  label { font-size: .82rem; color: var(--dim); text-transform: uppercase; letter-spacing: .06em; }
  select, input[type=text], input[type=number] {
    font: inherit; padding: .3rem .5rem; border: 1px solid var(--line);
    border-radius: 5px; background: #fff; color: var(--ink);
  }
  input[type=text] { width: 9rem; }
  input[type=number] { width: 4.5rem; }
  button { font: inherit; padding: .35rem 1rem; border: 1px solid var(--rule);
           border-radius: 5px; background: var(--rule); color: #fff; cursor: pointer; }
  a { color: var(--accent); }

  .legend { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: 1.5rem; }
  .legend span { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                 font-size: .78rem; border: 1px solid var(--line); background: var(--panel);
                 border-radius: 4px; padding: .15rem .45rem; color: var(--dim); }
  .legend b { color: var(--ink); }

  .score { background: #fff; border: 1px solid var(--line); border-radius: 8px;
           padding: 1rem; overflow-x: auto; }
  .score svg { margin: 0 auto; }

  .meta { display: flex; gap: 1.75rem; flex-wrap: wrap; margin-top: 1rem;
          font-size: .82rem; color: var(--dim); }
  .meta b { color: var(--ink); font-weight: 600; }

  .err { background: #fff2f0; border: 1px solid #d99; color: #922;
         padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: .88rem; }
  .warn { background: #fffbe9; border: 1px solid #ddc98a; color: #6b5518;
          padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: .88rem; }
  pre { background: var(--panel); border: 1px solid var(--line); border-radius: 8px;
        padding: 1rem; overflow-x: auto; font-size: .76rem; line-height: 1.45; }
  details { margin-top: 2rem; }
  summary { cursor: pointer; font-size: .9rem; color: var(--accent); }
  details > pre { margin-top: 1rem; }

  .answers { margin-top: 2.5rem; border-top: 1px solid var(--line); padding-top: 1.75rem; }
  .answers h2 { font-size: .95rem; margin: 0 0 .35rem; letter-spacing: -.01em; }
  .answers .pid { font-family: ui-monospace, Menlo, monospace; font-size: .8rem;
                  color: var(--accent); background: #f4efe9; border-radius: 4px;
                  padding: .1rem .4rem; margin-left: .4rem; }
<?= PuzzleView::css($variant) ?>
  footer { margin-top: 3rem; color: var(--dim); font-size: .8rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>12-Tone Sudoku</h1>
  <p class="sub">
    Twelve rows, twelve measures each, one whole note per filled cell. Thick bar
    lines mark the 4-wide box edges; the staves are bracketed in threes for the
    3-tall bands. Engraved by LilyPond as vector SVG on US Letter portrait.
  </p>

  <form method="get">
    <label for="variant">Set</label>
    <select name="variant" id="variant">
      <?php foreach (Variant::all() as $v): ?>
        <option value="<?= h($v->key) ?>"<?= $v->key === $variant->key ? ' selected' : '' ?>><?= h($v->label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="difficulty">Difficulty</label>
    <select name="difficulty" id="difficulty">
      <?php foreach (array_keys(SudokuEngine::DIFFICULTIES) as $d): ?>
        <option value="<?= h($d) ?>"<?= $d === $difficulty ? ' selected' : '' ?>><?= h(ucfirst($d)) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="seed">Seed</label>
    <input type="text" name="seed" id="seed" value="<?= h($puzzle->meta['seed']) ?>" placeholder="random">

    <label for="clef">Clef</label>
    <select name="clef" id="clef">
      <?php foreach (StaffRenderer::clefModes() as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $clef ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="range">Range</label>
    <select name="range" id="range">
      <?php foreach (StaffRenderer::RANGE_MODES as $key => $label): ?>
        <?php if ($key === 'full' && !$variant->allowsFullRange()) { continue; } ?>
        <option value="<?= h($key) ?>"<?= $key === $rangeMode ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="spelling">Accidentals</label>
    <select name="spelling" id="spelling">
      <?php foreach (Variant::SPELLINGS as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $spelling ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="size">Staff</label>
    <input type="number" name="size" id="size" value="<?= h($staffSize) ?>" min="8" max="24"
           title="20 is the largest that still fits twelve staves on Letter portrait">

    <label><input type="checkbox" name="solved" value="1"<?= $solved ? ' checked' : '' ?>> solved</label>

    <button type="submit">Engrave</button>
    <a href="?difficulty=<?= h($difficulty) ?>">new seed</a>
    <a href="<?= h(link_to(['format' => 'svg'])) ?>">svg</a>
    <a href="<?= h(link_to(['format' => 'pdf'])) ?>">pdf</a>
    <a href="<?= h(link_to(['format' => 'ly'])) ?>">.ly</a>
    <a href="index.php?difficulty=<?= h($difficulty) ?>&amp;seed=<?= h($puzzle->meta['seed']) ?>">grid view</a>
  </form>

  <div class="legend">
    <?php foreach ($variant->legend($spelling) as $symbol => $name): ?>
      <span><b><?= h($symbol) ?></b> = <?= h($name) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($errors): ?>
    <div class="err"><strong>Puzzle verification failed:</strong> <?= h(implode('; ', $errors)) ?></div>
  <?php endif; ?>

  <?php foreach ($store->warnings() as $w): ?>
    <div class="err"><strong>Cache problem:</strong> <?= h($w) ?></div>
  <?php endforeach; ?>

  <?php if (!$render['ok']): ?>
    <div class="err">
      <strong>LilyPond failed.</strong>
      <?php if (StaffRenderer::version() === null): ?>
        The binary <code><?= h(StaffRenderer::binary()) ?></code> could not be run &mdash; it may not be
        on the web server&rsquo;s <code>PATH</code>. Set <code>LILYPOND_BIN</code> to its full path.
      <?php endif; ?>
      <pre><?= h($render['log']) ?></pre>
      <p style="margin:0">Command: <code><?= h($render['command']) ?></code></p>
    </div>
  <?php else: ?>
    <?php foreach ($render['warnings'] as $w): ?>
      <div class="warn"><?= h($w) ?></div>
    <?php endforeach; ?>

    <div class="score"><?= StaffRenderer::inlineSvg($render['content']) ?></div>

    <div class="meta">
      <span>Rating <b><?= h($puzzle->difficultyLabel()) ?></b></span>
      <span>Givens <b><?= h($puzzle->givenCount()) ?></b> / 144</span>
      <span>Tier <b><?= h($puzzle->analysis['maxTier']) ?></b> of 7</span>
      <span>Seed <b><?= h($puzzle->meta['seed']) ?></b></span>
      <span>ID <b><?= h($puzzle->id()) ?></b></span>
      <span>Clef <b><?= h(StaffRenderer::clefModes()[$clef] ?? $clef) ?></b> <?= h(StaffRenderer::range($variant, $clef, $rangeMode)) ?></span>
      <span>Set <b><?= h($variant->sizeLabel()) ?></b> boxes <?= h($variant->boxLabel()) ?></span>
      <span>LilyPond <b><?= h(StaffRenderer::version() ?? 'unknown') ?></b></span>
      <span>Puzzle <b><?= h(number_format($genTook, 2)) ?>s</b><?= $puzzleCached ? ' (cached)' : '' ?></span>
      <span>Engraved <b><?= h(number_format($took, 2)) ?>s</b><?= $render['cached'] ? ' (cached)' : '' ?></span>
    </div>
  <?php endif; ?>

  <details>
    <summary>LilyPond source</summary>
    <pre><?= h($ly) ?></pre>
  </details>


  <section class="answers">
    <h2>Answer key <span class="pid"><?= h($puzzle->id()) ?></span></h2>
    <p class="sub">
      Givens in black, solved cells in colour. Published at
      <a href="<?= h(PuzzleLink::url($puzzle)) ?>"><?= h(PuzzleLink::display($puzzle)) ?></a>
      &mdash; the id comes from the grid itself, so it names this puzzle and no other.
    </p>
    <div class="pv-wrap"><?= PuzzleView::answerKey($puzzle) ?></div>

    <h2 style="margin-top:2rem">Solving steps</h2>
    <p class="sub">
      <?= h($tally['places']) ?> placements and <?= h($tally['elims']) ?> elimination steps,
      in the order the solver derived them. Cells use <code>r1c1</code> notation;
      <code>T<em>n</em></code> is the technique tier.
      <label style="margin-left:.5rem"><input type="checkbox" id="elimToggle" checked>
        show eliminations</label>
    </p>
    <div class="pv-wrap" id="stepsWrap"><?= PuzzleView::steps($steps, $engine) ?></div>
  </section>

  <script>
    document.getElementById('elimToggle').addEventListener('change', function () {
      document.getElementById('stepsWrap').classList.toggle('pv-hide-elims', !this.checked);
    });
  </script>

  <footer>
    One score, twelve simultaneous staves &mdash; LilyPond aligns the bar lines across
    all of them, which is what lets you read down a column. Proportional spacing
    (<code>proportionalNotationDuration</code>) keeps empty measures exactly as wide as
    filled ones. Puzzles are memoised in <code>cache/puzzles/</code> by difficulty and
    seed, engravings in <code>cache/</code> by source hash &mdash; so a seed you have
    seen before costs nothing to redraw. Page is US Letter portrait, half-inch margins, giving a 190.5&nbsp;mm
    line &mdash; about 15.9&nbsp;mm per bar. Staff size 20 stacks the twelve staves to
    roughly 215&nbsp;mm of the 254&nbsp;mm printable height; larger sizes will overflow
    the page. Output is cached in <code>cache/</code> by content hash.
  </footer>

</div>
</body>
</html>
