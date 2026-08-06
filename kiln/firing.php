<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/timing.php';
require __DIR__ . '/lib/cones.php';
require __DIR__ . '/lib/chart.php';
require __DIR__ . '/lib/led.php';
require __DIR__ . '/lib/auth.php';

require_login();

/* ------------------------------------------------------------------ */
/* Writes                                                              */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)p('action', '');

    if ($action === 'create') {
        // The plan names the booking day outright; fall back to working it out
        // from the start time only for anything posted without one.
        $plannedStart = parse_dt((string)p('planned_start', '')) ?? now();
        $bookingDay   = parse_dt((string)p('booking_day', '')) ?? booking_day_from_start($plannedStart);
        $kilnId       = (string)p('kiln', DEFAULT_KILN);

        // The planner hides the button when the kiln is taken, but it is a GET
        // form — this is the check that actually holds.
        $clashes = booking_conflicts($kilnId, $bookingDay);
        if ($clashes) {
            http_response_code(409);
            $pageTitle = 'That kiln is taken';
            $active    = 'plan';
            require __DIR__ . '/partials/header.php';
            ?>
            <section class="card">
                <h2><?= e(kiln_get($kilnId)['name']) ?> is already booked</h2>
                <p class="alert alert--warn">A booking runs from 6 pm the evening before to 6 pm the day
                    after, so two on the same kiln have to be at least two days apart.
                    <?= e(fmt_day($bookingDay)) ?> overlaps a booking that already exists.</p>
                <ul class="quirks">
                    <?php foreach ($clashes as $clash): ?>
                        <li><a href="firing.php?id=<?= e($clash['id']) ?>">
                            <?= e(fmt_day(firing_booking_day($clash))) ?> &mdash;
                            <?= e(ucfirst((string)$clash['type'])) ?> cone <?= e($clash['cone']) ?>,
                            <?= e(firing_firer_name($clash, 'unassigned')) ?>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint">Nothing was saved. The next free day for this kiln is
                    <strong><?= e(fmt_day(booking_next_free($kilnId, $bookingDay))) ?></strong>.</p>
                <p><a class="btn btn--ghost" href="javascript:history.back()">Back to the plan</a></p>
            </section>
            <?php
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        // The plan has to still be possible. The planner hides the button, but
        // this is a GET form and the button is not the guarantee.
        $checkPlan = plan_firing([
            'start'            => $plannedStart,
            'finish'           => parse_dt((string)p('planned_finish', '')) ?? $plannedStart,
            'unload_deadline'  => parse_dt((string)p('planned_unload', '')),
            'program_minutes'  => (int)p('program_minutes', 540),
            'hold_minutes'     => (int)p('hold_minutes', 0),
            'overhead_minutes' => (int)p('overhead_minutes', 60),
            'cone'             => (string)p('cone', ''),
        ]);
        if ($checkPlan['blocking']) {
            http_response_code(422);
            $pageTitle = 'That plan will not work';
            $active    = 'plan';
            require __DIR__ . '/partials/header.php';
            ?>
            <section class="card">
                <h2>That plan cannot be fired</h2>
                <?php foreach ($checkPlan['blocking'] as $problem): ?>
                    <p class="alert alert--stop"><?= e($problem) ?></p>
                <?php endforeach; ?>
                <p class="hint">Nothing was saved. Go back and adjust the times.</p>
                <p><a class="btn btn--ghost" href="javascript:history.back()">Back to the plan</a></p>
            </section>
            <?php
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        $id = firing_create([
            'kiln'          => $kilnId,
            'booking_day'   => $bookingDay->format('Y-m-d'),
            'fired_by'      => max(0, (int)p('fired_by', current_user_id())),
            'fired_by_name' => trim((string)p('fired_by_name', '')),
            'type'         => (string)p('type', 'glaze'),
            'program'      => (string)p('program', DEFAULT_PROGRAM),
            'cone'         => (string)p('cone', '6'),
            'hold_minutes' => (int)p('hold_minutes', 0),
            'planned'      => [
                'start_at'         => (string)p('planned_start', ''),
                'finish_at'        => (string)p('planned_finish', ''),
                'unload_by'        => (string)p('planned_unload', ''),
                'program_minutes'  => (int)p('program_minutes', 540),
                'preheat_minutes'  => (int)p('preheat_minutes', 0),
                'overhead_minutes' => (int)p('overhead_minutes', 60),
            ],
            'actual'       => [
                'start_at'    => (string)p('planned_start', ''),
                'end_at'      => '',
                'manual_stop' => false,
            ],
        ]);
        if ($id > 0) {
            redirect('firing.php?id=' . $id);
        }

        // Never bounce to id=0 — that just becomes a 404 with no explanation.
        http_response_code(500);
        $pageTitle = 'Could not save the firing';
        $active    = 'plan';
        require __DIR__ . '/partials/header.php';
        ?>
        <section class="card">
            <h2>The firing could not be saved</h2>
            <p class="alert alert--warn"><?= e(store_last_error() ?: store_diagnose() ?: 'The database refused the insert.') ?></p>
            <p class="hint">Nothing was written, so nothing is half-saved. Your plan is still in the browser
                &mdash; go back and press save again once this is sorted.</p>
            <p>
                <a class="btn" href="install.php">Open the installer</a>
                <a class="btn btn--ghost" href="javascript:history.back()">Back to the plan</a>
            </p>
        </section>
        <?php
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    // Backfill: give every firing with nobody on it the same person. Handy for
    // records written before firings recorded who fired them.
    if ($action === 'assign_unassigned') {
        $person  = (int)p('person', 0);
        $assigned = 0;
        if ($person > 0 && user_get($person)) {
            $statement = db_query(
                "UPDATE firings SET fired_by = ?, updated_at = ? WHERE fired_by = 0 AND fired_by_name = ''",
                [$person, db_datetime(now())]
            );
            $assigned = $statement ? $statement->rowCount() : 0;
        }
        redirect('firings.php?assigned=' . $assigned);
    }

    $id = (int)p('id', 0);
    $firing = firing_get($id);
    if (!$firing) {
        http_response_code(404);
        exit('No such firing.');
    }

    if ($action === 'check') {
        $item    = (string)p('item', '');
        $checked = p('checked') === '1';
        $valid   = [];
        foreach (checklist_for((string)$firing['kiln'], (string)$firing['type']) as $group) {
            foreach ($group['items'] as $listItem) {
                $valid[$listItem['id']] = true;
            }
        }
        if (!isset($valid[$item])) {
            json_out(['ok' => false, 'error' => 'unknown item']);
        }
        $checklist = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
        if ($checked) {
            $checklist[$item] = now()->format('c');
        } else {
            unset($checklist[$item]);
        }
        firing_update($id, ['checklist' => $checklist]);
        json_out(['ok' => true, 'done' => count($checklist)]);
    }

    // Untick everything in one visit. Useful when ticks predate a change to the
    // checklist itself, which is otherwise fiddly to unpick by hand.
    if ($action === 'clear_visit') {
        $clearVisit = (int)p('visit', 0);
        if ($clearVisit >= 1 && $clearVisit <= 3) {
            $checklist = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
            foreach (checklist_for_visit((string)$firing['kiln'], $clearVisit, (string)$firing['type']) as $group) {
                foreach ($group['items'] as $listItem) {
                    unset($checklist[$listItem['id']]);
                }
            }
            firing_update($id, ['checklist' => $checklist]);
        }
        redirect('firing.php?id=' . $id . '&visit=' . max(1, $clearVisit));
    }

    if ($action === 'temp') {
        $value = p('temp_c');
        if ($value !== null && $value !== '') {
            $at = parse_dt((string)p('temp_at', '')) ?? now();
            $temps = is_array($firing['temps'] ?? null) ? $firing['temps'] : [];
            $temps[] = ['at' => $at->format('c'), 'c' => (float)$value];
            usort($temps, static function ($a, $b) {
                return strcmp((string)$a['at'], (string)$b['at']);
            });
            firing_update($id, ['temps' => $temps]);
        }
        redirect('firing.php?id=' . $id . '#temps');
    }

    if ($action === 'temp_update') {
        $index = (int)p('index', -1);
        $temps = is_array($firing['temps'] ?? null) ? $firing['temps'] : [];
        if (isset($temps[$index])) {
            $value = p('temp_c');
            if ($value !== null && $value !== '') {
                $temps[$index]['c'] = (float)$value;
            }
            $at = parse_dt((string)p('temp_at', ''));
            if ($at) {
                $temps[$index]['at'] = $at->format('c');
            }
            usort($temps, static function ($a, $b) {
                return strcmp((string)$a['at'], (string)$b['at']);
            });
            firing_update($id, ['temps' => array_values($temps)]);
        }
        redirect('firing.php?id=' . $id . '#temps');
    }

    if ($action === 'temp_delete') {
        $index = (int)p('index', -1);
        $temps = is_array($firing['temps'] ?? null) ? $firing['temps'] : [];
        if (isset($temps[$index])) {
            unset($temps[$index]);
            firing_update($id, ['temps' => array_values($temps)]);
        }
        redirect('firing.php?id=' . $id . '#temps');
    }

    if ($action === 'delete') {
        firing_delete($id);
        // Come back to the list the way it was filtered, if we were told.
        $target = 'firings.php';
        parse_str((string)p('return', ''), $params);
        $keep = array_intersect_key($params, array_flip(['person', 'kiln']));
        if ($keep) {
            $target .= '?' . http_build_query($keep);
        }
        redirect($target);
    }

    if ($action === 'save') {
        $checklist = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
        $formVisit = (int)p('checklist_visit', 0);
        if ($formVisit >= 1 && $formVisit <= 3) {
            // Only one visit is ever on screen, so only its items may be cleared —
            // rebuilding the whole map here would untick the other two.
            $posted = is_array(p('checklist')) ? p('checklist') : [];
            foreach (checklist_for_visit((string)$firing['kiln'], $formVisit, (string)$firing['type']) as $group) {
                foreach ($group['items'] as $item) {
                    if (isset($posted[$item['id']])) {
                        $checklist[$item['id']] = $checklist[$item['id']] ?? now()->format('c');
                    } else {
                        unset($checklist[$item['id']]);
                    }
                }
            }
        }

        $startAt = parse_dt((string)p('actual_start', ''));
        $endAt   = parse_dt((string)p('actual_end', ''));

        $firedBy = (int)p('fired_by', 0);

        // Cone pack: positions come back by slot, the cone numbers are derived
        // from whatever cone the firing is set to now.
        $conePack = [];
        $posted   = p('cone_pos');
        if (is_array($posted)) {
            foreach (cone_pack_for((string)p('cone', $firing['cone'])) as $slot => $entry) {
                $conePack[] = [
                    'cone' => $entry['cone'],
                    'role' => $entry['role'],
                    'pos'  => cone_position_clamp((int)($posted[$slot] ?? 0)),
                ];
            }
        } else {
            $conePack = is_array($firing['cone_pack'] ?? null) ? $firing['cone_pack'] : [];
        }

        $ok = firing_update($id, [
            'cone_pack'     => $conePack,
            'fired_by'      => ($firedBy > 0 && !user_get($firedBy)) ? (int)($firing['fired_by'] ?? 0) : max(0, $firedBy),
            'fired_by_name' => trim((string)p('fired_by_name', (string)($firing['fired_by_name'] ?? ''))),
            'cone'         => (string)p('cone', $firing['cone']),
            'hold_minutes' => (int)p('hold_minutes', $firing['hold_minutes']),
            'controller_minutes' => p('controller_time') !== null
                ? (controller_time_parse((string)p('controller_time')) ?? 0)
                : (int)($firing['controller_minutes'] ?? 0),
            'actual'       => [
                'start_at'    => dt_store($startAt),
                'end_at'      => dt_store($endAt),
                'manual_stop' => p('manual_stop') === '1',
            ],
            // The free-text peep fields were replaced by the diagram; keep any
            // older values rather than blanking them on the next save.
            'cone_packs'   => [
                'top'    => p('cone_top') !== null ? trim((string)p('cone_top')) : (string)($firing['cone_packs']['top'] ?? ''),
                'middle' => p('cone_middle') !== null ? trim((string)p('cone_middle')) : (string)($firing['cone_packs']['middle'] ?? ''),
                'bottom' => p('cone_bottom') !== null ? trim((string)p('cone_bottom')) : (string)($firing['cone_packs']['bottom'] ?? ''),
            ],
            'shelves'     => trim((string)p('shelves', '')),
            'maintenance' => trim((string)p('maintenance', '')),
            'notes'       => trim((string)p('notes', '')),
            'checklist'   => $checklist,
        ]);
        redirect('firing.php?id=' . $id . ($ok ? '&saved=1' : '&error=1'));
    }

    redirect('firings.php');
}

/* ------------------------------------------------------------------ */
/* Read                                                                */
/* ------------------------------------------------------------------ */

$id = (int)q('id', 0);
$firing = firing_get($id);
if (!$firing) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/partials/header.php';
    echo '<section class="card"><h2>No such firing</h2><p><a href="firings.php">Back to firings</a></p></section>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$kiln     = kiln_get((string)$firing['kiln']);
$isManual = $kiln['kind'] === 'manual';
$program  = program_get((string)$firing['program']);
$checklist = checklist_for((string)$firing['kiln'], (string)$firing['type']);
$done     = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
$total    = checklist_count($checklist);

$plannedStart  = parse_dt($firing['planned']['start_at'] ?? '') ?? now();
$actualStart   = parse_dt($firing['actual']['start_at'] ?? '') ?? $plannedStart;
$programMinutes = (int)($firing['planned']['program_minutes'] ?? 540);
$preheatMinutes = (int)($firing['planned']['preheat_minutes'] ?? 0);
$overheadMinutes = (int)($firing['planned']['overhead_minutes'] ?? 60);
$holdMinutes     = (int)($firing['hold_minutes'] ?? 0);

// Rebuild the timeline from what was actually programmed. The hold is time at
// the top of the firing, so it counts towards the finish.
$syntheticFinish = add_minutes($actualStart, $programMinutes + $holdMinutes
    + ($preheatMinutes > 0 ? $preheatMinutes + $overheadMinutes : 0));
$unloadBy = parse_dt($firing['planned']['unload_by'] ?? '');
$planInput = [
    'start'            => $actualStart,
    'finish'           => $syntheticFinish,
    'unload_deadline'  => $unloadBy,
    'program_minutes'  => $programMinutes,
    'hold_minutes'     => (int)($firing['hold_minutes'] ?? 0),
    'overhead_minutes' => $overheadMinutes,
    'program_label'    => $isManual ? 'Firing' : $program['label'],
    'cone'             => (string)$firing['cone'],
];
$plan = $isManual ? plan_manual_firing($planInput) : plan_firing($planInput);

$actualMinutes = firing_actual_minutes($firing);

/* ------------------------------------------------------------------ */
/* Which visit                                                         */
/* ------------------------------------------------------------------ */

$visits = firing_visits();

// Default to the visit you are most likely standing in.
$defaultVisit = 1;
if (now() >= $plan['unload_start']) {
    $defaultVisit = 3;
} elseif (now() >= add_minutes($plan['actual_finish'], -(int)round(PRESENT_END_HOURS * 60))) {
    $defaultVisit = 2;
}
$visit = (int)q('visit', $defaultVisit);
if (!isset($visits[$visit])) {
    $visit = $defaultVisit;
}

// Progress per visit, so the tabs can carry it.
$visitProgress = [];
foreach (array_keys($visits) as $number) {
    $groups = checklist_for_visit((string)$firing['kiln'], $number, (string)$firing['type']);
    $items  = 0;
    $ticked = 0;
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $items++;
            $ticked += isset($done[$item['id']]) ? 1 : 0;
        }
    }
    $visitProgress[$number] = ['groups' => $groups, 'items' => $items, 'done' => $ticked];
}
$current = $visitProgress[$visit];

// The controller walkthrough belongs with the visit where you press the buttons.
$steps = [];
if ($visit === 1 && !$isManual) {
    $steps = controller_steps(
        (string)$firing['program'], (string)$firing['cone'],
        (int)$firing['hold_minutes'], $preheatMinutes, (string)$firing['kiln']
    );
    foreach ($steps as $i => $step) {
        $steps[$i]['masks'] = led_masks($step['display'], 4);
    }
}
$keypad = controller_keypad();

$pageTitle = $kiln['name'] . ' — ' . $visits[$visit]['title'];
$active    = 'log';
require __DIR__ . '/partials/header.php';
?>

<p class="backlink"><a href="firings.php">&larr; All firings</a></p>

<?php if (q('saved')): ?>
    <p class="alert alert--ok">Saved.</p>
<?php endif; ?>

<?php $storeProblem = store_diagnose(); ?>
<?php if (q('error') || $storeProblem !== ''): ?>
    <p class="alert alert--warn">
        <?= q('error') ? 'That change could not be saved. ' : '' ?>
        <?= e($storeProblem !== '' ? $storeProblem : 'Something is wrong with the database.') ?>
    </p>
<?php endif; ?>

<section class="card firing-head">
    <div class="record-head">
        <h2><?= e($kiln['name']) ?> &middot; <?= e(ucfirst((string)$firing['type'])) ?> cone <?= e($firing['cone']) ?></h2>
        <span class="muted"><?= e(firing_firer_name($firing)) ?></span>
    </div>
    <p class="hint">
        <?= e($isManual ? 'Kiln sitter' : $program['label']) ?> &middot;
        <?= e(fmt_minutes($programMinutes)) ?><?php
        if ($holdMinutes > 0): ?> plus a <?= e(fmt_minutes($holdMinutes)) ?> hold<?php endif; ?><?php
        if (!$isManual && $preheatMinutes > 0): ?> after a <?= e(controller_time($preheatMinutes)) ?> preheat<?php endif; ?>
        &middot; starts <?= e(fmt_clock($plan['actual_start'])) ?>
        &middot; ends <?= e(fmt_clock($plan['actual_finish'])) ?>
    </p>
</section>

<?php /* The whole 48 hours first: it is the context for all three visits, so it
         sits above the tabs that choose between them. */ ?>
<section class="card timeline-card">
    <?php require __DIR__ . '/partials/timeline-bars.php'; ?>

    <details class="whole-schedule">
        <?php /* Both labels are in the markup; the open state picks one. */ ?>
        <summary>
            <span class="when-closed">Expand all steps</span>
            <span class="when-open">Collapse all steps</span>
        </summary>
        <?php require __DIR__ . '/partials/timeline-steps.php'; ?>
    </details>
</section>

<nav class="visits">
    <?php foreach ($visits as $number => $meta): ?>
        <?php $progress = $visitProgress[$number]; ?>
        <a class="visit-tab <?= $number === $visit ? 'on' : '' ?>"
           href="firing.php?id=<?= e($firing['id']) ?>&visit=<?= e($number) ?>">
            <span class="visit-number">Visit <?= e($number) ?></span>
            <span class="visit-name"><?= e($meta['short']) ?></span>
            <span class="visit-count <?= $progress['items'] && $progress['done'] === $progress['items'] ? 'complete' : '' ?>"
                  data-visit-count="<?= e($number) ?>" data-visit-total="<?= e($progress['items']) ?>">
                <?= e($progress['done']) ?>/<?= e($progress['items']) ?>
            </span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="card visit-when">
    <h2><?= e($visits[$visit]['title']) ?></h2>
    <p class="hint"><?= e($visits[$visit]['blurb']) ?></p>

    <dl class="facts">
        <?php if ($visit === 1): ?>
            <div><dt>Press START</dt><dd><?= e(fmt_clock($plan['actual_start'])) ?></dd></div>
            <div><dt>Stay until</dt><dd><?= e(fmt_clock($plan['presence'][0]['end'])) ?></dd></div>
        <?php elseif ($visit === 2): ?>
            <div><dt>Be back by</dt><dd><?= e(fmt_clock($plan['presence'][1]['start'])) ?></dd></div>
            <div><dt>Finishes</dt><dd><?= e(fmt_clock($plan['actual_finish'])) ?></dd></div>
        <?php else: ?>
            <div><dt>Cool enough from</dt><dd><?= e(fmt_clock($plan['cool_early'])) ?></dd></div>
            <div><dt>Start unloading by</dt><dd><?= e(fmt_clock($plan['unload_start'])) ?></dd></div>
            <div><dt>Everything out by</dt><dd><?= e(fmt_clock($plan['unload_deadline'])) ?></dd></div>
        <?php endif; ?>
    </dl>
</section>

<section class="card" id="checklist">
    <div class="record-head">
        <h2>Checklist</h2>
        <span class="muted">
            <span id="check-done"><?= e($current['done']) ?></span> of <?= e($current['items']) ?>
            <?php if ($current['done'] > 0): ?>
                <form method="post" action="firing.php" class="inline"
                      onsubmit="return confirm('Untick everything in this visit?');">
                    <input type="hidden" name="action" value="clear_visit">
                    <input type="hidden" name="id" value="<?= e($firing['id']) ?>">
                    <input type="hidden" name="visit" value="<?= e($visit) ?>">
                    <button type="submit" class="btn btn--tiny">clear</button>
                </form>
            <?php endif; ?>
        </span>
    </div>
    <div class="progress"><span style="width:<?= $current['items'] ? round($current['done'] / $current['items'] * 100) : 0 ?>%"></span></div>
    <p class="alert alert--warn" id="check-error" hidden></p>

    <form method="post" action="firing.php" id="record-form" data-firing="<?= e($firing['id']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($firing['id']) ?>">
        <input type="hidden" name="checklist_visit" value="<?= e($visit) ?>">

        <?php foreach ($current['groups'] as $group): ?>
            <h3 class="phase"><?= e($group['phase']) ?></h3>
            <ul class="checks">
                <?php foreach ($group['items'] as $item): ?>
                    <li>
                        <label class="check">
                            <input type="checkbox" name="checklist[<?= e($item['id']) ?>]" value="1"
                                   data-item="<?= e($item['id']) ?>" <?= isset($done[$item['id']]) ? 'checked' : '' ?>>
                            <span class="box"></span>
                            <span class="check-text">
                                <?= e($item['text']) ?>
                                <?php if (!empty($item['note'])): ?>
                                    <em><?= e($item['note']) ?></em>
                                <?php endif; ?>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>

        <?php /* ---- what each visit needs to record ---- */ ?>

        <?php if ($visit === 1): ?>
            <h3 class="phase">Who and when</h3>
            <?php
            $firedBy     = (int)($firing['fired_by'] ?? 0);
            $firedByName = (string)($firing['fired_by_name'] ?? '');
            require __DIR__ . '/partials/firer-field.php';
            ?>
            <div class="grid-2">
                <div class="field">
                    <label for="actual_start">Actually started</label>
                    <input type="datetime-local" name="actual_start" id="actual_start" value="<?= e(dt_input(parse_dt($firing['actual']['start_at'] ?? ''))) ?>">
                    <p class="hint"><a href="#" class="linkish" data-set-now="actual_start">Now</a></p>
                </div>
                <div class="field">
                    <label for="cone">Cone</label>
                    <input type="text" name="cone" id="cone" value="<?= e($firing['cone']) ?>">
                </div>
            </div>

        <?php elseif ($visit === 2): ?>
            <h3 class="phase">How it ended</h3>
            <div class="grid-2">
                <div class="field">
                    <label for="actual_end">Finished (CPLT)</label>
                    <input type="datetime-local" name="actual_end" id="actual_end" value="<?= e(dt_input(parse_dt($firing['actual']['end_at'] ?? ''))) ?>">
                    <p class="hint"><a href="#" class="linkish" data-set-now="actual_end">Now</a></p>
                </div>
                <div class="field">
                    <?php $reported = (int)($firing['controller_minutes'] ?? 0); ?>
                    <label for="controller_time">Firing time on the display</label>
                    <input type="text" name="controller_time" id="controller_time" inputmode="decimal"
                           placeholder="12.30" value="<?= $reported > 0 ? e(controller_time($reported)) : '' ?>">
                    <p class="hint">
                        CPLT flashes with the length of the firing &mdash; type it as the kiln shows it,
                        <code>12.30</code> for twelve and a half hours.
                        <?php
                        $elapsed = firing_elapsed_minutes($firing);
                        if ($elapsed !== null):
                            $gap = $reported > 0 ? abs($reported - $elapsed) : 0;
                            ?>
                            Your recorded times say <?= e(fmt_minutes($elapsed)) ?><?php
                            if ($reported > 0 && $gap > 15): ?> &mdash; <?= e(fmt_minutes($gap)) ?>
                                off what the kiln says, so one of the two times above is probably wrong<?php
                            endif; ?>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <label class="check check--inline">
                <input type="checkbox" name="manual_stop" value="1" <?= !empty($firing['actual']['manual_stop']) ? 'checked' : '' ?>>
                <span class="box"></span>
                <span class="check-text">I stopped it manually rather than letting the controller finish</span>
            </label>

        <?php else: ?>
            <h3 class="phase">Witness cone pack</h3>
            <?php
            $pack       = cone_pack_for((string)$firing['cone']);
            $recorded   = is_array($firing['cone_pack'] ?? null) ? $firing['cone_pack'] : [];
            $posLabels  = cone_position_labels();
            $shortLabels = cone_position_short();
            $entries = [];
            foreach ($pack as $slot => $entry) {
                $entries[] = ['cone' => $entry['cone'], 'pos' => cone_position_clamp((int)($recorded[$slot]['pos'] ?? 0))];
            }
            ?>
            <p class="hint">How the cones looked when you unloaded. Coolest on the left, hottest on the right,
                because they fall to the left. Watch the numbering: a leading zero counts backwards, so glaze
                runs 5&middot;6&middot;7 and bisque runs 07&middot;06&middot;05 &mdash; the temperatures under
                each cone climb left to right either way.</p>

            <div class="cone-pack"
                 data-positions="<?= e(json_encode($posLabels)) ?>"
                 data-short="<?= e(json_encode($shortLabels)) ?>"
                 style="--cone-slots: <?= e(count($entries)) ?>">
                <?= cone_pack_svg($entries) ?>
                <div class="cone-steppers">
                    <?php foreach ($entries as $slot => $entry): ?>
                        <div class="cone-stepper" data-cone-slot data-slot="<?= e($slot) ?>">
                            <button type="button" class="btn btn--tiny" data-cone-step="-1"
                                    title="Less bent" aria-label="Cone <?= e($entry['cone']) ?>: less bent">&#9650;</button>
                            <span class="cone-state" title="<?= e($posLabels[$entry['pos']]) ?>"><?= e($shortLabels[$entry['pos']]) ?></span>
                            <button type="button" class="btn btn--tiny" data-cone-step="1"
                                    title="More bent" aria-label="Cone <?= e($entry['cone']) ?>: more bent">&#9660;</button>
                            <input type="hidden" name="cone_pos[<?= e($slot) ?>]" value="<?= e($entry['pos']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            $legacy = array_filter([
                'Top'    => (string)($firing['cone_packs']['top'] ?? ''),
                'Middle' => (string)($firing['cone_packs']['middle'] ?? ''),
                'Bottom' => (string)($firing['cone_packs']['bottom'] ?? ''),
            ], 'strlen');
            ?>
            <?php if ($legacy): ?>
                <p class="hint">Recorded before the diagram existed:
                    <?php foreach ($legacy as $where => $text): ?>
                        <?= e($where) ?> &mdash; <?= e($text) ?>.
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <h3 class="phase">Shelves and clean-up</h3>
            <div class="field">
                <label for="shelves">Shelves used (numbers)</label>
                <input type="text" name="shelves" id="shelves" value="<?= e($firing['shelves'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="maintenance">Maintenance performed</label>
                <textarea name="maintenance" id="maintenance" rows="2" placeholder="Ground a glaze drip off shelf 7, kiln washed 3 and 4&hellip;"><?= e($firing['maintenance'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="4" placeholder="How the glazes came out, anything odd about the firing&hellip;"><?= e($firing['notes'] ?? '') ?></textarea>
            </div>
        <?php endif; ?>

        <p class="hint" id="autosave-note" hidden>Everything here saves itself &mdash; clicks straight away,
            typing a second after you stop. The indicator at the top of the screen says when it last saved.</p>
        <noscript>
            <p class="alert alert--warn">This page saves as you edit it, which needs JavaScript.
                With JavaScript off, nothing you change here is recorded.</p>
        </noscript>
    </form>
</section>

<?php if ($visit === 1 && !$isManual): ?>
<section class="card controller-card">
    <h2>At the controller</h2>
    <p class="hint">What to press, in order. The panel shows the key and what the display should say.</p>
    <?php require __DIR__ . '/partials/controller.php'; ?>
</section>
<?php endif; ?>

<?php if ($visit === 1 && $isManual): ?>
<section class="card">
    <h2>Element settings</h2>
    <p class="hint">Up by 2 every hour: LO, 2, 4, 6, HI. Set the sitter timer to
        <strong><?= e($plan['timer_hours']) ?> hours</strong>.</p>
    <table class="steps-table">
        <thead><tr><th>Time</th><th>Elements</th></tr></thead>
        <tbody>
        <?php foreach ($plan['steps'] as $step): ?>
            <tr><td><?= e(fmt_clock($step['at'])) ?></td><td><strong><?= e($step['setting']) ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="card" id="temps">
    <h2>Hourly temperatures</h2>
    <p class="hint">
        <?= $visit === 3
            ? 'The record of how this firing went.'
            : 'Check and record the temperature every hour that you are present.' ?>
    </p>

    <?php $temps = is_array($firing['temps'] ?? null) ? $firing['temps'] : []; ?>
    <?php $firingId = (int)$firing['id']; ?>

    <p class="alert alert--warn" id="temp-error" hidden></p>

    <div id="temps-chart">
        <?php $chartCone = (string)$firing['cone']; ?>
        <?php require __DIR__ . '/partials/temps-chart.php'; ?>
    </div>

    <ul class="temps" id="temps-list" data-firing="<?= e($firingId) ?>">
        <?php require __DIR__ . '/partials/temps-list.php'; ?>
    </ul>

    <form method="post" action="firing.php" class="temp-form" id="temp-add">
        <input type="hidden" name="action" value="temp">
        <input type="hidden" name="id" value="<?= e($firingId) ?>">
        <input type="number" inputmode="decimal" step="1" name="temp_c" id="temp_c" placeholder="&deg;C" required>
        <span class="temp-when-field">
            <input type="datetime-local" name="temp_at" id="temp_at" value="<?= e(dt_input(now())) ?>">
            <button type="button" class="now-button" id="temp-now" title="Set to now" aria-label="Set the time to now">now</button>
        </span>
        <button type="submit" class="btn">Log it</button>
    </form>
    <p class="hint">The time keeps itself set to now until you change it. Tap a reading to correct it.</p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
