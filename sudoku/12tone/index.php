<?php
declare(strict_types=1);

/**
 * Web renderer for 12x12 sudoku (4-wide x 3-tall boxes, symbols 0-9 A B).
 *
 *   index.php?difficulty=hard
 *   index.php?difficulty=expert&seed=12345      reproducible
 *   index.php?difficulty=hard&format=json       raw export
 */

require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';
require __DIR__ . '/lib/PuzzleStore.php';
require __DIR__ . '/lib/PuzzleLink.php';

@set_time_limit(180);

$variant    = Variant::get((string) ($_GET['variant'] ?? Variant::DEFAULT));
$difficulty = (string) ($_GET['difficulty'] ?? 'medium');
if (!isset(SudokuEngine::DIFFICULTIES[$difficulty])) {
    $difficulty = 'medium';
}
$seed   = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : null;
$format = (string) ($_GET['format'] ?? 'html');

$store   = new PuzzleStore(__DIR__ . '/cache/puzzles');
$t0      = microtime(true);
$engine  = SudokuEngine::forVariant($variant->key);
$puzzle  = $store->get($variant->key, $difficulty, $seed);
$errors  = $store->errorsFor($puzzle);
$puzzleTook = microtime(true) - $t0;

if ($format === 'json') {
    header('Content-Type: application/json');
    echo $puzzle->toJson();
    exit;
}

$walkT0    = microtime(true);
$stepsWere = $store->hasSteps($puzzle);
$steps     = $store->steps($puzzle);
$walkTook  = microtime(true) - $walkT0;

$placements = 0;
$deductions = 0;
foreach ($steps as $st) {
    if ($st['type'] === 'place') {
        $placements++;
    } else {
        $deductions++;
    }
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** Render one 12x12 grid as a table. */
function renderGrid(Puzzle $p, bool $solved): string
{
    $n = $p->size();
    $html = '<table class="grid">';
    for ($r = 0; $r < $n; $r++) {
        $html .= '<tr>';
        for ($c = 0; $c < $n; $c++) {
            $given = $p->isGiven($r, $c);
            $sym   = $solved ? $p->solutionSymbol($r, $c) : $p->symbol($r, $c);
            $html .= '<td class="' . ($given ? 'given' : 'blank') . '">' . h($sym) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}

/** Render the inference walkthrough: one row per deduction. */
function renderSteps(array $steps, SudokuEngine $engine): string
{
    $labels = SudokuEngine::techniqueLabels();

    $html = '<table class="steps"><thead><tr>'
          . '<th class="num">#</th><th>Cell</th><th class="sym">Symbol</th>'
          . '<th>Technique</th><th>Why</th>'
          . '</tr></thead><tbody>';

    $i = 0;
    foreach ($steps as $st) {
        $meta = $labels[$st['key']] ?? ['label' => $st['key'], 'tier' => 0];
        $tech = h($meta['label']) . ' <span class="tier">T' . h($meta['tier']) . '</span>';

        if ($st['type'] === 'place') {
            $i++;
            $html .= '<tr class="place">'
                   . '<td class="num">' . $i . '</td>'
                   . '<td class="cellref">' . h($engine->cellName($st['cell'])) . '</td>'
                   . '<td class="sym"><b>' . h($engine->symbolOf($st['value'])) . '</b></td>'
                   . '<td>' . $tech . '</td>'
                   . '<td class="why">' . h($st['detail']) . '</td>'
                   . '</tr>';
            continue;
        }

        $parts = [];
        foreach (array_slice($st['elims'], 0, 8) as $el) {
            $parts[] = h($engine->cellName($el['cell'])) . '&minus;' . h($engine->symbolsOf($el['mask']));
        }
        $extra   = count($st['elims']) - 8;
        $summary = implode(', ', $parts) . ($extra > 0 ? ', <i>+' . $extra . ' more</i>' : '');

        $html .= '<tr class="elim">'
               . '<td class="num"></td>'
               . '<td class="cellref">' . $summary . '</td>'
               . '<td class="sym">&ndash;</td>'
               . '<td>' . $tech . '</td>'
               . '<td class="why">' . h($st['detail']) . '</td>'
               . '</tr>';
    }

    return $html . '</tbody></table>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>12x12 Sudoku &mdash; <?= h($puzzle->difficultyLabel()) ?></title>
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
  .wrap { max-width: 68rem; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin: 0 0 .25rem; letter-spacing: -.01em; }
  .sub { color: var(--dim); margin: 0 0 1.75rem; font-size: .9rem; }

  form { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
         background: var(--panel); border: 1px solid var(--line);
         border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1.75rem; }
  label { font-size: .82rem; color: var(--dim); text-transform: uppercase; letter-spacing: .06em; }
  select, input[type=text] { font: inherit; padding: .3rem .5rem; border: 1px solid var(--line);
                             border-radius: 5px; background: #fff; color: var(--ink); }
  input[type=text] { width: 9rem; }
  button { font: inherit; padding: .35rem 1rem; border: 1px solid var(--rule);
           border-radius: 5px; background: var(--rule); color: #fff; cursor: pointer; }

  .cols { display: flex; gap: 2.5rem; align-items: flex-start; flex-wrap: wrap; }

  table.grid { border-collapse: collapse; background: var(--panel); border: 2.5px solid var(--rule); }
  table.grid td {
    width: 2.35rem; height: 2.35rem; text-align: center; vertical-align: middle;
    border: 1px solid var(--line);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.05rem;
  }
  /* box walls: 4 wide, 3 tall */
  table.grid td:nth-child(<?= $variant->boxWidth ?>n+1) { border-left: 2.5px solid var(--rule); }
  table.grid tr:nth-child(<?= $variant->boxHeight ?>n+1) td { border-top: 2.5px solid var(--rule); }
  table.grid td.given { font-weight: 600; }
  table.grid td.blank { color: var(--dim); background: #fdfdfc; }

  .panel { background: var(--panel); border: 1px solid var(--line);
           border-radius: 8px; padding: 1rem 1.25rem; min-width: 17rem; }
  .panel h2 { font-size: .78rem; text-transform: uppercase; letter-spacing: .08em;
              color: var(--dim); margin: 0 0 .75rem; font-weight: 600; }
  dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: .3rem 1rem; font-size: .9rem; }
  dt { color: var(--dim); }
  dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
  dd.badge { font-weight: 600; color: var(--accent); }

  details { margin-top: 2rem; }
  summary { cursor: pointer; font-size: .9rem; color: var(--accent); }
  details > div, details > pre, details > table { margin-top: 1rem; }
  pre { background: var(--panel); border: 1px solid var(--line); border-radius: 8px;
        padding: 1rem; overflow-x: auto; font-size: .78rem; line-height: 1.45; }

  table.steps { border-collapse: collapse; width: 100%; background: var(--panel);
                border: 1px solid var(--line); border-radius: 8px; font-size: .84rem; }
  table.steps th { text-align: left; font-size: .72rem; text-transform: uppercase;
                   letter-spacing: .07em; color: var(--dim); font-weight: 600;
                   padding: .55rem .75rem; border-bottom: 1px solid var(--line); }
  table.steps td { padding: .38rem .75rem; border-bottom: 1px solid #efeff1; vertical-align: top; }
  table.steps tr:last-child td { border-bottom: none; }
  table.steps .num { text-align: right; color: var(--faint); width: 3rem;
                     font-variant-numeric: tabular-nums; }
  table.steps .cellref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                         white-space: nowrap; }
  table.steps .sym { text-align: center; width: 4rem;
                     font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  table.steps .why { color: var(--dim); }
  table.steps .tier { color: var(--faint); font-size: .72rem; }
  table.steps tr.place { background: #fffdf9; }
  table.steps tr.elim td { color: var(--dim); }
  .steps-wrap { overflow-x: auto; }
  .toggle { font-size: .82rem; color: var(--dim); margin: 1rem 0 .25rem; display: block; }
  .hide-elims tr.elim { display: none; }

  .err { background: #fff2f0; border: 1px solid #d99; color: #922;
         padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: .9rem; }
  .ok { color: #2c6e49; }
  footer { margin-top: 3rem; color: var(--dim); font-size: .8rem; }
  a { color: var(--accent); }
</style>
</head>
<body>
<div class="wrap">

  <h1>12&times;12 Sudoku</h1>
  <p class="sub">
    Boxes 4 wide &times; 3 tall &mdash; 3 stacks, 4 bands. Symbols
    <code>0&ndash;9 A B</code>. Every puzzle below is verified unique and solvable
    by inference alone.
  </p>

  <form method="get">
    <label for="difficulty">Difficulty</label>
    <select name="difficulty" id="difficulty">
      <?php foreach (array_keys(SudokuEngine::DIFFICULTIES) as $d): ?>
        <option value="<?= h($d) ?>"<?= $d === $difficulty ? ' selected' : '' ?>><?= h(ucfirst($d)) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="seed">Seed</label>
    <input type="text" name="seed" id="seed" value="<?= h($puzzle->meta['seed']) ?>" placeholder="random">
    <button type="submit">Generate</button>
    <a href="?difficulty=<?= h($difficulty) ?>">new seed</a>
    <a href="?difficulty=<?= h($difficulty) ?>&amp;seed=<?= h($puzzle->meta['seed']) ?>&amp;format=json">json</a>
  </form>

  <?php if ($errors): ?>
    <div class="err"><strong>Verification failed:</strong> <?= h(implode('; ', $errors)) ?></div>
  <?php endif; ?>

  <?php foreach ($store->warnings() as $w): ?>
    <div class="err"><strong>Cache problem:</strong> <?= h($w) ?></div>
  <?php endforeach; ?>

  <div class="cols">
    <div><?= renderGrid($puzzle, false) ?></div>

    <div class="panel">
      <h2>Grading</h2>
      <dl>
        <dt>Rating</dt>       <dd class="badge"><?= h($puzzle->difficultyLabel()) ?></dd>
        <dt>Requested</dt>    <dd><?= h($puzzle->meta['difficulty']) ?></dd>
        <dt>Givens</dt>       <dd><?= h($puzzle->givenCount()) ?> / 144</dd>
        <dt>Hardest tier</dt> <dd><?= h($puzzle->analysis['maxTier']) ?> of 7</dd>
        <dt>Score</dt>        <dd><?= h($puzzle->analysis['score']) ?></dd>
        <dt>Seed</dt>         <dd><?= h($puzzle->meta['seed']) ?></dd>
        <dt>Puzzle ID</dt>    <dd><code><?= h($puzzle->id()) ?></code></dd>
      </dl>

      <h2 style="margin-top:1.25rem">Techniques required</h2>
      <dl>
        <?php foreach ($puzzle->analysis['techniques'] as $name => $uses): ?>
          <dt><?= h($name) ?></dt><dd><?= h($uses) ?></dd>
        <?php endforeach; ?>
      </dl>

      <h2 style="margin-top:1.25rem">Verification</h2>
      <dl>
        <dt>Unique solution</dt><dd class="<?= $errors ? '' : 'ok' ?>"><?= $errors ? 'no' : 'yes' ?></dd>
        <dt>No guessing</dt>   <dd class="<?= $errors ? '' : 'ok' ?>"><?= $errors ? 'no' : 'yes' ?></dd>
        <dt>Generated in</dt>  <dd><?= h(number_format($puzzleTook, 2)) ?>s</dd>
        <dt>Walkthrough</dt>   <dd><?= h(number_format($walkTook, 2)) ?>s<?= $stepsWere ? '' : ' *' ?></dd>
      </dl>
    </div>
  </div>

  <details>
    <summary>Show solution &amp; how it is solved</summary>

    <div><?= renderGrid($puzzle, true) ?></div>

    <label class="toggle">
      <input type="checkbox" id="elimToggle" checked>
      show candidate eliminations as well as placements
    </label>
    <p class="sub" style="margin:.25rem 0 0">
      <?= h($placements) ?> placements and <?= h($deductions) ?> elimination steps,
      in the order the solver derived them. Cells use standard
      <code>r&#8321;c&#8321;</code> notation; <code>T<em>n</em></code> is the technique tier.
    </p>

    <div class="steps-wrap" id="stepsWrap">
      <?= renderSteps($steps, $engine) ?>
    </div>
  </details>

  <details>
    <summary>JSON export</summary>
    <pre><?= h($puzzle->toJson()) ?></pre>
  </details>

  <footer>
    Internal representation is <code>Puzzle</code> &mdash; flat arrays of n&sup2; ints,
    <code>0</code> meaning empty and <code>1&ndash;12</code> the symbols. JSON is an
    export format only: <code>Puzzle::toJson()</code> /
    <code>Puzzle::fromJson()</code> round-trip it.
  </footer>

</div>
<script>
  document.getElementById('elimToggle').addEventListener('change', function () {
    document.getElementById('stepsWrap').classList.toggle('hide-elims', !this.checked);
  });
</script>
</body>
</html>
