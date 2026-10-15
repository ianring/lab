<?php
declare(strict_types=1);

/**
 * 100 generated sigils, for judging whether the aesthetic holds.
 *
 * Every constant from the analysis is a control, so the rules can be tuned
 * against the photograph by eye rather than guessed at. The seed makes any
 * sheet you like reproducible from its URL.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/Glyph.php';

$count = max(1, min(600, (int) ($_GET['count'] ?? 100)));
$size  = max(40, min(260, (int) ($_GET['size'] ?? 96)));
$seed  = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : random_int(0, 999999);
$cell  = !empty($_GET['cell']);
$ink   = preg_match('/^#[0-9a-f]{6}$/i', (string) ($_GET['ink'] ?? '')) ? (string) $_GET['ink'] : '#1d4f3f';

$opt = [];
foreach (Glyph::DEFAULTS as $k => $v) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $opt[$k] = is_int($v) ? (int) $_GET[$k] : (float) $_GET[$k];
    }
}
$cfg = array_merge(Glyph::DEFAULTS, $opt);

mt_srand($seed);
$glyphs = [];
for ($i = 0; $i < $count; $i++) {
    $glyphs[] = Glyph::generate($opt);
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

/** Controls, grouped so the panel reads in the same order as the rules. */
$groups = [
    'Skeleton' => ['lattice' => 'Lattice', 'minSeg' => 'Min segs', 'maxSeg' => 'Max segs',
                   'maxStep' => 'Max step', 'pClose' => 'Close loop', 'pBranch' => 'Branch',
                   'pSpur' => 'Spur'],
    'Angles'   => ['wOrtho' => 'Orthogonal', 'wDiag' => '45 deg'],
    'Lengths'  => ['wLen1' => '1 step', 'wLen2' => '2 steps',
                   'wLen3' => '3 steps', 'wLen4' => '4 steps'],
    'Rings'    => ['pTwoRings' => 'Two rings', 'pTangent' => 'Tangent',
                   'ring' => 'Ring r', 'pCornerStart' => 'Start corner'],
    'Accessory'=> ['pAccessory' => 'Chance', 'wDots' => 'Dots', 'wDot' => 'One dot',
                   'wStub' => 'Stub'],
    'Rates'    => ['pBundle' => 'Bundle', 'bundleN' => 'Bars', 'pBundle4' => 'Four bars',
                   'pDots' => 'Dots', 'parallelGap' => 'Parallel gap'],
    'Density'  => ['fillGap' => 'Void size', 'maxFill' => 'Max fills'],
    'Drawing'  => ['stroke' => 'Weight', 'margin' => 'Margin', 'jitter' => 'Jitter',
                   'clearance' => 'Clearance'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sigils</title>
<style>
  :root { --ink:#2b2723; --dim:#7a736a; --line:#d7cfc1; --bg:#efe9dd; --panel:#faf6ee; }
  * { box-sizing:border-box; }
  body { margin:0; padding:1.25rem 1rem 4rem; background:var(--bg); color:var(--ink);
         font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
  .wrap { max-width:84rem; margin:0 auto; }
  h1 { font-size:1.1rem; margin:0 0 .2rem; }
  .sub { color:var(--dim); font-size:.84rem; margin:0 0 1rem; max-width:56rem; }
  a { color:#6b5a2e; }

  form { background:var(--panel); border:1px solid var(--line); border-radius:8px;
         padding:.8rem 1rem; margin-bottom:1.1rem; }
  .row { display:flex; gap:1.1rem; flex-wrap:wrap; align-items:center; margin-bottom:.55rem; }
  .row:last-child { margin-bottom:0; }
  .glabel { font-size:.64rem; text-transform:uppercase; letter-spacing:.09em; color:var(--dim);
            min-width:5rem; font-weight:600; }
  .field { display:flex; gap:.3rem; align-items:center; }
  label { font-size:.7rem; color:var(--dim); }
  input[type=number], input[type=text] { font:inherit; font-size:.8rem; width:4.2rem;
    padding:.18rem .3rem; border:1px solid var(--line); border-radius:4px; background:#fff; }
  button { font:inherit; font-size:.85rem; padding:.3rem 1.1rem; border:1px solid #6b5a2e;
           background:#6b5a2e; color:#fff; border-radius:5px; cursor:pointer; }

  .sheet { display:grid; grid-template-columns:repeat(auto-fill, minmax(<?= $size + 26 ?>px, 1fr));
           gap:.45rem; background:var(--panel); border:1px solid var(--line);
           border-radius:8px; padding:1rem; }
  .cellwrap { display:flex; align-items:center; justify-content:center; padding:.55rem; }
  .glyph { display:block; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Sigils</h1>
  <p class="sub">
    Endpoints snap to a lattice, so the angles fall out rather than being chosen.
    A self-avoiding walk of 1&ndash;4 segments, rings capping ends and corners only,
    and one detached accessory filling the space left over.
    Seed <?= h($seed) ?> &middot; <a href="<?= h(link_with(['seed' => null])) ?>">new sheet</a>
  </p>

  <form method="get">
    <?php foreach ($groups as $title => $fields): ?>
      <div class="row">
        <span class="glabel"><?= h($title) ?></span>
        <?php foreach ($fields as $key => $label): ?>
          <div class="field">
            <label for="<?= h($key) ?>"><?= h($label) ?></label>
            <input type="number" id="<?= h($key) ?>" name="<?= h($key) ?>"
                   value="<?= h($cfg[$key]) ?>"
                   step="<?= is_int(Glyph::DEFAULTS[$key]) ? '1' : '0.005' ?>">
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="row">
      <span class="glabel">Sheet</span>
      <div class="field"><label for="count">Count</label>
        <input type="number" id="count" name="count" value="<?= h($count) ?>" step="10"></div>
      <div class="field"><label for="size">Size</label>
        <input type="number" id="size" name="size" value="<?= h($size) ?>" step="8"></div>
      <div class="field"><label for="ink">Ink</label>
        <input type="text" id="ink" name="ink" value="<?= h($ink) ?>"></div>
      <div class="field"><label for="seed">Seed</label>
        <input type="text" id="seed" name="seed" value="<?= h($seed) ?>"></div>
      <div class="field"><label for="cell">Cell</label>
        <input type="checkbox" id="cell" name="cell" value="1"<?= $cell ? ' checked' : '' ?>></div>
      <button type="submit">Draw</button>
    </div>
  </form>

  <div class="sheet">
    <?php foreach ($glyphs as $g): ?>
      <div class="cellwrap"><?= $g->svg((float) $size, $cell, $ink) ?></div>
    <?php endforeach; ?>
  </div>

</div>
</body>
</html>
