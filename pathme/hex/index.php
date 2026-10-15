<?php
declare(strict_types=1);

/**
 * Every possible hexagonal tile, for picking the ones that belong in the set.
 *
 * A shape is the set of edges it touches - a 6-bit mask, 64 shapes in 14
 * classes. A tile assigns every edge to one shape, so a tile is a set partition
 * of the six edges: 203 of them, 43 up to rotation, 37 up to rotation + mirror.
 *
 * Click a tile to cycle it in -> out -> undecided. Decisions are stored against
 * the ROTATION CLASS, so judging one orientation settles all six.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/HexTile.php';
require __DIR__ . '/lib/HexRenderer.php';

$view    = (string) ($_GET['view'] ?? 'rot');     // rot | dih | all
$filter  = (string) ($_GET['filter'] ?? '');      // in | out | undecided
$shape   = (string) ($_GET['shape'] ?? '');       // a shape class name
$hideOv  = !empty($_GET['nooverlap']);
$size    = max(70, min(320, (int) ($_GET['size'] ?? 150)));

$renderer = new HexRenderer([
    'port'     => (float) ($_GET['port'] ?? HexRenderer::DEFAULTS['port']),
    'strokeW'  => (float) ($_GET['stroke'] ?? HexRenderer::DEFAULTS['strokeW']),
    'overRule' => ($_GET['over'] ?? '') === 'large' ? 'large' : 'small',
] + HexRenderer::coloursFrom($_GET) + [
    'showRim'  => empty($_GET['norim']),
]);

$chosen = [];
if (is_file(__DIR__ . '/chosen.json')) {
    $chosen = json_decode((string) file_get_contents(__DIR__ . '/chosen.json'), true) ?: [];
}

$all = HexTile::enumerate();

/** key a tile by the grouping in force, so a decision covers its whole orbit */
$keyOf = static function (HexTile $t) use ($view): string {
    if ($view === 'all') {
        return $t->id();
    }
    return $t->canonical($view === 'dih');
};

$tiles = [];
$orbit = [];
foreach ($all as $t) {
    $k = $keyOf($t);
    $orbit[$k] = ($orbit[$k] ?? 0) + 1;
    if ($view !== 'all' && isset($tiles[$k])) {
        continue;
    }
    $tiles[$k] = $t;
}

$shapeCounts = [];
foreach ($tiles as $t) {
    foreach ($t->shapeNames() as $n) {
        $shapeCounts[$n] = ($shapeCounts[$n] ?? 0) + 1;
    }
}
ksort($shapeCounts);

$shown = [];
foreach ($tiles as $k => $t) {
    $state = $chosen[$k] ?? 'undecided';
    if ($filter !== '' && $state !== $filter) {
        continue;
    }
    if ($hideOv && $t->hasOverlap()) {
        continue;
    }
    if ($shape !== '' && !in_array($shape, $t->shapeNames(), true)) {
        continue;
    }
    $shown[$k] = $t;
}

$tally = ['in' => 0, 'out' => 0, 'undecided' => 0];
foreach ($tiles as $k => $t) {
    $tally[$chosen[$k] ?? 'undecided']++;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
function link_with(array $over): string
{
    $q = array_merge($_GET, $over);
    return '?' . http_build_query(array_filter($q, static fn ($v): bool => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hex tiles &mdash; choose the set</title>
<style>
  :root { --ink:#eae4da; --dim:#9a8f80; --line:#3b332b; --bg:#141210; --panel:#1e1a16;
          --accent:#c2a276; --in:#79b36a; --out:#c0524a; }
  * { box-sizing:border-box; }
  body { margin:0; padding:1.25rem 1rem 4rem; background:var(--bg); color:var(--ink);
         font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
  .wrap { max-width:112rem; margin:0 auto; }
  h1 { font-size:1.2rem; margin:0 0 .2rem; }
  .sub { color:var(--dim); font-size:.84rem; margin:0 0 1rem; max-width:62rem; }
  a { color:var(--accent); }

  form { background:var(--panel); border:1px solid var(--line); border-radius:8px;
         padding:.75rem 1rem; margin-bottom:.75rem; display:flex; gap:1rem;
         align-items:center; flex-wrap:wrap; }
  label { font-size:.7rem; text-transform:uppercase; letter-spacing:.07em; color:var(--dim); }
  input[type=color] { width:2.4rem; height:1.7rem; padding:1px; background:#0f0d0b;
    border:1px solid var(--line); border-radius:4px; cursor:pointer; }
  input[type=number], select { font:inherit; font-size:.85rem; padding:.2rem .35rem;
    background:#0f0d0b; color:var(--ink); border:1px solid var(--line); border-radius:4px; }
  input[type=number] { width:4.6rem; }
  button { font:inherit; padding:.28rem 1rem; border:1px solid var(--accent);
           background:var(--accent); color:#1b1815; border-radius:5px; cursor:pointer; }
  .field { display:flex; gap:.35rem; align-items:center; }

  .chips { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:1rem; font-size:.76rem; }
  .chips a { border:1px solid var(--line); border-radius:999px; padding:.12rem .6rem;
             text-decoration:none; color:var(--dim); }
  .chips a.on { border-color:var(--accent); color:var(--accent); }

  .tally { font-size:.82rem; color:var(--dim); margin-bottom:.85rem; }
  .tally b { color:var(--ink); }
  .tally .i { color:var(--in); } .tally .o { color:var(--out); }

  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(<?= $size + 18 ?>px, 1fr)); gap:.75rem; }
  .cell { text-align:center; cursor:pointer; border-radius:8px; padding:.35rem .2rem .3rem;
          border:2px solid transparent; background:#100e0c; user-select:none; }
  .cell:hover { border-color:var(--line); }
  .cell.in  { border-color:var(--in);  background:#16200f; }
  .cell.out { border-color:var(--out); background:#1f100e; opacity:.55; }
  .cell svg { display:block; margin:0 auto; border-radius:3px; }
  .cap { font-family:ui-monospace, Menlo, monospace; font-size:.62rem; color:var(--dim);
         margin-top:.25rem; line-height:1.3; word-break:break-all; }
  .cap .names { color:#7c7266; }
  .ov { color:#c99; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Choose the tile set</h1>
  <p class="sub">
    A shape is the set of edges it touches &mdash; a 6-bit mask, so 64 shapes in 14 classes.
    A tile hands every edge to exactly one shape, giving <strong>203</strong> tiles,
    <strong>43</strong> up to rotation. <strong>Click a tile</strong> to cycle it
    in &rarr; out &rarr; undecided. Decisions are stored against the rotation class, so
    judging one orientation settles all of them.
  </p>

  <form method="get">
    <div class="field"><label for="view">Group</label>
      <select name="view" id="view">
        <option value="rot"<?= $view === 'rot' ? ' selected' : '' ?>>up to rotation (43)</option>
        <option value="dih"<?= $view === 'dih' ? ' selected' : '' ?>>up to rotation + mirror (37)</option>
        <option value="all"<?= $view === 'all' ? ' selected' : '' ?>>every orientation (203)</option>
      </select></div>
    <div class="field"><label for="filter">Show</label>
      <select name="filter" id="filter">
        <option value=""<?= $filter === '' ? ' selected' : '' ?>>everything</option>
        <option value="undecided"<?= $filter === 'undecided' ? ' selected' : '' ?>>undecided</option>
        <option value="in"<?= $filter === 'in' ? ' selected' : '' ?>>in</option>
        <option value="out"<?= $filter === 'out' ? ' selected' : '' ?>>out</option>
      </select></div>
    <div class="field"><label for="port">Port</label>
      <input type="number" step="0.02" min="0.1" max="0.95" name="port" id="port" value="<?= h($renderer->o['port']) ?>"></div>
    <div class="field"><label for="stroke">Outline</label>
      <input type="number" step="0.5" min="0" max="10" name="stroke" id="stroke" value="<?= h($renderer->o['strokeW']) ?>"></div>
    <div class="field"><label for="over">On top</label>
      <select name="over" id="over">
        <option value="small"<?= $renderer->o['overRule'] === 'small' ? ' selected' : '' ?>>fewer edges</option>
        <option value="large"<?= $renderer->o['overRule'] === 'large' ? ' selected' : '' ?>>more edges</option>
      </select></div>
    <div class="field"><label for="fill">Shape</label>
      <input type="color" name="fill" id="fill" value="<?= h($renderer->o['fill']) ?>"></div>
    <div class="field"><label for="bg">Ground</label>
      <input type="color" name="bg" id="bg" value="<?= h($renderer->o['bg']) ?>"></div>
    <div class="field"><label for="ink">Seam</label>
      <input type="color" name="ink" id="ink" value="<?= h($renderer->o['stroke']) ?>"></div>
    <div class="field"><label for="size">Size</label>
      <input type="number" step="10" name="size" id="size" value="<?= h($size) ?>"></div>
    <div class="field"><label for="nooverlap">Hide overlaps</label>
      <input type="checkbox" name="nooverlap" id="nooverlap" value="1"<?= $hideOv ? ' checked' : '' ?>></div>
    <?php if ($shape !== ''): ?><input type="hidden" name="shape" value="<?= h($shape) ?>"><?php endif; ?>
    <button type="submit">Redraw</button>
  </form>

  <div class="chips">
    <?php foreach (HexRenderer::PALETTES as $key => $pal): ?>
      <a href="<?= h(link_with(['palette' => $key, 'bg' => null, 'fill' => null, 'ink' => null])) ?>"
         style="border-color:<?= h($pal[1]) ?>">
        <span style="display:inline-block;width:.6rem;height:.6rem;border-radius:50%;
                     background:<?= h($pal[1]) ?>;border:1px solid <?= h($pal[0]) ?>"></span>
        <?= h($pal[3]) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="chips">
    <a href="<?= h(link_with(['shape' => null])) ?>" class="<?= $shape === '' ? 'on' : '' ?>">any shape</a>
    <?php foreach ($shapeCounts as $name => $n): ?>
      <a href="<?= h(link_with(['shape' => $name])) ?>" class="<?= $shape === $name ? 'on' : '' ?>"><?= h($name) ?> (<?= h($n) ?>)</a>
    <?php endforeach; ?>
  </div>

  <div class="tally">
    <?= count($shown) ?> shown of <?= count($tiles) ?> &nbsp;&middot;&nbsp;
    <span class="i">in <b id="tin"><?= $tally['in'] ?></b></span> &nbsp;
    <span class="o">out <b id="tout"><?= $tally['out'] ?></b></span> &nbsp;
    undecided <b id="tund"><?= $tally['undecided'] ?></b>
  </div>

  <div class="grid">
    <?php foreach ($shown as $k => $tile): ?>
      <?php $state = $chosen[$k] ?? 'undecided'; ?>
      <div class="cell <?= h($state === 'undecided' ? '' : $state) ?>"
           data-id="<?= h($k) ?>" data-state="<?= h($state) ?>">
        <?= $renderer->svg($tile, (float) $size) ?>
        <div class="cap">
          <?= h($tile->label()) ?><?php if ($tile->hasOverlap()): ?> <span class="ov">overlap</span><?php endif; ?>
          <br><span class="names"><?= h(implode(' + ', $tile->shapeNames())) ?></span>
          <?php if ($view !== 'all' && ($orbit[$k] ?? 1) > 1): ?>
            <br><span class="names">&times;<?= h($orbit[$k]) ?> orientations</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</div>
<script>
const NEXT = { undecided: 'in', in: 'out', out: 'clear' };
document.querySelectorAll('.cell').forEach(cell => {
  cell.addEventListener('click', async () => {
    const now  = cell.dataset.state;
    const want = NEXT[now] || 'in';
    const body = new URLSearchParams({ id: cell.dataset.id, state: want });
    try {
      const res  = await fetch('choose.php', { method: 'POST', body });
      // Read as text first: if the server hands back an HTML error page
      // instead of JSON, show that rather than a useless parse failure.
      const raw  = await res.text();
      let json;
      try { json = JSON.parse(raw); }
      catch (e) { alert('choose.php did not return JSON (HTTP ' + res.status + '):\n\n' + raw.slice(0, 1200)); return; }
      if (!json.ok) { alert(json.error + (json.at ? '\n\nat ' + json.at : '')); return; }
      const shown = want === 'clear' ? 'undecided' : want;
      cell.dataset.state = shown;
      cell.classList.toggle('in',  shown === 'in');
      cell.classList.toggle('out', shown === 'out');
      document.getElementById('tin').textContent  = json.counts.in;
      document.getElementById('tout').textContent = json.counts.out;
    } catch (e) {
      alert('save failed: ' + e);
    }
  });
});
</script>
</body>
</html>
