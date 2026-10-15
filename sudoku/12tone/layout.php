<?php
declare(strict_types=1);

/**
 * Interactive harness for fiddling with the PDF page layout.
 *
 * Every control maps to a PageOptions field, so the form and pdf.php stay in
 * step automatically. The preview pane is just pdf.php with the same query.
 */

require __DIR__ . '/lib/errors.php';
require __DIR__ . '/lib/Variant.php';
require __DIR__ . '/lib/SudokuEngine.php';
require __DIR__ . '/lib/Puzzle.php';
require __DIR__ . '/lib/PuzzleStore.php';
require __DIR__ . '/lib/StaffRenderer.php';
require __DIR__ . '/lib/PdfSupport.php';
require __DIR__ . '/lib/PdfPage.php';
require __DIR__ . '/lib/PageOptions.php';
require __DIR__ . '/lib/PuzzleView.php';
require __DIR__ . '/lib/PuzzleLink.php';

@set_time_limit(240);

const CACHE_DIR = __DIR__ . '/cache';

$variant    = Variant::get((string) ($_GET['variant'] ?? Variant::DEFAULT));
$difficulty = (string) ($_GET['difficulty'] ?? 'medium');
if (!isset(SudokuEngine::DIFFICULTIES[$difficulty])) {
    $difficulty = 'medium';
}
$seed      = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int) $_GET['seed'] : null;
$solved    = !empty($_GET['solved']);
$staffSize = max(8, min(24, (int) ($_GET['size'] ?? StaffRenderer::DEFAULT_STAFF_SIZE)));
$clef      = StaffRenderer::clefName((string) ($_GET['clef'] ?? StaffRenderer::DEFAULT_CLEF));
$spelling  = isset(Variant::SPELLINGS[(string) ($_GET['spelling'] ?? '')]) ? (string) $_GET['spelling'] : 'sharps';
$rangeMode = StaffRenderer::rangeMode((string) ($_GET['range'] ?? StaffRenderer::DEFAULT_RANGE), $variant);

StaffRenderer::useCacheDir(CACHE_DIR);
$store  = new PuzzleStore(CACHE_DIR . '/puzzles');
$puzzle = $store->get($variant->key, $difficulty, $seed);

$engine = SudokuEngine::forVariant($variant->key);
$steps  = $store->steps($puzzle);
$tally  = PuzzleView::tally($steps);

$opts   = PageOptions::fromQuery($_GET);
$page   = new PdfPage($opts);
$status = PdfSupport::status();

// Build once here purely to surface errors and notes next to the controls;
// the preview iframe re-runs it, but both hit the same cached engraving.
$buildError = null;
$buildStray = '';
$engraved   = null;
if ($status['ok']) {
    $prevLevel = error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    try {
        $engraved = $page->engrave($puzzle, CACHE_DIR, $solved, $staffSize, $clef, $spelling, $rangeMode);
        if ($engraved['ok']) {
            $page->build($puzzle, (string) $engraved['path']);
        } else {
            $buildError = "LilyPond failed:\n" . $engraved['log'];
        }
    } catch (\Throwable $e) {
        $buildError = $e->getMessage();
    }
    $buildStray = (string) ob_get_clean();
    error_reporting($prevLevel);
}

$query = array_merge(
    [
        'variant'    => $variant->key,
        'difficulty' => $difficulty,
        'seed'       => (string) $puzzle->meta['seed'],
        'size'       => (string) $staffSize,
        'clef'       => $clef,
        'spelling'   => $spelling,
        'range'      => $rangeMode,
        'solved'     => $solved ? '1' : '0',
    ],
    PageOptions::toQuery($opts)
);
$previewUrl = 'pdf.php?' . http_build_query($query);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page Layout &mdash; 12-Tone Sudoku</title>
<style>
  :root {
    --ink:#1c1c1e; --dim:#6b6b70; --line:#c9c9cf; --bg:#fbfbfa;
    --panel:#ffffff; --accent:#8a4b2a;
  }
  * { box-sizing:border-box; }
  body { margin:0; padding:1.5rem 1rem 3rem; background:var(--bg); color:var(--ink);
         font:16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
  .wrap { max-width:110rem; margin:0 auto; }
  h1 { font-size:1.3rem; margin:0 0 .25rem; }
  .sub { color:var(--dim); margin:0 0 1.25rem; font-size:.88rem; }
  a { color:var(--accent); }

  /* Two fixed tracks rather than flex-wrap: a wide <select> option label used to
     push the controls past their flex-basis, which dropped the preview onto a
     second line. minmax(0,1fr) stops the preview forcing overflow instead. */
  .cols { display:grid; grid-template-columns:23rem minmax(0, 1fr);
          gap:1.75rem; align-items:start; }
  .controls { min-width:0; background:var(--panel); border:1px solid var(--line);
              border-radius:8px; padding:1rem 1.1rem; }
  .preview { min-width:0; position:sticky; top:1rem; }
  .preview iframe { width:100%; height:min(84vh, 1100px); border:1px solid var(--line);
                    border-radius:8px; background:#fff; display:block; }

  /* Only stack when there genuinely is not room for both. */
  @media (max-width: 52rem) {
    .cols { grid-template-columns:1fr; }
    .preview { position:static; }
    .preview iframe { height:min(70vh, 900px); }
  }

  fieldset { border:none; padding:0; margin:0 0 1.1rem; }
  legend { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em;
           color:var(--dim); font-weight:600; padding:0 0 .4rem; }
  .row { display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; }
  .row label { flex:0 0 8.5rem; font-size:.78rem; color:var(--dim); }
  .row { min-width:0; }
  .row input[type=text], .row input[type=number], .row select, .row textarea {
    flex:1 1 auto; min-width:0; max-width:100%; font:inherit; font-size:.85rem;
    padding:.25rem .45rem; border:1px solid var(--line); border-radius:4px;
    background:#fff; color:var(--ink);
  }
  .row textarea { min-height:4.5rem; resize:vertical; font-family:ui-monospace, Menlo, monospace; font-size:.78rem; }
  .row.check label { flex:1 1 auto; }
  .hint { font-size:.7rem; color:#96969c; margin:-.2rem 0 .5rem 9rem;
          overflow-wrap:anywhere; }
  button { font:inherit; padding:.4rem 1.1rem; border:1px solid var(--ink);
           border-radius:5px; background:var(--ink); color:#fff; cursor:pointer; width:100%; }
  .links { margin-top:.75rem; font-size:.8rem; display:flex; gap:.9rem; flex-wrap:wrap; }

  .err { background:#fff2f0; border:1px solid #d99; color:#922; padding:.8rem 1rem;
         border-radius:8px; margin-bottom:1rem; font-size:.85rem; white-space:pre-wrap; }
  .warn { background:#fffbe9; border:1px solid #ddc98a; color:#6b5518; padding:.7rem 1rem;
          border-radius:8px; margin-bottom:1rem; font-size:.85rem; }
  code { font-family:ui-monospace, Menlo, monospace; font-size:.85em; }
  .answers .sub { color:var(--dim); font-size:.85rem; margin:0 0 .9rem; }
  .answers { margin-top: 2.5rem; border-top: 1px solid var(--line); padding-top: 1.75rem; }
  .answers h2 { font-size: .95rem; margin: 0 0 .35rem; letter-spacing: -.01em; }
  .answers .pid { font-family: ui-monospace, Menlo, monospace; font-size: .8rem;
                  color: var(--accent); background: #f4efe9; border-radius: 4px;
                  padding: .1rem .4rem; margin-left: .4rem; }
<?= PuzzleView::css($variant) ?>
</style>
</head>
<body>
<div class="wrap">

  <h1>Page Layout</h1>
  <p class="sub">
    US Letter portrait. The score is engraved by LilyPond at exactly the page&rsquo;s
    content width, then placed as a vector PDF &mdash; so changing the margin re-engraves
    the music rather than scaling it.
  </p>

  <?php if (!$status['ok']): ?>
    <div class="err"><strong>TCPDF / FPDI not installed.</strong>

TCPDF found: <?= $status['tcpdf'] ? 'yes' : 'no' ?>
FPDI found:  <?= $status['fpdi'] ? 'yes' : 'no' ?>

<?= h(PdfSupport::installHint()) ?></div>
  <?php endif; ?>

  <?php if ($buildError !== null): ?>
    <div class="err"><strong>Build failed.</strong>

<?= h($buildError) ?></div>
  <?php endif; ?>

  <?php foreach ($page->notes() as $n): ?>
    <div class="warn"><?= h($n) ?></div>
  <?php endforeach; ?>

  <?php if ($buildStray !== ''): ?>
    <div class="warn"><strong>PHP output during composition</strong> (harmless for the preview,
      but it would corrupt a raw PDF response &mdash; pdf.php keeps it out of the file):

<?= h($buildStray) ?></div>
  <?php endif; ?>

  <?php foreach ($store->warnings() as $w): ?>
    <div class="err"><strong>Cache problem:</strong> <?= h($w) ?></div>
  <?php endforeach; ?>

  <div class="cols">
    <form class="controls" method="get">

      <fieldset>
        <legend>Puzzle</legend>
        <div class="row">
          <label for="variant">Symbol set</label>
          <select name="variant" id="variant">
            <?php foreach (Variant::all() as $v): ?>
              <option value="<?= h($v->key) ?>"<?= $v->key === $variant->key ? ' selected' : '' ?>><?= h($v->label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="hint"><?= h($variant->description) ?></div>
        <div class="row">
          <label for="difficulty">Difficulty</label>
          <select name="difficulty" id="difficulty">
            <?php foreach (array_keys(SudokuEngine::DIFFICULTIES) as $d): ?>
              <option value="<?= h($d) ?>"<?= $d === $difficulty ? ' selected' : '' ?>><?= h(ucfirst($d)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <label for="seed">Seed</label>
          <input type="text" name="seed" id="seed" value="<?= h($puzzle->meta['seed']) ?>">
        </div>
        <div class="row">
          <label for="clef">Clef</label>
          <select name="clef" id="clef">
            <?php foreach (StaffRenderer::clefModes() as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $key === $clef ? ' selected' : '' ?>><?= h($label) ?> (<?= h(StaffRenderer::range($variant, $key, $rangeMode)) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <label for="range">Note range</label>
          <select name="range" id="range">
            <?php foreach (StaffRenderer::RANGE_MODES as $key => $label): ?>
              <?php if ($key === 'full' && !$variant->allowsFullRange()) { continue; } ?>
              <option value="<?= h($key) ?>"<?= $key === $rangeMode ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="hint">
          <?php if ($variant->allowsFullRange()): ?>
            Full range uses every octave that stays within one ledger line of the
            staff &mdash; two octaves in any clef. The same symbol then appears high
            in one bar and low in another.
          <?php else: ?>
            <?= h($variant->fullRangeBlockedBecause()) ?>
          <?php endif; ?>
        </div>
        <div class="row">
          <label for="spelling">Accidentals</label>
          <select name="spelling" id="spelling">
            <?php foreach (Variant::SPELLINGS as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $key === $spelling ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <label for="size">Staff size</label>
          <input type="number" name="size" id="size" min="8" max="24" value="<?= h($staffSize) ?>">
        </div>
        <div class="row check">
          <input type="hidden" name="solved" value="0">
          <input type="checkbox" name="solved" id="solved" value="1"<?= $solved ? ' checked' : '' ?>>
          <label for="solved">Show the solution</label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Page</legend>
        <?php foreach (PageOptions::FIELDS as $key => $field): ?>
          <?php $val = PageOptions::value($opts, $key); ?>
          <?php if ($field['type'] === 'bool'): ?>
            <div class="row check">
              <input type="hidden" name="<?= h($key) ?>" value="0">
              <input type="checkbox" name="<?= h($key) ?>" id="f_<?= h($key) ?>" value="1"<?= $val ? ' checked' : '' ?>>
              <label for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
            </div>
          <?php elseif ($field['type'] === 'textarea'): ?>
            <div class="row">
              <label for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
              <textarea name="<?= h($key) ?>" id="f_<?= h($key) ?>"><?= h($val) ?></textarea>
            </div>
          <?php elseif ($field['type'] === 'select'): ?>
            <div class="row">
              <label for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
              <select name="<?= h($key) ?>" id="f_<?= h($key) ?>">
                <?php foreach ($field['options'] as $o): ?>
                  <option value="<?= h($o) ?>"<?= (string) $val === $o ? ' selected' : '' ?>><?= h(ucfirst($o)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <div class="row">
              <label for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
              <input type="<?= in_array($field['type'], ['int', 'float'], true) ? 'number' : 'text' ?>"
                     <?= $field['type'] === 'float' ? 'step="0.05"' : '' ?>
                     name="<?= h($key) ?>" id="f_<?= h($key) ?>" value="<?= h($val) ?>">
            </div>
          <?php endif; ?>
          <?php if (!empty($field['hint'])): ?>
            <div class="hint"><?= h($field['hint']) ?></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </fieldset>

      <button type="submit">Redraw</button>

      <div class="links">
        <a href="<?= h($previewUrl) ?>" target="_blank">open PDF</a>
        <a href="<?= h($previewUrl . '&download=1') ?>">download</a>
        <a href="staff.php?difficulty=<?= h($difficulty) ?>&amp;seed=<?= h($puzzle->meta['seed']) ?>">staff view</a>
        <a href="index.php?difficulty=<?= h($difficulty) ?>&amp;seed=<?= h($puzzle->meta['seed']) ?>">grid view</a>
      </div>
    </form>

    <div class="preview">
      <?php if ($status['ok'] && $buildError === null): ?>
        <iframe src="<?= h($previewUrl) ?>" title="PDF preview"></iframe>
      <?php else: ?>
        <div class="warn">No preview &mdash; fix the problem above and redraw.</div>
      <?php endif; ?>
    </div>
  </div>


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

</div>
</body>
</html>
