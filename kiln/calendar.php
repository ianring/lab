<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/timing.php';
require __DIR__ . '/lib/auth.php';

require_login();

/* Which month. */
$month = parse_dt((string)q('month', '') . '-01') ?? now()->modify('first day of this month');
$month = $month->setTime(0, 0)->modify('first day of this month');
$daysInMonth = (int)$month->format('t');
$monthEnd    = $month->modify('+' . ($daysInMonth - 1) . ' days');

// The grid starts on the Sunday on or before the 1st, and runs whole weeks.
$gridStart = $month->modify('-' . (int)$month->format('w') . ' days');
$gridEnd   = $monthEnd->modify('+' . (6 - (int)$monthEnd->format('w')) . ' days');
$gridDays  = (int)$gridStart->diff($gridEnd)->days + 1;

/* The same two filters the list has. */
$personFilter = (int)q('person', 0);
$kilnFilter   = (string)q('kiln', '');

/*
 * A booking is one named day but three days of access: from 6 pm the evening
 * before to 6 pm the day after. Pull anything whose booking day could touch
 * the grid, then paint the three days it occupies.
 */
$where  = ['COALESCE(booking_day, DATE(planned_start), DATE(created_at)) BETWEEN ? AND ?'];
$params = [$gridStart->modify('-1 day')->format('Y-m-d'), $gridEnd->modify('+1 day')->format('Y-m-d')];
if ($personFilter > 0) {
    $where[]  = 'fired_by = ?';
    $params[] = $personFilter;
}
if ($kilnFilter !== '') {
    $where[]  = 'kiln = ?';
    $params[] = $kilnFilter;
}

$rows = db_rows(
    'SELECT id, kiln, booking_day, planned_start, actual_start, created_at,
            fired_by, fired_by_name, type, cone
       FROM firings
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY kiln',
    $params
);

$calendar = [];   // 'Y-m-d' => [ ['role' => booking|access|clear, …], … ]
foreach ($rows as $row) {
    $firing = firing_normalize([
        'id' => $row['id'], 'kiln' => $row['kiln'], 'booking_day' => $row['booking_day'],
        'fired_by' => $row['fired_by'], 'fired_by_name' => $row['fired_by_name'],
        'type' => $row['type'], 'cone' => $row['cone'], 'created_at' => $row['created_at'],
        'planned' => ['start_at' => $row['planned_start']],
        'actual'  => ['start_at' => $row['actual_start']],
    ]);
    $day = firing_booking_day($firing);
    if (!$day) {
        continue;
    }

    $entry = [
        'id'    => (int)$row['id'],
        'kiln'  => kiln_get((string)$row['kiln']),
        'who'   => firing_firer_name($firing, ''),
        'what'  => ucfirst((string)$row['type']) . ' cone ' . $row['cone'],
    ];
    foreach ([['-1 day', 'access'], ['+0 day', 'booking'], ['+1 day', 'clear']] as [$shift, $role]) {
        $key = $day->modify($shift)->format('Y-m-d');
        $calendar[$key][] = $entry + ['role' => $role];
    }
}

$today = now()->format('Y-m-d');

// Carried by the view links; the month links need the month as well.
$filterQuery = filter_query($personFilter, $kilnFilter);
$monthQuery  = static function (DateTimeImmutable $m) use ($personFilter, $kilnFilter) {
    return filter_query($personFilter, $kilnFilter, ['month' => $m->format('Y-m')]);
};

$pageTitle = 'Calendar';
$active    = 'log';
require __DIR__ . '/partials/header.php';
?>

<section class="card">
    <div class="record-head">
        <h2>Calendar</h2>
        <span class="views">
            <a href="firings.php<?= e($filterQuery) ?>">List</a>
            <a href="calendar.php<?= e($filterQuery) ?>" class="on">Calendar</a>
        </span>
    </div>

    <?php $filterAction = 'calendar.php'; ?>
    <?php $filterKeep = ['month' => $month->format('Y-m')]; ?>
    <?php require __DIR__ . '/partials/filters.php'; ?>

    <nav class="pager month-nav">
        <a class="btn btn--tiny" href="calendar.php<?= e($monthQuery($month->modify('-1 month'))) ?>">&larr; <?= e($month->modify('-1 month')->format('M')) ?></a>
        <strong><?= e($month->format('F Y')) ?></strong>
        <a class="btn btn--tiny" href="calendar.php<?= e($monthQuery($month->modify('+1 month'))) ?>"><?= e($month->modify('+1 month')->format('M')) ?> &rarr;</a>
    </nav>

    <div class="cal">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $name): ?>
            <div class="cal-head"><?= e($name) ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $gridDays; $i++): ?>
            <?php
            $day     = $gridStart->modify('+' . $i . ' days');
            $key     = $day->format('Y-m-d');
            $inMonth = $day->format('Y-m') === $month->format('Y-m');
            $entries = $calendar[$key] ?? [];
            ?>
            <div class="cal-day <?= $inMonth ? '' : 'cal-day--outside' ?> <?= $key === $today ? 'cal-day--today' : '' ?>">
                <span class="cal-date"><?= e((int)$day->format('j')) ?></span>
                <?php foreach ($entries as $entry): ?>
                    <a class="cal-booking cal-booking--<?= e($entry['role']) ?>"
                       href="firing.php?id=<?= e($entry['id']) ?>"
                       title="<?= e($entry['kiln']['name'] . ' · ' . $entry['what']
                            . ($entry['who'] !== '' ? ' · ' . $entry['who'] : '')
                            . ' · ' . ['access' => 'load from 6 pm', 'booking' => 'firing day', 'clear' => 'out by 6 pm'][$entry['role']]) ?>">
                        <span class="cal-kiln"><?= e(preg_replace('/[^0-9]/', '', $entry['kiln']['name']) ?: $entry['kiln']['name']) ?></span>
                        <?php if ($entry['role'] === 'booking'): ?>
                            <span class="cal-what"><?= e($entry['what']) ?></span>
                        <?php else: ?>
                            <span class="cal-what"><?= $entry['role'] === 'access' ? 'load 6pm' : 'out 6pm' ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>

    <ul class="cal-key">
        <li><span class="swatch swatch--access"></span> Access from 6 pm — load the kiln</li>
        <li><span class="swatch swatch--booking"></span> The booking day — the firing itself</li>
        <li><span class="swatch swatch--clear"></span> Out by 6 pm — unload and clean up</li>
    </ul>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
