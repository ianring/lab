<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/timing.php';
require __DIR__ . '/lib/auth.php';

require_login();

$all = firings_all();

$personFilter = (int)q('person', 0);
$kilnFilter   = (string)q('kiln', '');

$firings = array_values(array_filter($all, static function ($firing) use ($personFilter, $kilnFilter) {
    if ($personFilter > 0 && (int)($firing['fired_by'] ?? 0) !== $personFilter) {
        return false;
    }
    if ($kilnFilter !== '' && (string)$firing['kiln'] !== $kilnFilter) {
        return false;
    }
    return true;
}));

// Averages per program, from firings that ran to completion.
$byProgram = [];
foreach ($firings as $firing) {
    $minutes = firing_actual_minutes($firing);
    if ($minutes === null) {
        continue;
    }
    $byProgram[(string)$firing['program']][] = $minutes;
}

// Firings with nobody assigned — usually records written before the app
// started tracking who fired them.
$unassigned = array_values(array_filter($all, static function ($firing) {
    return (int)($firing['fired_by'] ?? 0) === 0
        && trim((string)($firing['fired_by_name'] ?? '')) === '';
}));

// So the calendar link, and the calendar itself, keep the same filter.
$filterQuery = filter_query($personFilter, $kilnFilter);

$pageTitle = 'Firings';
$active    = 'log';
require __DIR__ . '/partials/header.php';
?>

<?php if (q('assigned') !== null): ?>
    <p class="alert alert--ok">
        <?php $n = (int)q('assigned'); ?>
        <?= $n > 0 ? e($n) . ' firing' . ($n === 1 ? '' : 's') . ' assigned.' : 'Nothing to assign.' ?>
    </p>
<?php endif; ?>

<?php if ($unassigned && users_all()): ?>
    <section class="card">
        <h2><?= e(count($unassigned)) ?> firing<?= count($unassigned) === 1 ? ' has' : 's have' ?> nobody assigned</h2>
        <p class="hint">Records saved before the app tracked who was firing. Assign them all to one person
            here, then fix any exceptions individually.</p>
        <form method="post" action="firing.php" class="filters"
              onsubmit="return confirm('Assign all <?= e(count($unassigned)) ?> unassigned firings to this person?');">
            <input type="hidden" name="action" value="assign_unassigned">
            <select name="person" required>
                <option value="">Choose a person&hellip;</option>
                <?php foreach (users_all() as $user): ?>
                    <option value="<?= e($user['id']) ?>"><?= e($user['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Assign them</button>
        </form>
    </section>
<?php endif; ?>

<section class="card">
    <div class="record-head">
        <h2>Firings</h2>
        <span class="views">
            <a href="firings.php<?= e($filterQuery) ?>" class="on">List</a>
            <a href="calendar.php<?= e($filterQuery) ?>">Calendar</a>
        </span>
    </div>

    <?php if ($all): ?>
        <?php $filterAction = 'firings.php'; ?>
        <?php require __DIR__ . '/partials/filters.php'; ?>
    <?php endif; ?>

    <?php if (!$firings): ?>
        <p>
            <?php if ($all): ?>
                No firings match that filter. <a href="firings.php">Show all</a>.
            <?php else: ?>
                Nothing logged yet. Plan a firing and start a log &mdash; after a few, this app will know
                how long your programs really take and how each kiln is behaving.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="firing-list">
            <?php foreach ($firings as $firing): ?>
                <?php
                $kiln    = kiln_get((string)$firing['kiln']);
                $program = program_get((string)$firing['program']);
                $minutes = firing_actual_minutes($firing);
                $state   = firing_state($firing);
                $doneCount = is_array($firing['checklist'] ?? null) ? count($firing['checklist']) : 0;
                $total   = checklist_count(checklist_for((string)$firing['kiln'], (string)$firing['type']));
                $start   = parse_dt($firing['actual']['start_at'] ?? '') ?? parse_dt($firing['planned']['start_at'] ?? '');
                ?>
                <li>
                    <a class="fl-link" href="firing.php?id=<?= e($firing['id']) ?>">
                        <span class="fl-date"><?= e(fmt_day($start)) ?></span>
                        <span class="fl-main">
                            <strong><?= e($kiln['name']) ?> &middot; <?= e(ucfirst((string)$firing['type'])) ?> cone <?= e($firing['cone']) ?></strong>
                            <span class="muted">
                                <?= e(firing_firer_name($firing)) ?>
                            </span>
                            <span class="muted">
                                <?= e($kiln['kind'] === 'manual' ? 'Kiln sitter' : $program['label']) ?>
                                <?php if ((int)($firing['planned']['preheat_minutes'] ?? 0) > 0): ?>
                                    &middot; preheat <?= e(controller_time((int)$firing['planned']['preheat_minutes'])) ?>
                                <?php endif; ?>
                                <?php /* Nothing to say about a firing that has not happened yet. */ ?>
                                <?php if ($minutes !== null): ?>
                                    &middot; <?= e(fmt_minutes($minutes)) ?>
                                <?php elseif ($state === 'running'): ?>
                                    &middot; in progress
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="fl-progress"><?= e($doneCount) ?>/<?= e($total) ?></span>
                    </a>
                    <span class="fl-actions">
                        <a class="btn btn--tiny" href="firing.php?id=<?= e($firing['id']) ?>">Edit</a>
                        <form method="post" action="firing.php" class="inline"
                              onsubmit="return confirm('Delete the <?= e($kiln['name']) ?> firing from <?= e(fmt_day($start)) ?>? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($firing['id']) ?>">
                            <input type="hidden" name="return" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <button type="submit" class="btn btn--tiny btn--danger">Delete</button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($byProgram): ?>
<section class="card">
    <h2>What your programs actually take</h2>
    <div class="table-scroll">
        <table class="steps-table">
            <thead><tr><th>Program</th><th>Firings</th><th>Average</th><th>Range</th></tr></thead>
            <tbody>
            <?php foreach ($byProgram as $key => $samples): ?>
                <tr>
                    <td><?= e(program_get($key)['label']) ?></td>
                    <td><?= e(count($samples)) ?></td>
                    <td><strong><?= e(fmt_minutes((int)round(array_sum($samples) / count($samples)))) ?></strong></td>
                    <td class="muted"><?= e(fmt_minutes(min($samples))) ?> &ndash; <?= e(fmt_minutes(max($samples))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Wall-clock length including any preheat. The planner subtracts preheat before suggesting
        a program length.</p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
