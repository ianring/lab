<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/timing.php';
require __DIR__ . '/lib/auth.php';

require_login();

$kilnId   = (string)q('kiln', DEFAULT_KILN);
$kilns    = kiln_list();
if (!isset($kilns[$kilnId])) {
    $kilnId = DEFAULT_KILN;
}
$kiln     = $kilns[$kilnId];
$isManual = $kiln['kind'] === 'manual';

// Whose firing this is. Defaults to whoever is signed in, but can be anybody —
// a name is enough for someone who will never have an account.
$firedBy     = (int)q('fired_by', current_user_id());
$firedByName = trim((string)q('fired_by_name', ''));
if ($firedBy > 0 && !user_get($firedBy)) {
    $firedBy = current_user_id();
}
if ($firedBy === -1 || $firedByName !== '') {
    $firedBy = 0;
}

$programKey = (string)q('program', DEFAULT_PROGRAM);
$programs   = program_list();
if (!isset($programs[$programKey])) {
    $programKey = DEFAULT_PROGRAM;
}
$program = $programs[$programKey];

$cone = trim((string)q('cone', $program['cone']));
if ($cone === '') {
    $cone = $program['cone'];
}

// Program length: the manual's ballpark, unless you have told it otherwise.
$defaultHours  = $program['hours'];
$history       = firing_history_estimate($kilnId, $programKey, $cone);
$hoursParam    = q('hours');
$programHours  = $hoursParam !== null && $hoursParam !== '' ? (float)$hoursParam : $defaultHours;
$programHours  = max(0.25, min(48.0, $programHours));
$programMinutes = (int)round($programHours * 60);

$holdMinutes = (int)q('hold', 0);
$holdMinutes = max(0, min(180, $holdMinutes));

$overheadHours   = q('overhead') !== null && q('overhead') !== '' ? (float)q('overhead') : PREHEAT_OVERHEAD_HOURS;
$overheadHours   = max(0.0, min(6.0, $overheadHours));
$overheadMinutes = (int)round($overheadHours * 60);

/*
 * The booking. One named day, but the kiln room is yours from 6 pm the evening
 * before until 6 pm the day after — and every other time on this page has to
 * sit inside that window.
 */
$bookingDay = parse_dt(q('booking_day'));
if (!$bookingDay) {
    // Tomorrow, unless there is still time to book today's kiln.
    $bookingDay = now()->setTime(0, 0);
    if ((int)now()->format('G') >= UNLOAD_HOUR) {
        $bookingDay = $bookingDay->modify('+1 day');
    }
}
$bookingDay = $bookingDay->setTime(0, 0);
$accessFrom = $bookingDay->modify('-1 day')->setTime(UNLOAD_HOUR, 0);
$clearBy    = $bookingDay->modify('+1 day')->setTime(UNLOAD_HOUR, 0);

// Everything must be out by the end of the booking. Not a choice — a boundary.
$unloadBy = $clearBy;

$start = parse_dt(q('start'));
if (!$start) {
    // Access opens at 6 pm the evening before; start there unless that has
    // already passed, in which case start now.
    $start = now() > $accessFrom ? now()->setTime((int)now()->format('H'), (int)now()->format('i')) : $accessFrom;
}

$finish = parse_dt(q('finish'));
if (!$finish) {
    // Default: the next 8 am that leaves room for the program plus a preheat.
    $finish = $start->setTime(8, 0);
    $floor  = add_minutes($start, $programMinutes + $overheadMinutes + 30);
    while ($finish < $floor) {
        $finish = $finish->modify('+1 day');
    }
}

$planInput = [
    'start'            => $start,
    'finish'           => $finish,
    'unload_deadline'  => $unloadBy,
    'window_start'     => $accessFrom,   // the timeline is the booking window,
    'window_end'       => $clearBy,      // 6 pm to 6 pm, exactly 48 hours
    'program_minutes'  => $programMinutes,
    'hold_minutes'     => $holdMinutes,
    'overhead_minutes' => $overheadMinutes,
    'program_label'    => $program['label'],
    'cone'             => $cone,
];

$plan = $isManual ? plan_manual_firing($planInput) : plan_firing($planInput);

// One kiln, one booking at a time. The windows are 48 hours, so anything
// within a day of this one overlaps it.
$conflicts = booking_conflicts($kilnId, $bookingDay);
if ($conflicts) {
    $clash = $conflicts[0];
    $clashDay = firing_booking_day($clash);
    array_unshift($plan['blocking'],
        $kiln['name'] . ' is already booked for ' . fmt_day($clashDay) . ' by '
        . firing_firer_name($clash, 'somebody') . '. Bookings run 6 pm to 6 pm, so two on the same kiln '
        . 'have to be at least two days apart. The next free day is '
        . fmt_day(booking_next_free($kilnId, $bookingDay)) . '.');
}

// The booking window is a hard edge in both directions.
if ($start < $accessFrom) {
    array_unshift($plan['blocking'], 'The kiln room is not yours until ' . fmt_clock($accessFrom)
        . ', so you cannot start before then.');
}
if ($plan['actual_finish'] > $clearBy) {
    array_unshift($plan['blocking'], 'This runs past the end of the booking at ' . fmt_clock($clearBy)
        . ' — never mind cooling and unloading. Book the next day as well, or shorten the firing.');
}

if (!$isManual && $program['type'] === 'glaze' && cone_rank($cone) > MAX_GLAZE_CONE) {
    array_unshift($plan['warnings'], 'Cone ' . $cone . ' is above cone ' . MAX_GLAZE_CONE . '. The manual says never fire these kilns above cone ' . MAX_GLAZE_CONE . '.');
}

$pageTitle = 'Plan a firing';
$active    = 'plan';
require __DIR__ . '/partials/header.php';
?>

<form class="card form" method="get" action="index.php" id="planner">
    <h2>Plan a firing</h2>

    <div class="field field--booking">
        <label for="booking_day">Day of the kiln reservation</label>
        <input type="date" name="booking_day" id="booking_day" data-replan value="<?= e($bookingDay->format('Y-m-d')) ?>">
        <p class="hint" id="booking-window" data-refresh>Day of the kiln reservation. 
            The kiln room is yours from <strong><?= e(fmt_clock($accessFrom)) ?></strong>
            and everything must be out by <strong><?= e(fmt_clock($clearBy)) ?></strong>.</p>
    </div>

    <div class="field">
        <label>Kiln</label>
        <div class="chips">
            <?php foreach ($kilns as $id => $k): ?>
                <label class="chip">
                    <input type="radio" name="kiln" value="<?= e($id) ?>" <?= (string)$id === $kilnId ? 'checked' : '' ?>>
                    <span><?= e($k['name']) ?><?= $k['kind'] === 'manual' ? ' <em>manual</em>' : '' ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="hint" id="kiln-blurb" data-refresh><?= e($kiln['blurb']) ?>
            <a class="linkish" href="kilns.php#kiln-<?= e(rawurlencode($kilnId)) ?>">Details</a></p>
    </div>

    <?php require __DIR__ . '/partials/firer-field.php'; ?>

    <?php if (!$isManual): ?>
        <div class="field">
            <label>Program</label>
            <div class="chips chips--wide">
                <?php foreach ($programs as $key => $prog): ?>
                    <label class="chip">
                        <input type="radio" name="program" value="<?= e($key) ?>" data-hours="<?= e($prog['hours']) ?>" data-cone="<?= e($prog['cone']) ?>" <?= $key === $programKey ? 'checked' : '' ?>>
                        <span><?= e($prog['label']) ?><?= $prog['recommended'] ? ' <em>rec.</em>' : '' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="field">
            <label for="cone">Cone</label>
            <select name="cone" id="cone">
                <?php $coneTable = cone_table(); ?>
                <?php foreach ($coneTable as $c => $temp): ?>
                    <option value="<?= e($c) ?>" <?= (string)$c === $cone ? 'selected' : '' ?>>
                        Cone <?= e($c) ?> &mdash; <?= e($temp) ?>&deg;C
                    </option>
                <?php endforeach; ?>
                <?php if (!isset($coneTable[$cone])): ?>
                    <option value="<?= e($cone) ?>" selected>Cone <?= e($cone) ?></option>
                <?php endif; ?>
            </select>
            <p class="hint" id="cone-hint" data-refresh>
                <?= $program['type'] === 'bisque'
                    ? 'Bisque is normally 06; cone 05 burns off more impurities in the clay.'
                    : 'Glaze is normally 6; cone 5 with a 15 minute hold is the alternative.' ?>
            </p>
        </div>

        <div class="field">
            <label for="hours">Program length (hours)</label>
            <input type="number" inputmode="decimal" step="0.25" min="0.25" max="48" name="hours" id="hours" value="<?= e(rtrim(rtrim(number_format($programHours, 2, '.', ''), '0'), '.')) ?>">
            <?php if ($history): ?>
                <p class="hint" id="hours-hint" data-refresh>
                    Your last <?= e($history['count']) ?> matching firing<?= $history['count'] === 1 ? '' : 's' ?>
                    (<?= e($history['scope']) ?>) averaged <?= e(fmt_minutes($history['minutes'])) ?>.
                    <a href="#" class="linkish" data-set-hours="<?= e(round($history['minutes'] / 60, 2)) ?>">Use that</a>
                </p>
            <?php else: ?>
                <p class="hint" id="hours-hint" data-refresh>Manual&rsquo;s estimate. Log a few firings and the app will offer your own average.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isManual): ?>
        <div class="grid-2">
            <div class="field">
                <label for="hold">Hold / soak at the top (minutes)</label>
                <input type="number" inputmode="numeric" step="5" min="0" max="180" name="hold" id="hold" value="<?= e($holdMinutes) ?>">
                <p class="hint" id="hold-hint" data-refresh>
                    <?php if ($program['type'] === 'bisque'): ?>
                        Bisque is normally fired with no hold &mdash; leave it at 0.
                    <?php else: ?>
                        The cheat sheet says 10&ndash;30 minutes for glaze. Heat work can push you a cone
                        higher: cone 5 with a 15 minute hold reaches a soft cone 6.
                    <?php endif; ?>
                    The hold runs after the program reaches temperature, so it makes the firing that much longer.
                </p>
            </div>
            <div class="field">
                <label for="overhead">Preheat ramp allowance (hours)</label>
                <input type="number" inputmode="decimal" step="0.25" min="0" max="6" name="overhead" id="overhead" value="<?= e(rtrim(rtrim(number_format($overheadHours, 2, '.', ''), '0'), '.')) ?>">
                <p class="hint">Time to climb to 93&deg;C before the preheat stage starts. This is the
                    &ldquo;&minus; 1 to bring temperature to preheat&rdquo; in the cheat sheet&rsquo;s sum:
                    hours until you want it to end, minus the firing time, minus 1.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="field">
            <label for="start">I can press START at</label>
            <input type="datetime-local" name="start" id="start" value="<?= e(dt_input($start)) ?>">
            <p class="hint"><a href="#" class="linkish" data-set-now="start">Now</a></p>
        </div>
        <div class="field">
            <label for="finish">I want it finished by</label>
            <input type="datetime-local" name="finish" id="finish" value="<?= e(dt_input($finish)) ?>">
            <p class="hint">Be mindful that you need to be present in the workshop
                <?= e(fmt_hours_words(PRESENT_END_HOURS)) ?> prior to this.</p>
        </div>
    </div>

    <p class="hint">Everything has to be out by <strong><?= e(fmt_clock($clearBy)) ?></strong>, the end of the
        booking, and the kiln needs <?= e(COOLING_HOURS_MIN) ?> hours to cool before it is safe to empty
        &mdash; which is what caps how late the firing can finish.</p>

    <?php /* With JavaScript the plan is recalculated the moment a field changes,
             so app.js hides this. It is the fallback for a browser without it. */ ?>
    <button type="submit" class="btn btn--primary" data-submit-fallback>Work it out</button>
</form>

<div id="plan-results">

<?php foreach ($plan['blocking'] as $problem): ?>
    <p class="alert alert--stop"><?= e($problem) ?></p>
<?php endforeach; ?>

<?php foreach ($plan['warnings'] as $warning): ?>
    <p class="alert alert--warn"><?= e($warning) ?></p>
<?php endforeach; ?>

<?php if ($isManual): ?>

    <section class="card answer">
        <h2>Kiln 1 has no preheat to program</h2>
        <p class="answer-lede">Set the sitter timer to <strong><?= e($plan['timer_hours']) ?> hours</strong> and start at
            <strong><?= e(fmt_clock($plan['actual_start'])) ?></strong> to finish around
            <strong><?= e(fmt_clock($plan['actual_finish'])) ?></strong>.</p>
        <p class="hint">The timer only needs to outlast the firing &mdash; 30 minutes to an hour beyond your estimate. The kiln sitter is what actually ends the firing.</p>
        <table class="steps-table">
            <thead><tr><th>Time</th><th>Elements</th></tr></thead>
            <tbody>
            <?php foreach ($plan['steps'] as $step): ?>
                <tr>
                    <td><?= e(fmt_clock($step['at'])) ?></td>
                    <td><strong><?= e($step['setting']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">Up by 2 every hour: LO, 2, 4, 6, HI. Check the pyrometer each hour and write it down.</p>
        <p class="alt">Cooling caps the finish: to be out of the kiln room by
            <strong><?= e(fmt_clock($unloadBy)) ?></strong>, the firing has to end by
            <strong><?= e(fmt_clock($plan['latest_finish'])) ?></strong>.</p>
        <?php foreach ($plan['notes'] as $note): ?>
            <p class="hint"><?= e($note) ?></p>
        <?php endforeach; ?>
    </section>

<?php else: ?>

    <section class="card answer answer--<?= e($plan['mode']) ?>">
        <?php if ($plan['mode'] === 'preheat'): ?>
            <h2>Program this preheat</h2>
            <div class="big-number">
                <span class="big-label">PRHT</span>
                <span class="big-value"><?= e(controller_time($plan['preheat_minutes'])) ?></span>
            </div>
            <p class="answer-lede">
                A <strong><?= e(fmt_minutes($plan['preheat_minutes'])) ?></strong> preheat stage.
                Press START at <strong><?= e(fmt_clock($plan['actual_start'])) ?></strong> and it finishes about
                <strong><?= e(fmt_clock($plan['actual_finish'])) ?></strong><?php
                if ($plan['early_by_minutes'] > 0) {
                    echo ', ' . e(fmt_minutes($plan['early_by_minutes'])) . ' before your target';
                } ?>.
            </p>
            <p class="alt">Or skip the preheat entirely and press START at
                <strong><?= e(fmt_clock($plan['press_start_at'])) ?></strong> instead.</p>

        <?php elseif ($plan['mode'] === 'start_later'): ?>
            <h2>No preheat needed</h2>
            <div class="big-number">
                <span class="big-label">Press START at</span>
                <span class="big-value"><?= e(ltrim($plan['actual_start']->format('g:i a'), '0')) ?></span>
            </div>
            <p class="answer-lede">
                That is <strong><?= e(fmt_minutes($plan['wait_minutes'])) ?></strong> from now.
                Leave PRHT at 0.00 and the firing lands on <strong><?= e(fmt_clock($plan['actual_finish'])) ?></strong>.
            </p>

        <?php else: ?>
            <h2>That finish time is not reachable</h2>
            <div class="big-number">
                <span class="big-label">Earliest finish</span>
                <span class="big-value"><?= e(ltrim($plan['actual_finish']->format('g:i a'), '0')) ?></span>
            </div>
            <p class="answer-lede">
                Starting at <?= e(fmt_clock($plan['actual_start'])) ?>, a <?= e(fmt_minutes($plan['program_minutes'])) ?>
                program<?= $plan['hold_minutes'] > 0 ? ' with a ' . e(fmt_minutes($plan['hold_minutes'])) . ' hold' : '' ?>
                cannot be done before <?= e(fmt_clock($plan['actual_finish'])) ?> &mdash;
                <?= e(fmt_minutes($plan['late_by_minutes'])) ?> past your target.
                No preheat: preheat only ever makes a firing longer.
            </p>
        <?php endif; ?>

        <p class="alt">Cooling caps the finish: to be out of the kiln room by
            <strong><?= e(fmt_clock($unloadBy)) ?></strong>, the firing has to end by
            <strong><?= e(fmt_clock($plan['latest_finish'])) ?></strong>.
            <?php if ($plan['latest_finish'] > $plan['actual_finish']): ?>
                <a href="#" class="linkish" data-set-finish="<?= e(dt_input($plan['latest_finish'])) ?>">Use that as my finish time</a>
            <?php endif; ?>
        </p>

        <?php foreach ($plan['notes'] as $note): ?>
            <p class="hint"><?= e($note) ?></p>
        <?php endforeach; ?>
    </section>

<?php endif; ?>

<?php require __DIR__ . '/partials/timeline.php'; ?>


<form class="card start-log" method="post" action="firing.php">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="kiln" value="<?= e($kilnId) ?>">
    <input type="hidden" name="fired_by" value="<?= e($firedBy) ?>">
    <input type="hidden" name="fired_by_name" value="<?= e($firedByName) ?>">
    <input type="hidden" name="program" value="<?= e($programKey) ?>">
    <input type="hidden" name="type" value="<?= e($isManual ? (cone_rank($cone) < 0 ? 'bisque' : 'glaze') : $program['type']) ?>">
    <input type="hidden" name="cone" value="<?= e($cone) ?>">
    <input type="hidden" name="hold_minutes" value="<?= e($holdMinutes) ?>">
    <input type="hidden" name="program_minutes" value="<?= e($programMinutes) ?>">
    <input type="hidden" name="preheat_minutes" value="<?= e($plan['preheat_minutes']) ?>">
    <input type="hidden" name="overhead_minutes" value="<?= e($isManual ? 0 : $overheadMinutes) ?>">
    <input type="hidden" name="planned_start" value="<?= e(dt_store($plan['actual_start'])) ?>">
    <input type="hidden" name="planned_finish" value="<?= e(dt_store($plan['actual_finish'])) ?>">
    <input type="hidden" name="planned_unload" value="<?= e(dt_store($unloadBy)) ?>">
    <input type="hidden" name="booking_day" value="<?= e($bookingDay->format('Y-m-d')) ?>">
    <h2>Does this work?</h2>
    <?php if ($plan['blocking']): ?>
        <p>Not yet &mdash; this plan cannot be fired as it stands:</p>
        <ul class="quirks">
            <?php foreach ($plan['blocking'] as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($conflicts): ?>
        <p>That kiln is not free on <?= e(fmt_day($bookingDay)) ?>. Pick another day, or another kiln.</p>
        <ul class="quirks">
            <?php foreach ($conflicts as $clash): ?>
                <li>
                    <a href="firing.php?id=<?= e($clash['id']) ?>">
                        <?= e(fmt_day(firing_booking_day($clash))) ?> &mdash;
                        <?= e(ucfirst((string)$clash['type'])) ?> cone <?= e($clash['cone']) ?>,
                        <?= e(firing_firer_name($clash, 'unassigned')) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>Save it and the app turns this plan into a firing: three visits to the workshop, each with its
            own checklist and everything you need on the spot &mdash; including which buttons to press on
            the controller.</p>
        <button type="submit" class="btn btn--primary">Save as a firing</button>
    <?php endif; ?>
</form>

</div><!-- /plan-results -->

<?php require __DIR__ . '/partials/footer.php'; ?>
