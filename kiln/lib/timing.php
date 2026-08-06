<?php
declare(strict_types=1);

/**
 * The arithmetic behind "I want this kiln to finish at X — what preheat do I
 * program?"
 *
 * finish = start + preheat ramp + preheat stage + cone fire program
 *
 * The ramp is the fixed cost of getting there (60F/hr up to 93C); the preheat
 * stage is the number you type into PRHT. "Hold" is deliberately not used for
 * either — in the kiln room that means the soak at top temperature.
 * Everything is done in whole minutes.
 */

/**
 * @param array $in kiln, start (DateTimeImmutable), finish (DateTimeImmutable),
 *                  program_minutes, hold_minutes, overhead_minutes, cone
 */
function plan_firing(array $in): array
{
    /** @var DateTimeImmutable $start */
    $start = $in['start'];
    /** @var DateTimeImmutable $finish */
    $finish = $in['finish'];

    $programMinutes  = max(0, (int)$in['program_minutes']);
    $overheadMinutes = max(0, (int)$in['overhead_minutes']);
    $holdMinutes     = max(0, (int)($in['hold_minutes'] ?? 0));

    // The hold is time at the top of the firing, after the programmed ramp —
    // the manual's word is "extends". So it counts against the clock like
    // everything else.
    $firingMinutes = $programMinutes + $holdMinutes;

    $availableMinutes = minutes_between($start, $finish);
    $spare = $availableMinutes - $firingMinutes;

    $plan = [
        'start'            => $start,
        'target_finish'    => $finish,
        'program_minutes'  => $programMinutes,
        'hold_minutes'     => $holdMinutes,
        'overhead_minutes' => $overheadMinutes,
        'available_minutes' => $availableMinutes,
        'spare_minutes'    => $spare,
        'preheat_minutes'  => 0,
        'warnings'         => [],
        // Warnings that make the plan impossible rather than merely unwise.
        // The planner refuses to save while there are any.
        'blocking'         => [],
        'notes'            => [],
    ];

    // The zero-preheat alternative: just press START later.
    $plan['press_start_at'] = add_minutes($finish, -$firingMinutes);

    if ($spare < -PREHEAT_ROUND_MINUTES) {
        // Not enough time left, no matter what.
        $plan['mode'] = 'too_late';
        $plan['actual_start']  = $start;
        $plan['actual_finish'] = add_minutes($start, $firingMinutes);
        $plan['late_by_minutes'] = -$spare;
        $plan['warnings'][] = 'Starting now, this program finishes ' . fmt_minutes(-$spare)
            . ' after your target. Shorten the program, or accept the later finish.';
    } elseif ($spare < $overheadMinutes + PREHEAT_ROUND_MINUTES) {
        // Enough time for the program, but not enough to make a preheat worthwhile.
        $plan['mode'] = 'start_later';
        $plan['actual_start']  = $plan['press_start_at'];
        $plan['actual_finish'] = $finish;
        $plan['wait_minutes']  = max(0, minutes_between($start, $plan['press_start_at']));
        $plan['notes'][] = 'There is not enough slack for a useful preheat — the preheat ramp alone eats '
            . fmt_minutes($overheadMinutes) . '. Just press START later.';
    } else {
        $usable  = $spare - $overheadMinutes;
        $preheat = (int)(floor($usable / PREHEAT_ROUND_MINUTES) * PREHEAT_ROUND_MINUTES);
        $plan['mode'] = 'preheat';
        $plan['preheat_minutes'] = $preheat;
        $plan['actual_start']    = $start;
        $plan['actual_finish']   = add_minutes($start, $overheadMinutes + $preheat + $firingMinutes);
        $plan['early_by_minutes'] = max(0, minutes_between($plan['actual_finish'], $finish));

        if ($preheat > 12 * 60) {
            $plan['warnings'][] = 'That is a ' . fmt_minutes($preheat)
                . ' preheat. Sanity-check the program length — or come back later and skip the preheat.';
        }
    }

    $actualStart  = $plan['actual_start'];
    $actualFinish = $plan['actual_finish'];

    /* -------------------------------------------------------------- */
    /* Timeline                                                        */
    /* -------------------------------------------------------------- */

    $segments = [];
    $cursor   = $actualStart;

    if ($plan['preheat_minutes'] > 0) {
        $rampEnd = add_minutes($cursor, $overheadMinutes);
        $segments[] = [
            'key'     => 'ramp',
            'label'   => 'Preheat ramp to 93C',
            'detail'  => 'Climbs at 60F/hr until it reaches 200F (93.3C).',
            'start'   => $cursor,
            'end'     => $rampEnd,
            'minutes' => $overheadMinutes,
        ];
        $cursor = $rampEnd;

        $holdEnd = add_minutes($cursor, $plan['preheat_minutes']);
        $segments[] = [
            'key'     => 'preheat',
            'label'   => 'Preheat stage at 93C',
            'detail'  => 'The number you type into PRHT. Drying time for the ware.',
            'start'   => $cursor,
            'end'     => $holdEnd,
            'minutes' => $plan['preheat_minutes'],
        ];
        $cursor = $holdEnd;
    }

    $programEnd = add_minutes($cursor, $programMinutes);
    $segments[] = [
        'key'     => 'program',
        'label'   => ($in['program_label'] ?? 'Cone fire') . ' to cone ' . ($in['cone'] ?? '?'),
        'detail'  => 'The programmed ramp up to temperature.',
        'start'   => $cursor,
        'end'     => $programEnd,
        'minutes' => $programMinutes,
    ];

    if ($holdMinutes > 0) {
        $segments[] = [
            'key'     => 'hold',
            'label'   => 'Hold at cone ' . ($in['cone'] ?? '?'),
            'detail'  => 'Soaking at temperature. The heat work can push the result to the next cone.',
            'start'   => $programEnd,
            'end'     => $actualFinish,
            'minutes' => $holdMinutes,
        ];
    }

    $coolStart = $actualFinish;
    $coolEarly = $coolStart->modify('+' . COOLING_HOURS_MIN . ' hours');
    $coolLate  = $coolStart->modify('+' . COOLING_HOURS_MAX . ' hours');
    $segments[] = [
        'key'     => 'cooling',
        'label'   => 'Cooling',
        'detail'  => COOLING_HOURS_MIN . '-' . COOLING_HOURS_MAX . ' hours, depending on the load, the season and how many kilns are running.',
        'start'   => $coolStart,
        'end'     => $coolLate,
        'minutes' => COOLING_HOURS_MAX * 60,
    ];

    $plan['segments'] = $segments;

    // Presence windows, drawn as bands over the firing.
    $presenceEnd    = add_minutes($actualStart, (int)round(PRESENT_START_HOURS * 60));
    $presenceReturn = add_minutes($actualFinish, -(int)round(PRESENT_END_HOURS * 60));
    $plan['presence'] = [
        [
            'label' => 'Stay',
            'start' => $actualStart,
            'end'   => $presenceEnd,
        ],
        [
            'label' => 'Be back',
            'start' => max($presenceReturn, $presenceEnd),
            'end'   => $actualFinish,
        ],
    ];

    /* -------------------------------------------------------------- */
    /* Points of interest                                              */
    /* -------------------------------------------------------------- */

    $unloadDeadline = $in['unload_deadline'] ?? null;
    if (!$unloadDeadline) {
        $unloadDeadline = $actualFinish->setTime(UNLOAD_HOUR, 0)->modify('+1 day');
        while ($unloadDeadline < $coolEarly) {
            $unloadDeadline = $unloadDeadline->modify('+1 day');
        }
    }

    // Emptying the kiln takes time of its own, so the real cut-off for cooling
    // is when unloading has to START, not the moment the booking ends.
    $unloadStart = add_minutes($unloadDeadline, -UNLOADING_MINUTES);
    $plan['unload_start']   = $unloadStart;
    $plan['unload_minutes'] = UNLOADING_MINUTES;

    // Added here rather than with the others: it depends on the deadline, which
    // is only known once we are this far down.
    if (UNLOADING_MINUTES > 0) {
        $plan['segments'][] = [
            'key'     => 'unload',
            'label'   => 'Unloading',
            'detail'  => 'Emptying the kiln and clearing up, worked back from the end of your booking.',
            'start'   => $unloadStart,
            'end'     => $unloadDeadline,
            'minutes' => UNLOADING_MINUTES,
        ];
    }
    $plan['latest_finish']  = $unloadStart->modify('-' . COOLING_HOURS_MAX . ' hours');
    $plan['unload_slack_minutes'] = minutes_between($coolEarly, $unloadStart);

    if ($unloadDeadline <= $actualFinish) {
        $plan['blocking'][] = 'Your unload deadline is before the firing even finishes. The kiln has to be out by '
            . fmt_clock($unloadDeadline) . ' and this firing does not end until ' . fmt_clock($actualFinish) . '.';
    } elseif ($unloadStart < $coolEarly) {
        $plan['blocking'][] = 'This finishes too late to cool in time. You need ' . COOLING_HOURS_MIN
            . ' hours before it is safe to empty, and ' . fmt_minutes(UNLOADING_MINUTES)
            . ' to empty it, so unloading would have to start at ' . fmt_clock($coolEarly)
            . ' — ' . fmt_minutes(minutes_between($unloadStart, $coolEarly)) . ' later than it can. '
            . 'To make it, the firing has to finish by ' . fmt_clock($plan['latest_finish']) . '.';
    } elseif ($unloadStart < $coolLate) {
        // Cooling takes 20-24 hours and you do not get to choose which. If the
        // slow end runs past the moment unloading has to start, the plan only
        // works if the kiln happens to cool quickly — which is not a plan.
        $plan['blocking'][] = 'Cooling might not be finished in time. A slow cool takes until '
            . fmt_clock($coolLate) . ', but unloading has to start at ' . fmt_clock($unloadStart)
            . ' to be out by ' . fmt_clock($unloadDeadline) . '. It would only work if this load cools '
            . 'at the quick end of the ' . COOLING_HOURS_MIN . '-' . COOLING_HOURS_MAX
            . ' hour range. Finish by ' . fmt_clock($plan['latest_finish']) . ' to be sure.';
    }

    $markers = [];
    $markers[] = ['at' => $actualStart, 'label' => 'Press START', 'kind' => 'action',
        'detail' => 'Display flashes ON with the current temperature.'];
    $markers[] = ['at' => $presenceEnd, 'label' => 'Free to leave', 'kind' => 'presence',
        'detail' => 'One hour in. You must be in the building until now.'];

    if ($plan['preheat_minutes'] > 0) {
        $markers[] = ['at' => add_minutes($actualStart, $overheadMinutes), 'label' => 'Preheat stage begins', 'kind' => 'milestone',
            'detail' => 'Kiln has reached 93C and stays there for ' . fmt_minutes($plan['preheat_minutes']) . '.'];
        $markers[] = ['at' => add_minutes($actualStart, $overheadMinutes + $plan['preheat_minutes']), 'label' => 'Cone fire program begins', 'kind' => 'milestone',
            'detail' => 'Rolls straight into the cone fire program with no input from you.'];
    }

    if ($holdMinutes > 0) {
        $markers[] = ['at' => $programEnd, 'label' => 'Top temperature reached — hold begins', 'kind' => 'milestone',
            'detail' => 'Soaks for ' . fmt_minutes($holdMinutes) . ' before CPLT. The controller counts this down on the display.'];
    }

    $markers[] = ['at' => $presenceReturn, 'label' => 'Be back at the kiln', 'kind' => 'presence',
        'detail' => 'You must be present for the last two hours. Start watching the cone packs — dark safety glasses on.'];
    $markers[] = ['at' => $actualFinish, 'label' => 'Firing complete (CPLT)', 'kind' => 'finish',
        'detail' => 'Turn off the kiln power switch, then the kiln fan, then the room fans. Record the end time and total hours.'];
    /*
     * Cracking the lid happens at about 100C, emptying it at about 52C, so it
     * always comes first — and by a decent margin, since cooling slows as the
     * kiln approaches room temperature. Derived from the cooling window rather
     * than a fixed number of hours: a hard-coded figure lands anywhere once the
     * window is changed in Settings, including on top of the safe-unload mark.
     */
    $crackMinutes = (int)round(COOLING_HOURS_MIN * 60 * 0.55);
    $crackAt = add_minutes($coolStart, max(60, $crackMinutes));
    if ($crackAt >= $coolEarly) {
        $crackAt = add_minutes($coolEarly, -60);
    }
    if ($crackAt > $coolStart) {
        $markers[] = ['at' => $crackAt, 'label' => 'Roughly 100C — safe to crack the lid', 'kind' => 'cooling',
            'detail' => 'An estimate from the cooling window, not a measurement. Turn the kiln on to read the '
                . 'actual temperature before you crack the lid and open the peeps.'];
    }
    $markers[] = ['at' => $coolEarly, 'label' => 'Earliest safe unload', 'kind' => 'cooling',
        'detail' => 'Do not open the lid wide until about 52C or room temperature — glazes craze and lose vibrance if you rush it.'];
    $markers[] = ['at' => $coolLate, 'label' => 'Cool by now in most cases', 'kind' => 'cooling',
        'detail' => 'Big loads, glaze firings and a busy kiln room all push this later.'];
    $markers[] = ['at' => $unloadStart, 'label' => 'Start unloading', 'kind' => 'action',
        'detail' => 'Allow ' . fmt_minutes(UNLOADING_MINUTES) . ': ware out, shelves inspected and washed, '
            . 'posts scraped, kiln vacuumed, cone packs and shelf numbers into the log, pots out of the room.'];
    $markers[] = ['at' => $unloadDeadline, 'label' => 'Unloaded and cleaned up by here', 'kind' => 'deadline',
        'detail' => 'End of your booking — the next load can begin.'];

    usort($markers, static function ($a, $b) {
        return $a['at']->getTimestamp() <=> $b['at']->getTimestamp();
    });
    $plan['markers'] = $markers;

    // Three bands, one per trip to the workshop.
    $plan['visit_bands'] = [
        1 => ['number' => 1, 'label' => 'Load',   'start' => $actualStart,  'end' => $presenceEnd],
        2 => ['number' => 2, 'label' => 'End',    'start' => max($presenceReturn, $presenceEnd), 'end' => $actualFinish],
        3 => ['number' => 3, 'label' => 'Unload', 'start' => $unloadStart,  'end' => $unloadDeadline],
    ];

    // The booking window when the caller knows it — exactly 48 hours, 6 pm to
    // 6 pm — otherwise just far enough to hold everything.
    $plan['window_start'] = $in['window_start'] ?? $actualStart;
    $plan['window_end']   = $in['window_end']
        ?? ($unloadDeadline > $coolLate ? $unloadDeadline : $coolLate);
    $plan['cool_early']   = $coolEarly;
    $plan['cool_late']    = $coolLate;
    $plan['unload_deadline'] = $unloadDeadline;

    if ($presenceReturn < $presenceEnd) {
        $plan['notes'][] = 'This firing is short enough that the first hour and the last two hours overlap — plan to stay for the whole thing.';
    }

    return $plan;
}

/**
 * Rebuild the schedule for a stored firing.
 *
 * The record keeps what was programmed, not the derived times, so anything
 * that needs "when is visit 2" — the page, the reminder cron — comes through
 * here and gets the same answer.
 */
function firing_plan(array $firing): array
{
    $isManual = kiln_get((string)$firing['kiln'])['kind'] === 'manual';

    $start = parse_dt($firing['actual']['start_at'] ?? '')
        ?? parse_dt($firing['planned']['start_at'] ?? '')
        ?? now();

    $programMinutes  = (int)($firing['planned']['program_minutes'] ?? 0);
    $preheatMinutes  = (int)($firing['planned']['preheat_minutes'] ?? 0);
    $overheadMinutes = (int)($firing['planned']['overhead_minutes'] ?? 0);
    $holdMinutes     = (int)($firing['hold_minutes'] ?? 0);

    $input = [
        'start'            => $start,
        // Program plus hold, plus the preheat when there was one.
        'finish'           => add_minutes($start, $programMinutes + $holdMinutes
            + ($preheatMinutes > 0 ? $preheatMinutes + $overheadMinutes : 0)),
        'unload_deadline'  => parse_dt($firing['planned']['unload_by'] ?? ''),
        'program_minutes'  => $programMinutes,
        'hold_minutes'     => $holdMinutes,
        'overhead_minutes' => $overheadMinutes,
        'program_label'    => $isManual ? 'Firing' : program_get((string)$firing['program'])['label'],
        'cone'             => (string)($firing['cone'] ?? ''),
    ];

    return $isManual ? plan_manual_firing($input) : plan_firing($input);
}

/**
 * When each of the three visits obliges you to be at the workshop.
 *
 * @return array [1 => DateTimeImmutable, 2 => …, 3 => …]
 */
function firing_visit_times(array $plan): array
{
    return [
        1 => $plan['actual_start'],
        2 => $plan['presence'][1]['start'],
        3 => $plan['unload_start'] ?? $plan['unload_deadline'],
    ];
}

/**
 * The canonical day of a booking.
 *
 * A booking is one named day, but it runs from 6 pm the evening before to
 * 6 pm the day after — three calendar days of kiln room access for one day's
 * name. So an evening start belongs to the *next* day's booking: loading at
 * 8 pm on Friday is the Saturday booking.
 */
function booking_day_from_start(DateTimeImmutable $start): DateTimeImmutable
{
    $day = $start->setTime(0, 0);
    return (int)$start->format('G') >= UNLOAD_HOUR ? $day->modify('+1 day') : $day;
}

/**
 * Kiln 1 has no controller: you turn the elements up by 2 every hour and the
 * sitter drops the weight when the cone bends. So the plan is a list of times
 * to be standing at the kiln, and a timer setting.
 */
function plan_manual_firing(array $in): array
{
    /** @var DateTimeImmutable $start */
    $start = $in['start'];
    $programMinutes = max(0, (int)$in['program_minutes']);
    $finish = add_minutes($start, $programMinutes);

    $steps = [];
    foreach (manual_kiln_steps() as $i => $setting) {
        $steps[] = [
            'at'      => $start->modify('+' . $i . ' hours'),
            'setting' => $setting,
            'label'   => $i === 0 ? 'Switch on, elements to LO' : 'Turn the elements up to ' . $setting,
        ];
    }

    // "Make sure the timer on the sitter is 30 mins to 1 hour higher than the
    // time anticipated to fire." The apprentice sheet says at least 2 hours.
    $timerHours = (int)ceil($programMinutes / 60) + 1;

    $hiAt      = $start->modify('+' . (count(manual_kiln_steps()) - 1) . ' hours');
    $returnAt  = add_minutes($finish, -(int)round(PRESENT_END_HOURS * 60));
    $coolEarly = $finish->modify('+' . COOLING_HOURS_MIN . ' hours');
    $coolLate  = $finish->modify('+' . COOLING_HOURS_MAX . ' hours');

    $unloadDeadline = $in['unload_deadline'] ?? null;
    if (!$unloadDeadline) {
        $unloadDeadline = $finish->setTime(UNLOAD_HOUR, 0)->modify('+1 day');
        while ($unloadDeadline < $coolEarly) {
            $unloadDeadline = $unloadDeadline->modify('+1 day');
        }
    }

    $unloadStart = add_minutes($unloadDeadline, -UNLOADING_MINUTES);
    $latestFinish = $unloadStart->modify('-' . COOLING_HOURS_MAX . ' hours');

    $warnings = [];
    $notes = ['The kiln sitter ends the firing when the cone bends, so treat the finish time as an estimate from past firings.'];
    $blocking = [];
    if ($unloadDeadline <= $finish) {
        $blocking[] = 'Your unload deadline is before the firing is expected to finish.';
    } elseif ($unloadStart < $coolEarly) {
        $blocking[] = 'This finishes too late to cool in time — the earliest safe unload is ' . fmt_clock($coolEarly)
            . ', and unloading takes ' . fmt_minutes(UNLOADING_MINUTES) . ', which runs '
            . fmt_minutes(minutes_between($unloadStart, $coolEarly)) . ' past where it can start. '
            . 'The firing has to finish by ' . fmt_clock($latestFinish) . '.';
    } elseif ($unloadStart < $coolLate) {
        $blocking[] = 'Cooling might not be finished in time. A slow cool takes until '
            . fmt_clock($coolLate) . ', but unloading has to start at ' . fmt_clock($unloadStart)
            . '. Finish by ' . fmt_clock($latestFinish) . ' to be sure.';
    }

    $markers = [];
    foreach ($steps as $step) {
        $markers[] = ['at' => $step['at'], 'label' => $step['label'], 'kind' => 'action',
            'detail' => 'Check the pyrometer and write the temperature in the log.'];
    }
    $markers[] = ['at' => $hiAt, 'label' => 'On HI — you may leave', 'kind' => 'presence',
        'detail' => 'New January 2023: you must stay at the workshop until the kiln is set to HI.'];
    $markers[] = ['at' => $returnAt, 'label' => 'Be back at the kiln', 'kind' => 'presence',
        'detail' => 'Return for the last two hours. Watch the cone packs through the peeps.'];
    $markers[] = ['at' => $finish, 'label' => 'Expected sitter shut-off', 'kind' => 'finish',
        'detail' => 'Estimated from your logged firings — the sitter decides, not the clock. Turn the element knob back to OFF, then the fans off.'];
    $markers[] = ['at' => $coolEarly, 'label' => 'Earliest safe unload', 'kind' => 'cooling',
        'detail' => 'Crack the lid at about 100C; do not open wide until about 52C.'];
    $markers[] = ['at' => $coolLate, 'label' => 'Cool by now in most cases', 'kind' => 'cooling', 'detail' => ''];
    $markers[] = ['at' => $unloadStart, 'label' => 'Start unloading', 'kind' => 'action',
        'detail' => 'Allow ' . fmt_minutes(UNLOADING_MINUTES) . ': ware out, shelves inspected and washed, '
            . 'kiln vacuumed, cone packs and shelf numbers into the log, pots out of the room.'];
    $markers[] = ['at' => $unloadDeadline, 'label' => 'Unloaded and cleaned up by here', 'kind' => 'deadline',
        'detail' => 'End of your booking.'];

    usort($markers, static function ($a, $b) {
        return $a['at']->getTimestamp() <=> $b['at']->getTimestamp();
    });

    return [
        'mode'            => 'manual',
        'start'           => $start,
        'actual_start'    => $start,
        'actual_finish'   => $finish,
        'target_finish'   => $finish,
        'program_minutes' => $programMinutes,
        'preheat_minutes' => 0,
        'hold_minutes'    => 0,
        'steps'           => $steps,
        'timer_hours'     => $timerHours,
        'markers'         => $markers,
        'presence'        => [
            ['label' => 'Stay', 'start' => $start, 'end' => $hiAt],
            ['label' => 'Be back', 'start' => max($returnAt, $hiAt), 'end' => $finish],
        ],
        'visit_bands'     => [
            1 => ['number' => 1, 'label' => 'Load',   'start' => $start, 'end' => $hiAt],
            2 => ['number' => 2, 'label' => 'End',    'start' => max($returnAt, $hiAt), 'end' => $finish],
            3 => ['number' => 3, 'label' => 'Unload', 'start' => $unloadStart, 'end' => $unloadDeadline],
        ],
        'segments' => array_values(array_filter([
            ['key' => 'program', 'label' => 'Firing, elements stepped up hourly', 'detail' => '', 'start' => $start, 'end' => $finish, 'minutes' => $programMinutes],
            ['key' => 'cooling', 'label' => 'Cooling', 'detail' => COOLING_HOURS_MIN . '-' . COOLING_HOURS_MAX . ' hours.', 'start' => $finish, 'end' => $coolLate, 'minutes' => COOLING_HOURS_MAX * 60],
            UNLOADING_MINUTES > 0
                ? ['key' => 'unload', 'label' => 'Unloading', 'detail' => 'Emptying the kiln and clearing up.', 'start' => $unloadStart, 'end' => $unloadDeadline, 'minutes' => UNLOADING_MINUTES]
                : null,
        ])),
        'window_start' => $in['window_start'] ?? $start,
        'window_end'   => $in['window_end'] ?? ($unloadDeadline > $coolLate ? $unloadDeadline : $coolLate),
        'cool_early'   => $coolEarly,
        'cool_late'    => $coolLate,
        'unload_deadline' => $unloadDeadline,
        'unload_start'    => $unloadStart,
        'unload_minutes'  => UNLOADING_MINUTES,
        'latest_finish'   => $latestFinish,
        'unload_slack_minutes' => minutes_between($coolEarly, $unloadStart),
        'warnings'     => $warnings,
        'blocking'     => $blocking,
        'notes'        => $notes,
    ];
}
