<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/auth.php';

require_login();

$manifest = kilns_manifest();
$studio   = $manifest['studio'];
$labels   = kiln_field_labels();
$special  = kiln_special_fields();

/** Rows to show for one kiln: known fields first, then anything else in the file. */
$rows = static function (array $kiln) use ($labels, $special): array {
    $rows = [];
    foreach ($labels as $key => $label) {
        if (isset($kiln[$key]) && is_scalar($kiln[$key]) && trim((string)$kiln[$key]) !== '') {
            $rows[$label] = (string)$kiln[$key];
        }
    }
    foreach ($kiln as $key => $value) {
        if (isset($labels[$key]) || in_array($key, $special, true) || strpos((string)$key, '_') === 0) {
            continue;
        }
        if (is_scalar($value) && trim((string)$value) !== '') {
            $rows[ucfirst(str_replace('_', ' ', (string)$key))] = (string)$value;
        }
    }
    return $rows;
};

$pageTitle = 'Kilns';
$active    = 'kilns';
require __DIR__ . '/partials/header.php';
?>

<?php if ($manifest['error'] !== ''): ?>
    <p class="alert alert--warn"><?= e($manifest['error']) ?></p>
<?php endif; ?>

<section class="card">
    <div class="record-head">
        <h2>Kilns<?= !empty($studio['name']) ? ' at ' . e($studio['name']) : '' ?></h2>
        <span class="muted">read only</span>
    </div>
    <p class="hint">This is the kiln manifest, <code><?= e($manifest['source']) ?></code>. It is edited on
        the server, not here &mdash; so what you read on this page is what the planner and the checklists
        are actually using.</p>
</section>

<?php foreach (kiln_list() as $id => $kiln): ?>
    <section class="card kiln-card" id="kiln-<?= e($id) ?>">
        <div class="record-head">
            <h2><?= e($kiln['name']) ?></h2>
            <span class="pill pill--<?= e($kiln['kind']) ?>"><?= e($kiln['kind'] === 'manual' ? 'Kiln sitter' : 'Computer controlled') ?></span>
        </div>

        <?php if ($kiln['blurb'] !== ''): ?>
            <p><?= e($kiln['blurb']) ?></p>
        <?php endif; ?>

        <?php $kilnRows = $rows($kiln); ?>
        <?php if ($kilnRows): ?>
            <dl class="manifest">
                <?php foreach ($kilnRows as $label => $value): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <?php if (!empty($kiln['quirks']) && is_array($kiln['quirks'])): ?>
            <h3 class="phase">Worth knowing</h3>
            <ul class="quirks">
                <?php foreach ($kiln['quirks'] as $quirk): ?>
                    <?php if (is_scalar($quirk) && trim((string)$quirk) !== ''): ?>
                        <li><?= e($quirk) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($kiln['notes']) && is_scalar($kiln['notes'])): ?>
            <p class="hint"><?= e($kiln['notes']) ?></p>
        <?php endif; ?>

        <p class="kiln-actions">
            <a class="btn btn--ghost" href="index.php?kiln=<?= e(rawurlencode((string)$id)) ?>">Plan a firing on <?= e($kiln['name']) ?></a>
        </p>
    </section>
<?php endforeach; ?>

<?php if (!empty($studio['notes']) && is_array($studio['notes'])): ?>
    <section class="card">
        <h2>The kiln room<?= !empty($studio['room']) ? ' &mdash; ' . e($studio['room']) : '' ?></h2>
        <ul class="quirks">
            <?php foreach ($studio['notes'] as $note): ?>
                <?php if (is_scalar($note) && trim((string)$note) !== ''): ?>
                    <li><?= e($note) ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Editing this</h2>
    <p class="hint">The manifest lives at <code>config/kilns.json</code>. Add a field to a kiln and it
        appears here automatically, with the key tidied into a label. Add a kiln and it appears in the
        planner too. Fields left blank are hidden rather than shown empty, so there is no harm in keeping
        the placeholders until you can fill them in at the kiln.</p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
