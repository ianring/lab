<?php
declare(strict_types=1);

/**
 * Tiles the plane with the shapes you kept.
 *
 * Reads chosen.json, takes every tile marked "in", and lays them out on a
 * pointy-top hex grid picking one at random for each cell, at a random rotation
 * and optionally mirrored.
 *
 * No edge matching is needed. A tile hands every one of its six edges to some
 * shape, so every edge always carries a port of the same width in the same
 * place - which means any tile fits beside any other. That is the whole reason
 * this can be random rather than a constraint solve.
 *
 *   tiling.php?cols=14&rows=10&seed=42&mirror=1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/HexTile.php';
require __DIR__ . '/lib/HexRenderer.php';

$cols   = max(1, min(60, (int) ($_GET['cols'] ?? 12)));
$rows   = max(1, min(60, (int) ($_GET['rows'] ?? 9)));
$radius = max(14, min(200, (int) ($_GET['radius'] ?? 60)));
$mirror = !isset($_GET['mirror']) || $_GET['mirror'] !== '0';
$rotate = !isset($_GET['rotate']) || $_GET['rotate'] !== '0';
$grid   = !empty($_GET['grid']);
$seed   = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : random_int(0, 999999);

$renderer = new HexRenderer([
    'radius'   => (float) $radius,
    'port'     => (float) ($_GET['port'] ?? HexRenderer::DEFAULTS['port']),
    'strokeW'  => (float) ($_GET['stroke'] ?? HexRenderer::DEFAULTS['strokeW']),
    'overRule' => ($_GET['over'] ?? '') === 'large' ? 'large' : 'small',
] + HexRenderer::coloursFrom($_GET) + [
    'showRim'  => false,
]);

// ------------------------------------------------------------- the chosen set

$chosen = [];
if (is_file(__DIR__ . '/chosen.json')) {
    $chosen = json_decode((string) file_get_contents(__DIR__ . '/chosen.json'), true) ?: [];
}
$ids = array_keys(array_filter($chosen, static fn ($v): bool => $v === 'in'));

$pool    = [];
$badIds  = [];
foreach ($ids as $id) {
    try {
        $pool[] = HexTile::fromId((string) $id);
    } catch (\Throwable $e) {
        $badIds[] = $id . ' (' . $e->getMessage() . ')';
    }
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

// ------------------------------------------------------------------- layout

$svg = '';
if ($pool) {
    mt_srand($seed);

    [$dx, $dy] = $renderer->spacing();
    $W = $cols * $dx;
    $H = ($rows - 1) * $dy + 2 * $radius;

    // draw one ring beyond the frame so the edges are filled, then let the
    // viewBox crop it
    $body = '';
    for ($r = -1; $r <= $rows; $r++) {
        for ($c = -1; $c <= $cols; $c++) {
            $tile = $pool[mt_rand(0, count($pool) - 1)];

            if ($rotate || $mirror) {
                $rot  = $rotate ? mt_rand(0, 5) : 0;
                $flip = $mirror && mt_rand(0, 1) === 1;
                $tile = $tile->transform(
                    static fn (int $e): int => $flip
                        ? ((($rot - $e) % 6) + 6) % 6
                        : ($e + $rot) % 6
                );
            }

            $x = $c * $dx + ($r & 1 ? $dx / 2 : 0);
            $y = $r * $dy;

            $body .= '<g transform="translate(' . round($x, 2) . ',' . round($y, 2) . ')">'
                  . $renderer->body($tile)
                  . ($grid ? $renderer->rimPath() : '')
                  . '</g>';
        }
    }

    $svg = '<svg width="100%" viewBox="' . (-$dx / 2) . ' ' . (-$radius) . ' ' . $W . ' ' . $H . '" '
         . 'preserveAspectRatio="xMidYMin meet" style="display:block">'
         . '<rect x="' . (-$dx) . '" y="' . (-2 * $radius) . '" width="' . ($W + 2 * $dx)
         . '" height="' . ($H + 4 * $radius) . '" fill="' . $renderer->o['bg'] . '"/>'
         . $body
         . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hex tiling</title>
<style>
  :root { --ink:#eae4da; --dim:#9a8f80; --line:#3b332b; --bg:#141210; --panel:#1e1a16; --accent:#c2a276; }
  * { box-sizing:border-box; }
  body { margin:0; padding:1rem 1rem 3rem; background:var(--bg); color:var(--ink);
         font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
  .wrap { max-width:120rem; margin:0 auto; }
  h1 { font-size:1.15rem; margin:0 0 .2rem; }
  .sub { color:var(--dim); font-size:.83rem; margin:0 0 .9rem; }
  a { color:var(--accent); }
  form { background:var(--panel); border:1px solid var(--line); border-radius:8px;
         padding:.7rem 1rem; margin-bottom:.9rem; display:flex; gap:1rem;
         align-items:center; flex-wrap:wrap; }
  label { font-size:.7rem; text-transform:uppercase; letter-spacing:.07em; color:var(--dim); }
  input[type=color] { width:2.4rem; height:1.7rem; padding:1px; background:#0f0d0b;
    border:1px solid var(--line); border-radius:4px; cursor:pointer; }
  input[type=number], input[type=text], select { font:inherit; font-size:.85rem; padding:.2rem .35rem;
    background:#0f0d0b; color:var(--ink); border:1px solid var(--line); border-radius:4px; width:5rem; }
  select { width:auto; }
  button { font:inherit; padding:.28rem 1rem; border:1px solid var(--accent);
           background:var(--accent); color:#1b1815; border-radius:5px; cursor:pointer; }
  .field { display:flex; gap:.35rem; align-items:center; }
  .chips { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:.85rem; font-size:.76rem; }
  .chips a { border:1px solid var(--line); border-radius:999px; padding:.12rem .6rem;
             text-decoration:none; color:var(--dim); display:flex; align-items:center; gap:.35rem; }
  .plane { border:1px solid var(--line); border-radius:8px; overflow:hidden; }
  .warn { background:#26200f; border:1px solid #7a6a2e; color:#e6d9a8;
          padding:.9rem 1.1rem; border-radius:8px; margin-bottom:1rem; font-size:.88rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Hex tiling</h1>
  <p class="sub">
    <?= count($pool) ?> tile<?= count($pool) === 1 ? '' : 's' ?> in the set &middot;
    seed <?= h($seed) ?> &middot;
    <a href="<?= h(link_with(['seed' => null])) ?>">new seed</a> &middot;
    <a href="index.php">back to choosing</a>
  </p>

  <?php if ($badIds): ?>
    <div class="warn"><strong>Skipped unreadable ids:</strong> <?= h(implode(', ', $badIds)) ?></div>
  <?php endif; ?>

  <?php if (!$pool): ?>
    <div class="warn">
      <strong>Nothing chosen yet.</strong>
      Open <a href="index.php">index.php</a> and click some tiles to mark them <em>in</em>.
      They are stored in <code>chosen.json</code>; this page reads the ones marked <code>in</code>.
    </div>
  <?php else: ?>

  <div class="chips">
    <?php foreach (HexRenderer::PALETTES as $key => $pal): ?>
      <a href="<?= h(link_with(['palette' => $key, 'bg' => null, 'fill' => null, 'ink' => null])) ?>"
         style="border-color:<?= h($pal[1]) ?>">
        <span style="display:inline-block;width:.6rem;height:.6rem;border-radius:50%;
                     background:<?= h($pal[1]) ?>;border:1px solid <?= h($pal[0]) ?>"></span>
        <?= h($pal[3]) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get">
    <div class="field"><label for="cols">Cols</label>
      <input type="number" name="cols" id="cols" value="<?= h($cols) ?>" min="1" max="60"></div>
    <div class="field"><label for="rows">Rows</label>
      <input type="number" name="rows" id="rows" value="<?= h($rows) ?>" min="1" max="60"></div>
    <div class="field"><label for="radius">Tile</label>
      <input type="number" name="radius" id="radius" value="<?= h($radius) ?>" min="14" max="200"></div>
    <div class="field"><label for="port">Port</label>
      <input type="number" step="0.02" name="port" id="port" value="<?= h($renderer->o['port']) ?>"></div>
    <div class="field"><label for="stroke">Outline</label>
      <input type="number" step="0.5" name="stroke" id="stroke" value="<?= h($renderer->o['strokeW']) ?>"></div>
    <div class="field"><label for="over">On top</label>
      <select name="over" id="over">
        <option value="small"<?= $renderer->o['overRule'] === 'small' ? ' selected' : '' ?>>fewer edges</option>
        <option value="large"<?= $renderer->o['overRule'] === 'large' ? ' selected' : '' ?>>more edges</option>
      </select></div>
    <div class="field"><label for="rotate">Rotate</label>
      <input type="hidden" name="rotate" value="0">
      <input type="checkbox" name="rotate" id="rotate" value="1"<?= $rotate ? ' checked' : '' ?>></div>
    <div class="field"><label for="mirror">Mirror</label>
      <input type="hidden" name="mirror" value="0">
      <input type="checkbox" name="mirror" id="mirror" value="1"<?= $mirror ? ' checked' : '' ?>></div>
    <div class="field"><label for="grid">Grid</label>
      <input type="checkbox" name="grid" id="grid" value="1"<?= $grid ? ' checked' : '' ?>></div>
    <div class="field"><label for="fill">Shape</label>
      <input type="color" name="fill" id="fill" value="<?= h($renderer->o['fill']) ?>"></div>
    <div class="field"><label for="bg">Ground</label>
      <input type="color" name="bg" id="bg" value="<?= h($renderer->o['bg']) ?>"></div>
    <div class="field"><label for="ink">Seam</label>
      <input type="color" name="ink" id="ink" value="<?= h($renderer->o['stroke']) ?>"></div>
    <div class="field"><label for="seed">Seed</label>
      <input type="text" name="seed" id="seed" value="<?= h($seed) ?>"></div>
    <button type="submit">Lay out</button>
  </form>

  <div class="plane"><?= $svg ?></div>

  <?php endif; ?>
</div>
</body>
</html>
