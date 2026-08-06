<?php
declare(strict_types=1);

/**
 * Static reference data pulled from the WPW Electric Kiln Manual
 * (June 2022, rev. January & July 2023) and the Bartlett V6-CF Series 700
 * controller that Euclid ships on the computer kilns.
 *
 * This file is meant to be edited. Key positions, display wording, checklist
 * items and program lengths all live here so you can correct them against the
 * real controller without touching any logic.
 */

/**
 * The kiln manifest, read from config/kilns.json. That file is hand-edited and
 * shown read-only at kilns.php; if it is missing or broken we fall back to the
 * bare minimum below and say so in the UI.
 *
 * Returns ['studio' => [...], 'kilns' => [id => kiln], 'source' => ..., 'error' => ...]
 */
/**
 * Which file a manifest should be read from: the live one you edit on the
 * server, or the .example copy that ships with the app.
 *
 * @return array [path, isExample] — path is '' when neither exists.
 */
function manifest_source(string $live, string $example): array
{
    if (is_file($live)) {
        return [$live, false];
    }
    if (is_file($example)) {
        return [$example, true];
    }
    return ['', false];
}

function kilns_manifest(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $studio = [];
    $kilns  = [];
    $source = 'built-in';
    $error  = '';

    [$path, $isExample] = manifest_source(KILNS_FILE, KILNS_EXAMPLE_FILE);

    if ($path === '') {
        $error = 'No config/kilns.json found, so the built-in kiln list is being used.';
    } else {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            $error = 'config/' . basename($path) . ' could not be parsed (' . json_last_error_msg() . '), so the built-in kiln list is being used.';
        } elseif (empty($decoded['kilns']) || !is_array($decoded['kilns'])) {
            $error = 'config/' . basename($path) . ' has no "kilns" list, so the built-in kiln list is being used.';
        } else {
            $studio = is_array($decoded['studio'] ?? null) ? $decoded['studio'] : [];
            foreach ($decoded['kilns'] as $entry) {
                if (!is_array($entry) || !isset($entry['id'])) {
                    continue;
                }
                $id = (string)$entry['id'];
                if ($id === '') {
                    continue;
                }
                $entry['id']    = $id;
                $entry['name']  = (string)($entry['name'] ?? ('Kiln ' . $id));
                $entry['kind']  = ($entry['kind'] ?? '') === 'manual' ? 'manual' : 'computer';
                $entry['blurb'] = (string)($entry['blurb'] ?? '');
                $kilns[$id] = $entry;
            }
            if ($kilns) {
                $source = 'config/' . basename($path);
                if ($isExample) {
                    $error = 'Running from the shipped example. Copy config/kilns.example.json to '
                        . 'config/kilns.json on the server and edit that — the example is overwritten '
                        . 'every time you upload the app.';
                }
            } else {
                $error = 'config/' . basename($path) . ' has no usable kiln entries, so the built-in kiln list is being used.';
            }
        }
    }

    if (!$kilns) {
        $kilns = kilns_builtin();
    }

    $cache = ['studio' => $studio, 'kilns' => $kilns, 'source' => $source, 'error' => $error];
    return $cache;
}

/** Last resort if the manifest is missing — enough to keep the app usable. */
function kilns_builtin(): array
{
    return [
        '1' => ['id' => '1', 'name' => 'Kiln 1', 'kind' => 'manual',   'blurb' => 'R-85 with LT-3K kiln sitter'],
        '2' => ['id' => '2', 'name' => 'Kiln 2', 'kind' => 'computer', 'blurb' => 'Power switch is on the wall.'],
        '3' => ['id' => '3', 'name' => 'Kiln 3', 'kind' => 'computer', 'blurb' => 'Power switch is attached to the kiln.'],
        '4' => ['id' => '4', 'name' => 'Kiln 4', 'kind' => 'computer', 'blurb' => 'Power switch is attached to the kiln.'],
    ];
}

function kiln_list(): array
{
    return kilns_manifest()['kilns'];
}

/**
 * Fields shown on the kiln page, in this order. Anything else in the manifest
 * is shown after these with its key tidied into a label, so adding a field to
 * the JSON is all it takes to see it in the UI.
 */
function kiln_field_labels(): array
{
    return [
        'make'             => 'Make',
        'model'            => 'Model',
        'controller'       => 'Controller',
        'power_switch'     => 'Power switch',
        'element_settings' => 'Element settings',
        'venting'          => 'Venting',
        'cones'            => 'Cones',
        'max_cone'         => 'Maximum cone',
        'chamber'          => 'Chamber',
        'shelves'          => 'Shelves',
        'posts'            => 'Posts',
        'thermocouples'    => 'Thermocouples',
    ];
}

/** Manifest fields that are displayed some other way, not as a plain row. */
function kiln_special_fields(): array
{
    return ['id', 'name', 'kind', 'blurb', 'quirks', 'notes'];
}

function kiln_get(string $id): array
{
    $all = kiln_list();
    return $all[$id] ?? $all[DEFAULT_KILN] ?? reset($all);
}

/**
 * Cone Fire programs. The hours are starting points, editable in Settings —
 * and the app will offer your own logged average once you have fired the same
 * program a couple of times.
 */
function program_list(): array
{
    return [
        'slow_bisque' => [
            'label' => 'Slow Bisque',
            'type'  => 'bisque',
            'hours' => (float)setting('program_hours_slow_bisque', 12.0),
            'cone'  => '06',
            'recommended' => true,
        ],
        'fast_bisque' => [
            'label' => 'Fast Bisque',
            'type'  => 'bisque',
            'hours' => (float)setting('program_hours_fast_bisque', 9.0),
            'cone'  => '06',
            'recommended' => false,
        ],
        'slow_glaze' => [
            'label' => 'Slow Glaze',
            'type'  => 'glaze',
            'hours' => (float)setting('program_hours_slow_glaze', 9.0),
            'cone'  => '6',
            'recommended' => true,
        ],
        'fast_glaze' => [
            'label' => 'Fast Glaze',
            'type'  => 'glaze',
            'hours' => (float)setting('program_hours_fast_glaze', 5.0),
            'cone'  => '6',
            'recommended' => false,
        ],
    ];
}

function program_get(string $key): array
{
    $all = program_list();
    return $all[$key] ?? $all[DEFAULT_PROGRAM] ?? reset($all);
}

/**
 * Cone -> temperature. The manual quotes cone 06 = 998C and cone 6 = 1223C;
 * the rest are Orton's regular-cone figures at 108F/hr. Your controller's own
 * table is the one that counts.
 */
function cone_table(): array
{
    return [
        '010' => 903, '09' => 920, '08' => 942, '07' => 976, '06' => 998,
        '05'  => 1031, '04' => 1063, '03' => 1086, '02' => 1102, '01' => 1119,
        '1'   => 1137, '2' => 1142, '3' => 1152, '4' => 1162, '5' => 1186,
        '6'   => 1223, '7' => 1239, '8' => 1249, '9' => 1260, '10' => 1285,
    ];
}

function cone_temp(string $cone): ?int
{
    $t = cone_table();
    return $t[$cone] ?? null;
}

/** Cone "06" is cooler than cone "6": leading zero means below cone 1. */
function cone_rank(string $cone): float
{
    $cone = trim($cone);
    if ($cone === '') {
        return 0;
    }
    if ($cone[0] === '0') {
        return -(float)ltrim($cone, '0');
    }
    return (float)$cone;
}

/* ------------------------------------------------------------------------ */
/* Controller face                                                           */
/* ------------------------------------------------------------------------ */

/**
 * The keypad, as a grid. Rows of keys; each key has an id (used to highlight
 * it during the walkthrough) and a label. Move things around here until it
 * matches the real Euclid/Bartlett panel — nothing else needs to change.
 */
function controller_keypad(): array
{
    return [
        'brand' => 'EUCLID KILNS',
        'model' => 'Manufactured by Bartlett Instrument Co.',
        // Three columns, matching the panel: Vary-Fire and Options down the
        // left, the number pad in the middle, Cone Fire speeds down the right.
        'left'  => [
            ['heading' => 'VARY-FIRE PROGRAMMER', 'keys' => [
                ['id' => 'enter_prog',  'label' => "ENTER\nPROG",  'small' => true],
                ['id' => 'recall_prog', 'label' => "RECALL\nPROG", 'small' => true],
            ]],
            ['heading' => 'OPTIONS', 'stack' => true, 'keys' => [
                ['id' => 'delay', 'label' => 'Delay', 'small' => true],
                ['id' => 'alarm', 'label' => 'Alarm', 'small' => true],
                ['id' => 'other', 'label' => 'Other', 'small' => true],
            ]],
        ],
        'rows'  => [
            [['id' => '1', 'label' => '1'], ['id' => '2', 'label' => '2'], ['id' => '3', 'label' => '3']],
            [['id' => '4', 'label' => '4'], ['id' => '5', 'label' => '5'], ['id' => '6', 'label' => '6']],
            [['id' => '7', 'label' => '7'], ['id' => '8', 'label' => '8'], ['id' => '9', 'label' => '9']],
            [['id' => '0', 'label' => '0'], ['id' => 'enter', 'label' => 'ENTER', 'span' => 2]],
        ],
        'right' => [
            ['heading' => 'CONE FIRE', 'stack' => true, 'keys' => [
                ['id' => 'slow_bisque', 'label' => "Slow\nBisque"],
                ['id' => 'fast_bisque', 'label' => "Fast\nBisque"],
                ['id' => 'slow_glaze',  'label' => "Slow\nGlaze"],
                ['id' => 'fast_glaze',  'label' => "Fast\nGlaze"],
            ]],
        ],
        'bottom' => [
            ['id' => 'cone_table',     'label' => "Cone\nTable"],
            ['id' => 'review_program', 'label' => "Review\nProgram"],
            ['id' => 'view_segment',   'label' => "View\nSegment"],
        ],
        'start' => ['id' => 'start', 'label' => "START\nSTOP"],
    ];
}

/**
 * Which key selects each Cone Fire speed. Confirmed from photographs of the
 * panel: each speed has its own labelled button down the right-hand side.
 */
function speed_keys(): array
{
    return [
        'slow_bisque' => ['key' => 'slow_bisque', 'display' => 'S-bS', 'verified' => true],
        'fast_bisque' => ['key' => 'fast_bisque', 'display' => 'F-bS', 'verified' => true],
        'slow_glaze'  => ['key' => 'slow_glaze',  'display' => 'S-GL', 'verified' => true],
        'fast_glaze'  => ['key' => 'fast_glaze',  'display' => 'F-GL', 'verified' => true],
    ];
}

/**
 * Build the button-by-button walkthrough for a specific firing.
 * Each step: keys to press, what the display should read, what to do.
 */
function controller_steps(string $programKey, string $cone, int $holdMinutes, int $preheatMinutes, string $kilnId = ''): array
{
    $program = program_get($programKey);
    $speed   = speed_keys()[$programKey] ?? ['key' => '1', 'display' => 'CONE', 'verified' => false];
    $steps   = [];

    $steps[] = [
        'keys'    => [],
        'display' => 'WAIT',
        'title'   => 'Turn on the power switch',
        'detail'  => 'On the side of the control box. Kiln 2 is the odd one out — its switch is on the wall. The display reads WAIT, or the length and temperature of the last firing.',
    ];
    $steps[] = [
        'keys'    => ['enter'],
        'display' => 'IdLE',
        'title'   => 'Press ENTER to reach IDLE',
        'detail'  => 'If the display is showing the last firing, press ENTER until you get IDLE. The temperature flashes alongside it — that flashing is what tells you the controller is ready to be programmed.',
    ];
    $steps[] = [
        'keys'    => [$speed['key']],
        'display' => $speed['display'],
        'title'   => 'Choose ' . $program['label'],
        'detail'  => 'While the temperature is flashing, select your firing speed.'
            . ($speed['verified'] ? '' : ' (Check this key against your panel — the manual does not name it.)'),
        'unverified' => !$speed['verified'],
    ];
    $steps[] = [
        'keys'    => ['enter'],
        'display' => 'CONE',
        'title'   => 'Press ENTER',
        'detail'  => 'The display confirms your choice, then asks for the cone number.',
    ];
    $steps[] = [
        'keys'    => str_split($cone),
        'display' => $cone,
        'title'   => 'Type cone ' . $cone,
        'detail'  => 'To correct a mistake, press 0 three times, then ENTER, and start the number again.',
    ];
    $steps[] = [
        'keys'    => ['enter'],
        'display' => 'HOLd',
        'title'   => 'Press ENTER',
        'detail'  => 'Now it wants the hold (soak) time.',
    ];

    $holdText   = sprintf('%d.%02d', intdiv($holdMinutes, 60), $holdMinutes % 60);
    $holdDigits = array_values(array_filter(str_split(str_replace('.', '', $holdText)), 'strlen'));
    $steps[] = [
        'keys'    => $holdDigits,
        'display' => $holdText,
        'title'   => $holdMinutes > 0
            ? 'Type ' . implode('', $holdDigits) . ' for a ' . $holdMinutes . ' minute hold'
            : 'Leave the hold at 0',
        'detail'  => $holdMinutes > 0
            ? 'Hold extends the time at the end of firing without raising the temperature, but the extra heat work can push you to the next cone — cone 5 with a 15 minute hold reaches a soft cone 6. The cheat sheet suggests 10 to 30 minutes for glaze.'
            : 'No soak, which is the usual choice for bisque. Type 0 or leave it as it is.',
    ];
    $steps[] = [
        'keys'    => ['enter'],
        'display' => 'IdLE',
        'title'   => 'Press ENTER',
        'detail'  => 'IDLE TC 2 flashes briefly, then the current temperature starts flashing again.',
    ];

    if ($preheatMinutes > 0) {
        $preheatText   = sprintf('%d.%02d', intdiv($preheatMinutes, 60), $preheatMinutes % 60);
        $preheatDigits = array_values(array_filter(str_split(str_replace('.', '', $preheatText)), 'strlen'));
        $steps[] = [
            'keys'    => ['other', 'other'],
            'display' => 'PRHT',
            'title'   => 'Press OTHER twice',
            'detail'  => 'Two presses lands on PRHT. If it never appears, Cone Fire mode was not selected — back out, pick a Cone Fire speed, then come back.',
        ];
        $steps[] = [
            'keys'    => ['enter'],
            'display' => '0.00',
            'title'   => 'Press ENTER to select preheat',
            'detail'  => 'Preheat is reset to zero after every firing, so it has to be re-entered each time.',
        ];
        $steps[] = [
            'keys'    => $preheatDigits,
            'display' => $preheatText,
            'title'   => 'Type ' . implode('', $preheatDigits),
            'detail'  => 'No decimal point — ' . implode('', $preheatDigits) . ' shows as ' . $preheatText
                . ', hours left of the point and minutes right of it. If you fumble it, press zero four times and start again.',
        ];
        $steps[] = [
            'keys'    => ['enter'],
            'display' => 'CPL',
            'title'   => 'Press ENTER',
            'detail'  => 'CPL flashes a few times to confirm the preheat was accepted, then the current temperature returns to the display.',
        ];
    }

    $steps[] = [
        'keys'    => ['review_program'],
        'display' => 'rEV',
        'title'   => 'Press Review Program (optional)',
        'detail'  => 'Steps through what you actually entered, before or during the firing. Worth ten seconds.',
        'optional' => true,
    ];

    // The cheat sheet's last-minute check, done standing at the kiln with the
    // program already entered.
    $steps[] = [
        'keys'    => [],
        'display' => 'IdLE',
        'title'   => 'Last-minute check',
        'detail'  => 'Everything below before you press START.',
        'checks'  => [
            'Peep holes closed',
            'Latch is on',
            'Lid aligned with the bottom' . ($kilnId === '2' || $kilnId === '3' ? ' — this one especially' : ''),
            'Room fans on at the thermostat',
            'Kiln fan on',
        ],
    ];

    $steps[] = [
        'keys'    => ['start'],
        'display' => 'On',
        'title'   => 'Press START/STOP',
        'detail'  => 'The display flashes ON with the current temperature. If it does not, press START again.'
            . ' Target temperature will show — cone 06 is 998C, cone 6 is 1223C.',
        'checks'  => [
            'Display is flashing ON',
            'Elements clicking on and off — that sound means the kiln is engaged',
        ],
    ];

    $steps[] = [
        'keys'    => [],
        'display' => 'On',
        'title'   => 'Watch it come up',
        'detail'  => 'Stay for the first hour. A kiln that is switched on but not heating is the failure you want to catch now, not in the morning.',
        'checks'  => [
            'Temperature climbing on the display',
            'Still hearing the elements cycle',
            'First reading written into the log',
        ],
    ];

    $steps[] = [
        'keys'    => [],
        'display' => 'CPLt',
        'title'   => 'When it reads CPLT',
        'detail'  => 'Be back for the last two hours. CPLT flashes with the final temperature and the length of the firing.',
        'checks'  => [
            'End temperature and total time written down',
            'Temperature now falling',
            'Kiln power switch off',
            'Kiln fan off, then room fans off',
        ],
    ];

    return $steps;
}

/* ------------------------------------------------------------------------ */
/* Checklists                                                                */
/* ------------------------------------------------------------------------ */

/**
 * Checklist for the computer-controlled kilns (2, 3, 4), drawn from
 * sections 4, 5, 10 and 12 of the manual plus the apprentice record of firing.
 */
function checklist_computer(): array
{
    return [
        [
            'phase' => 'Before you start',
            'items' => [
                ['id' => 'c_floor',   'text' => 'Basement floor is dry — the kilns cannot be fired if it is wet.', 'note' => 'Never touch a kiln or controller while standing in water.'],
                ['id' => 'c_pail',    'text' => 'If it has rained, check the red pail under the pipes right of the sink; empty it and email the convener if there is water in it.'],
                ['id' => 'c_booking', 'text' => 'Kiln is booked and the booking is confirmed.'],
            ],
        ],
        [
            'phase' => 'Loading',
            'items' => [
                ['id' => 'l_debris',   'text' => 'Kiln bottom checked for dust, sand or debris from the last user; vacuumed if needed.'],
                ['id' => 'l_size',     'text' => 'Pots arranged according to size.'],
                ['id' => 'l_cable',    'text' => 'Kiln cable elevated off the floor.'],
                ['id' => 'l_shelves',  'text' => 'Shelves examined and dusted top and bottom, adhered kiln wash removed from the undersides, checked for cracks.'],
                ['id' => 'l_posts',    'text' => 'Both ends of every post scraped on the cement block before inserting.', 'note' => 'Kiln wash left on posts sticks them to the shelves and can drop into your pots.'],
                ['id' => 'l_flip',     'type' => 'bisque', 'text' => 'Shelves flipped to the bare side to help straighten sagging (especially kiln 2).'],
                ['id' => 'l_handles',  'type' => 'bisque', 'text' => 'Mugs loaded with handles facing inward.'],
                ['id' => 'l_bottoms',  'type' => 'glaze', 'text' => 'Bottoms of pots cleaned.'],
                ['id' => 'l_cookies',  'type' => 'glaze', 'text' => 'Kiln cookies under the ware — optional but recommended.'],
                ['id' => 'l_ware',     'text' => 'Ware at least 1 cm from the walls; thermocouples not bumped.'],
                ['id' => 'l_pfm',      'text' => 'Pieces from the "Please Fire Me" shelf added if they fit.'],
                ['id' => 'l_cones',    'text' => 'Witness cone packs loaded at the peep holes.'],
                ['id' => 'l_record',   'text' => 'Shelf numbers recorded in the firing log.'],
            ],
        ],
        [
            'phase' => 'Safety and fans',
            'items' => [
                ['id' => 'f_door',    'text' => 'Basement fire exit door checked that it opens — then kept shut during the firing.'],
                ['id' => 'f_flam',    'text' => 'Flammables cleared away from around the kiln.'],
                ['id' => 'f_lid',     'text' => 'Lid closed with the safety handle and peep holes closed.', 'note' => 'The kiln will not stay on if the lid is not latched.'],
                ['id' => 'f_aligned', 'text' => 'Lid aligned with the bottom.', 'note' => 'Kilns 2 and 3 in particular.'],
                ['id' => 'f_panel',   'text' => 'Numbered switch for this kiln is on in the panel box.'],
                ['id' => 'f_roomfan', 'text' => 'Room intake and output fans turned on at the thermostat between kilns 2 and 3.', 'note' => 'Both individual fan switches must be left on for the thermostat to work.'],
                ['id' => 'f_kilnfan', 'text' => 'Kiln fan turned on.'],
            ],
        ],
        [
            'phase' => 'Programming and starting',
            'items' => [
                ['id' => 'f_power',   'text' => 'Power switch on the side of the control box turned on (kiln 2: on the wall).'],
                ['id' => 'f_prog',    'text' => 'Controller programmed: speed, cone, hold, preheat.'],
                ['id' => 'f_review',  'text' => 'Program reviewed before starting.'],
                ['id' => 'f_start',   'text' => 'START pressed — display flashing ON with the current temperature.'],
                ['id' => 'f_click',   'text' => 'Elements heard clicking on and off — the kiln is actually engaged.'],
                ['id' => 'f_climb',   'text' => 'Temperature seen climbing in the first few minutes.'],
                ['id' => 'f_first',   'text' => 'Stayed for the first hour of the firing.'],
                ['id' => 'f_firstlog', 'text' => 'First temperature written into the log below.'],
            ],
        ],
        [
            'visit' => 2,
            'phase' => 'Watching the end',
            'items' => [
                ['id' => 'f_last',    'text' => 'Present for the last two hours of the firing.'],
                ['id' => 'f_temps',   'text' => 'Temperature checked and recorded every hour you are present.'],
                ['id' => 'f_peep',    'text' => 'Near target, cone packs checked through the peeps with dark safety glasses.', 'note' => 'Open the peeps with a metal tool and blow into the hole to see the pack.'],
            ],
        ],
        [
            'visit' => 2,
            'phase' => 'Turning off',
            'items' => [
                ['id' => 't_cplt',    'text' => 'Display reads CPLT; end temperature and total hours recorded.', 'note' => 'Record it if you had to stop the kiln manually instead of letting the controller finish.'],
                ['id' => 't_falling', 'text' => 'Temperature seen falling — the elements really have shut off.'],
                ['id' => 't_power',   'text' => 'Kiln power switch turned off.'],
                ['id' => 't_kilnfan', 'text' => 'Kiln fan turned off to prevent rapid cooling.', 'note' => 'Fans can cool up to a third faster than natural cooling — that means pinging, crazing, even cracks.'],
                ['id' => 't_roomfan', 'text' => 'Room fans turned off (leave on only on a very hot summer day).'],
            ],
        ],
        [
            'visit' => 3,
            'phase' => 'Unloading',
            'items' => [
                ['id' => 'u_crack',   'text' => 'Kiln turned on to read the temperature; lid cracked and peeps opened at about 100C.'],
                ['id' => 'u_open',    'text' => 'Lid not opened wide until about 52C or room temperature. Gloves on.'],
                ['id' => 'u_tc',      'text' => 'Thermocouples not bumped while unloading.'],
                ['id' => 'u_time',    'text' => 'Unloaded and cleaned up before 6 pm so the next load can begin.'],
                ['id' => 'u_conelog', 'text' => 'Witness cone results drawn into the firing log.'],
                ['id' => 'u_clean',   'text' => 'Glaze drips and crackled kiln wash cleaned off the shelves; kiln wash reapplied if needed.'],
                ['id' => 'u_shelves', 'text' => 'Shelves used and maintenance performed recorded.'],
                ['id' => 'u_vac',     'text' => 'Bottom shelves out, elements and bricks vacuumed top to bottom, kiln floor vacuumed, stilts and shelves replaced, lid and peeps closed.'],
                ['id' => 'u_report',  'text' => 'Any firing problems or bumped thermocouples reported to kiln maintenance, cc the convener.'],
                ['id' => 'u_pots',    'text' => 'All pots out of the kiln room; "Please Fire Me" pots onto the blue shelves with completed paperwork.'],
                ['id' => 'u_newcones', 'text' => 'New cone packs made to replace the ones you used.'],
            ],
        ],
    ];
}

/** Checklist for the manual R-85 with the LT-3K kiln sitter (kiln 1). */
function checklist_manual(): array
{
    return [
        [
            'phase' => 'Before you start',
            'items' => [
                ['id' => 'c_floor',   'text' => 'Basement floor is dry. Never touch a kiln or controller while standing in water.'],
                ['id' => 'c_pail',    'text' => 'If it has rained, check the red pail under the pipes right of the sink.'],
                ['id' => 'c_booking', 'text' => 'Kiln is booked and the booking is confirmed.'],
            ],
        ],
        [
            'phase' => 'Loading',
            'items' => [
                ['id' => 'l_debris',  'text' => 'Kiln bottom checked for debris or silica sand; vacuumed if needed.'],
                ['id' => 'l_sitter',  'text' => 'Correct kiln sitter cone (top shelf) fitted before loading.', 'note' => 'Lift the grey latch button outside and hook the prong over it to open the claw. Cone goes number side down, centred across the two supports, with the single auto-shutoff prong above.'],
                ['id' => 'l_shelves', 'text' => 'Shelves dusted top and bottom, adhered kiln wash removed, checked for cracks.'],
                ['id' => 'l_posts',   'text' => 'Both ends of every post scraped clean before inserting.'],
                ['id' => 'l_cookies', 'type' => 'glaze', 'text' => 'Kiln cookies under the ware.'],
                ['id' => 'l_ware',    'text' => 'Ware at least 1 cm from the walls; thermocouples not bumped.'],
                ['id' => 'l_pfm',     'text' => 'Pieces from the "Please Fire Me" shelf added if they fit.'],
                ['id' => 'l_cones',   'text' => 'Witness cone packs loaded at the peep holes.'],
            ],
        ],
        [
            'phase' => 'Firing',
            'items' => [
                ['id' => 'f_panel',   'text' => 'Numbered switch for this kiln is on in the panel box.'],
                ['id' => 'f_fans',    'text' => 'Room fan and kiln fan turned on.'],
                ['id' => 'f_flam',    'text' => 'Flammables cleared; fire escape door checked; peeps closed and lid latched.'],
                ['id' => 'f_timer',   'text' => 'Sitter timer set 30 minutes to 1 hour beyond the time you expect the firing to take.'],
                ['id' => 'f_on',      'text' => 'Time sitter switch pushed in — the red light comes on.'],
                ['id' => 'f_lo',      'text' => 'Elements switched counter-clockwise to LO. You should feel the click and hear the kiln rev.', 'note' => 'The OFF position is a little left of 12 o\'clock — there is a pink dot of nail polish marking it.'],
                ['id' => 'f_preheat', 'text' => 'If preheating greenware on LO, peepholes left open so water vapour can escape.'],
                ['id' => 'f_step',    'text' => 'Elements turned up by 2 each hour: LO, 2, 4, 6, HI.'],
                ['id' => 'f_stay',    'text' => 'Stayed at the workshop until the kiln is on HI.', 'note' => 'New January 2023. You may leave after that, but must return for the last two hours.'],
            ],
        ],
        [
            'visit' => 2,
            'phase' => 'Watching the end',
            'items' => [
                ['id' => 'f_last',    'text' => 'Returned for the last two hours of the firing.'],
                ['id' => 'f_temps',   'text' => 'Pyrometer temperature checked and recorded each hour.'],
            ],
        ],
        [
            'visit' => 2,
            'phase' => 'Turning off',
            'items' => [
                ['id' => 't_sitter',  'text' => 'Left on HI until the kiln sitter switched the kiln off.'],
                ['id' => 't_off',     'text' => 'Element knob turned back to the OFF position.'],
                ['id' => 't_fans',    'text' => 'Room fan and kiln fan turned off.'],
            ],
        ],
        [
            'visit' => 3,
            'phase' => 'Unloading',
            'items' => [
                ['id' => 'u_crack',   'text' => 'Kiln turned on to read the temperature; lid cracked and peeps opened at about 100C.'],
                ['id' => 'u_open',    'text' => 'Lid not opened wide until about 52C or room temperature. Gloves on.'],
                ['id' => 'u_time',    'text' => 'Unloaded and cleaned up before 6 pm.'],
                ['id' => 'u_conelog', 'text' => 'Witness cone results drawn into the firing log.'],
                ['id' => 'u_clean',   'text' => 'Glaze drips and loose kiln wash cleaned off; kiln wash reapplied if needed.'],
                ['id' => 'u_vac',     'text' => 'Bottom shelves out, elements, bricks and floor vacuumed, shelves replaced, lid and peeps closed.'],
                ['id' => 'u_report',  'text' => 'Any problems reported to kiln maintenance, cc the convener.'],
                ['id' => 'u_pots',    'text' => 'All pots out of the kiln room.'],
                ['id' => 'u_newcones', 'text' => 'New cone packs made to replace the ones you used.'],
            ],
        ],
    ];
}

/**
 * The checklist for a kiln, narrowed to one kind of firing.
 *
 * A few items only make sense for one of them — a glaze load has no shelves to
 * flip, a bisque load has no bottoms to clean — so those carry a 'type'. Pass
 * an empty type to keep the whole list.
 */
function checklist_for(string $kilnId, string $type = ''): array
{
    $groups = kiln_get($kilnId)['kind'] === 'manual' ? checklist_manual() : checklist_computer();
    if ($type === '') {
        return $groups;
    }

    $out = [];
    foreach ($groups as $group) {
        $group['items'] = array_values(array_filter(
            $group['items'],
            static function (array $item) use ($type) {
                return ($item['type'] ?? '') === '' || $item['type'] === $type;
            }
        ));
        if ($group['items']) {
            $out[] = $group;
        }
    }
    return $out;
}

/**
 * A firing is three trips to the workshop, and the app is organised around
 * them. Checklist groups without a 'visit' belong to the first one.
 */
function firing_visits(): array
{
    return [
        1 => [
            'title' => 'Load and start',
            'short' => 'Load',
            'blurb' => 'Loading, programming the controller, pressing START, and the first hour.',
        ],
        2 => [
            'title' => 'Be there for the end',
            'short' => 'End',
            'blurb' => 'Back for the last two hours, watching the cones, and shutting down at CPLT.',
        ],
        3 => [
            'title' => 'Unload',
            'short' => 'Unload',
            'blurb' => 'Next day: open up, empty it, clean the shelves, vacuum, write it up.',
        ],
    ];
}

function checklist_visit_of(array $group): int
{
    $visit = (int)($group['visit'] ?? 1);
    return ($visit >= 1 && $visit <= 3) ? $visit : 1;
}

/** Just the groups belonging to one visit. */
function checklist_for_visit(string $kilnId, int $visit, string $type = ''): array
{
    $groups = [];
    foreach (checklist_for($kilnId, $type) as $group) {
        if (checklist_visit_of($group) === $visit) {
            $groups[] = $group;
        }
    }
    return $groups;
}

function checklist_count(array $checklist): int
{
    $n = 0;
    foreach ($checklist as $group) {
        $n += count($group['items']);
    }
    return $n;
}

/** Element settings for the manual kiln, one step per hour. */
function manual_kiln_steps(): array
{
    return ['LO', '2', '4', '6', 'HI'];
}
