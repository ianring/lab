<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/auth.php';

require_login();

$messages = [];
$saved    = false;
$failed   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (p('action') === 'reset') {
        if (settings_reset()) {
            redirect('settings.php?reset=1');
        }
        $failed = true;
    } else {
        [$ok, , $messages] = settings_save($_POST);
        if ($ok && !$messages) {
            redirect('settings.php?saved=1');
        }
        $saved  = $ok;
        $failed = !$ok;
    }
}

$settings = settings_load();
$spec     = settings_spec();
$changed  = settings_changed();
$writable = settings_writable();

// Group the fields for display, in the order they are declared.
$groups = [];
foreach ($spec as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

$pageTitle = 'Settings';
$active    = 'settings';
require __DIR__ . '/partials/header.php';
?>

<?php if (q('saved') || $saved): ?>
    <p class="alert alert--ok">Settings saved.</p>
<?php endif; ?>
<?php if (q('reset')): ?>
    <p class="alert alert--ok">Settings put back to the shipped defaults.</p>
<?php endif; ?>
<?php if ($failed): ?>
    <p class="alert alert--warn">Could not save the settings. <?= e(store_diagnose() ?: db_error()) ?></p>
<?php endif; ?>
<?php foreach ($messages as $message): ?>
    <p class="alert alert--warn"><?= e($message) ?></p>
<?php endforeach; ?>
<?php if (!$writable): ?>
    <p class="alert alert--warn">Settings cannot be saved. <?= e(store_diagnose() ?: db_error()) ?></p>
<?php endif; ?>

<form class="card form" method="post" action="settings.php">
    <h2>Settings</h2>
    <p class="hint">These are the numbers the planner does its arithmetic with. Everything here has a
        sensible default from the WPW manual &mdash; change them as you learn how your kilns actually behave.</p>

    <?php foreach ($groups as $groupName => $fields): ?>
        <h3 class="phase"><?= e($groupName) ?></h3>

        <?php foreach ($fields as $key => $field): ?>
            <div class="field setting<?= isset($changed[$key]) ? ' setting--changed' : '' ?>">
                <label for="<?= e($key) ?>">
                    <?= e($field['label']) ?>
                    <?php if (isset($changed[$key])): ?><span class="tag">changed</span><?php endif; ?>
                </label>

                <?php if ($field['type'] === 'number'): ?>
                    <input type="number" inputmode="decimal"
                           name="<?= e($key) ?>" id="<?= e($key) ?>"
                           value="<?= e($settings[$key]) ?>"
                           min="<?= e($field['min']) ?>" max="<?= e($field['max']) ?>" step="<?= e($field['step']) ?>">

                <?php elseif ($field['type'] === 'timezone'): ?>
                    <select name="<?= e($key) ?>" id="<?= e($key) ?>">
                        <?php foreach (timezone_identifiers_list() as $zone): ?>
                            <option value="<?= e($zone) ?>" <?= $zone === $settings[$key] ? 'selected' : '' ?>><?= e($zone) ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($field['type'] === 'kiln'): ?>
                    <select name="<?= e($key) ?>" id="<?= e($key) ?>">
                        <?php foreach (kiln_list() as $id => $k): ?>
                            <option value="<?= e($id) ?>" <?= (string)$id === (string)$settings[$key] ? 'selected' : '' ?>>
                                <?= e($k['name']) ?> &mdash; <?= e($k['kind'] === 'manual' ? 'kiln sitter' : 'controller') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($field['type'] === 'program'): ?>
                    <select name="<?= e($key) ?>" id="<?= e($key) ?>">
                        <?php foreach (program_list() as $id => $prog): ?>
                            <option value="<?= e($id) ?>" <?= $id === $settings[$key] ? 'selected' : '' ?>><?= e($prog['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php else: ?>
                    <input type="text" name="<?= e($key) ?>" id="<?= e($key) ?>" value="<?= e($settings[$key]) ?>">
                <?php endif; ?>

                <?php if ($field['hint'] !== ''): ?>
                    <p class="hint"><?= e($field['hint']) ?></p>
                <?php endif; ?>
                <?php if (isset($changed[$key])): ?>
                    <p class="hint">Default is <?= e(settings_defaults()[$key]) ?>.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <button type="submit" class="btn btn--primary"<?= $writable ? '' : ' disabled' ?>>Save settings</button>
</form>

<section class="card">
    <h2>Where this lives</h2>
    <p class="hint">Settings live in the <code>settings</code> table, one row per name. Empty that table and
        everything goes back to the defaults in <code>lib/settings.php</code>. Database credentials are
        constants in <code>config/db.php</code>; things the manual fixes &mdash; the checklists, the keypad
        layout, cone temperatures &mdash; stay in <code>lib/data.php</code>.</p>
    <p class="hint">Facts about the kilns themselves live in the manifest at <code>config/kilns.json</code>,
        edited on the server and shown read-only on the <a href="kilns.php">Kilns</a> page.</p>
    <form method="post" action="settings.php" onsubmit="return confirm('Put every setting back to its default?');">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn btn--ghost">Reset to defaults</button>
    </form>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
