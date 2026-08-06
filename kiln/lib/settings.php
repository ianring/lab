<?php
declare(strict_types=1);

/**
 * User-editable settings, stored in data/settings.json and turned into the
 * app's constants by lib/config.php. Anything in here can be changed from the
 * Settings page without editing code.
 *
 * Adding a setting: add a row to settings_spec(), then define() a constant for
 * it in lib/config.php. Keep this file free of calls into data.php at load
 * time — config.php loads it first, before anything else exists.
 */

function settings_spec(): array
{
    return [
        'timezone' => [
            'group' => 'General',
            'label' => 'Time zone',
            'type'  => 'timezone',
            'default' => 'America/Toronto',
            'hint'  => 'Every time in the app is shown in this zone.',
        ],
        'default_kiln' => [
            'group' => 'General',
            'label' => 'Kiln selected by default',
            'type'  => 'kiln',
            'default' => '3',
            'hint'  => 'The one the planner opens on.',
        ],
        'default_program' => [
            'group' => 'General',
            'label' => 'Program selected by default',
            'type'  => 'program',
            'default' => 'slow_glaze',
            'hint'  => '',
        ],
        'unload_hour' => [
            'group' => 'General',
            'label' => 'Unload deadline hour',
            'type'  => 'number',
            'default' => 18,
            'min' => 0, 'max' => 23, 'step' => 1,
            'hint'  => '24-hour clock. 18 is 6 pm, when the next load can begin.',
        ],

        'preheat_overhead_hours' => [
            'group' => 'Preheat',
            'label' => 'Preheat ramp allowance (hours)',
            'type'  => 'number',
            'default' => 1.0,
            'min' => 0, 'max' => 6, 'step' => 0.25,
            'hint'  => 'How long the climb to 93C takes before the preheat stage you programmed starts counting. The manual says budget about 1 hour; the arithmetic from a 21C room at 60F/hr is nearer 2h10m. If firings finish late, raise this.',
        ],
        'preheat_round_minutes' => [
            'group' => 'Preheat',
            'label' => 'Round the preheat to (minutes)',
            'type'  => 'number',
            'default' => 5,
            'min' => 1, 'max' => 30, 'step' => 1,
            'hint'  => 'The answer is rounded down to a multiple of this, so it is quick to type.',
        ],

        'present_start_hours' => [
            'group' => 'Presence and cooling',
            'label' => 'Stay for the first (hours)',
            'type'  => 'number',
            'default' => 1.0,
            'min' => 0, 'max' => 12, 'step' => 0.5,
            'hint'  => 'WPW rule: present for the first hour of the firing.',
        ],
        'present_end_hours' => [
            'group' => 'Presence and cooling',
            'label' => 'Be back for the last (hours)',
            'type'  => 'number',
            'default' => 2.0,
            'min' => 0, 'max' => 12, 'step' => 0.5,
            'hint'  => 'WPW rule: present for the last two hours.',
        ],
        'cooling_hours_min' => [
            'group' => 'Presence and cooling',
            'label' => 'Cooling, earliest safe unload (hours)',
            'type'  => 'number',
            'default' => 20,
            'min' => 1, 'max' => 72, 'step' => 1,
            'hint'  => 'This is what the booking deadline is checked against.',
        ],
        'cooling_hours_max' => [
            'group' => 'Presence and cooling',
            'label' => 'Cooling, cool by (hours)',
            'type'  => 'number',
            'default' => 24,
            'min' => 1, 'max' => 96, 'step' => 1,
            'hint'  => 'The pessimistic end of the cooling window. Raised automatically if you set it below the earliest.',
        ],
        'unloading_minutes' => [
            'group' => 'Presence and cooling',
            'label' => 'Unloading takes (minutes)',
            'type'  => 'number',
            'default' => 30,
            'min' => 0, 'max' => 240, 'step' => 5,
            'hint'  => 'Getting the ware out, inspecting and washing shelves, vacuuming the kiln and '
                . 'writing up the log. The timeline works back from your deadline by this much.',
        ],
        'max_glaze_cone' => [
            'group' => 'Presence and cooling',
            'label' => 'Warn above glaze cone',
            'type'  => 'number',
            'default' => 7,
            'min' => 1, 'max' => 14, 'step' => 1,
            'hint'  => 'The manual says never fire these kilns above cone 7.',
        ],

        'program_hours_slow_bisque' => [
            'group' => 'Program lengths',
            'label' => 'Slow Bisque (hours)',
            'type'  => 'number',
            'default' => 12.0,
            'min' => 0.25, 'max' => 48, 'step' => 0.25,
            'hint'  => '',
        ],
        'program_hours_fast_bisque' => [
            'group' => 'Program lengths',
            'label' => 'Fast Bisque (hours)',
            'type'  => 'number',
            'default' => 9.0,
            'min' => 0.25, 'max' => 48, 'step' => 0.25,
            'hint'  => '',
        ],
        'program_hours_slow_glaze' => [
            'group' => 'Program lengths',
            'label' => 'Slow Glaze (hours)',
            'type'  => 'number',
            'default' => 9.0,
            'min' => 0.25, 'max' => 48, 'step' => 0.25,
            'hint'  => '',
        ],
        'program_hours_fast_glaze' => [
            'group' => 'Program lengths',
            'label' => 'Fast Glaze (hours)',
            'type'  => 'number',
            'default' => 5.0,
            'min' => 0.25, 'max' => 48, 'step' => 0.25,
            'hint'  => 'Starting points only — the planner offers your own logged average once you have a couple of firings.',
        ],
    ];
}

function settings_defaults(): array
{
    $defaults = [];
    foreach (settings_spec() as $key => $field) {
        $defaults[$key] = $field['default'];
    }
    return $defaults;
}

/** Settings merged over the defaults. Cached for the request. */
function settings_load(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }

    // One row per setting. A database that is down or not yet installed simply
    // means everything runs on its defaults, which keeps the login page and
    // the installer working.
    $stored = [];
    foreach (db_rows('SELECT name, value FROM settings') as $row) {
        $stored[(string)$row['name']] = $row['value'];
    }

    $settings = settings_defaults();
    foreach ($settings as $key => $default) {
        if (array_key_exists($key, $stored)) {
            $settings[$key] = settings_clean($key, $stored[$key]);
        }
    }

    $cache = settings_reconcile($settings);
    return $cache;
}

function setting(string $key, $fallback = null)
{
    $settings = settings_load();
    return $settings[$key] ?? $fallback;
}

/** Coerce one value into something safe for its type. */
function settings_clean(string $key, $value)
{
    $spec = settings_spec()[$key] ?? null;
    if (!$spec) {
        return $value;
    }

    switch ($spec['type']) {
        case 'number':
            $number = is_numeric($value) ? (float)$value : (float)$spec['default'];
            $number = max((float)$spec['min'], min((float)$spec['max'], $number));
            // Whole-number settings stay whole numbers.
            return ((float)$spec['step'] == floor((float)$spec['step'])) ? (int)round($number) : $number;

        case 'timezone':
            $value = is_scalar($value) ? (string)$value : '';
            return in_array($value, timezone_identifiers_list(), true) ? $value : (string)$spec['default'];

        case 'kiln':
            $value = is_scalar($value) ? (string)$value : '';
            if (function_exists('kiln_list')) {
                $ids = array_map('strval', array_keys(kiln_list()));
                return in_array($value, $ids, true) ? $value : (string)$spec['default'];
            }
            return $value !== '' ? $value : (string)$spec['default'];

        case 'program':
            $value = is_scalar($value) ? (string)$value : '';
            if (function_exists('program_list')) {
                return array_key_exists($value, program_list()) ? $value : (string)$spec['default'];
            }
            return $value !== '' ? $value : (string)$spec['default'];

    }

    return $value;
}

/** Settings that constrain each other. */
function settings_reconcile(array $settings): array
{
    if ($settings['cooling_hours_max'] < $settings['cooling_hours_min']) {
        $settings['cooling_hours_max'] = $settings['cooling_hours_min'];
    }
    return $settings;
}

/**
 * Save posted values. Returns [ok, settings, messages].
 */
function settings_save(array $posted): array
{
    $settings = settings_load();
    $messages = [];

    foreach (settings_spec() as $key => $field) {
        if (!array_key_exists($key, $posted) || !is_scalar($posted[$key])) {
            continue;
        }
        $clean = settings_clean($key, $posted[$key]);
        if ($field['type'] === 'number' && is_numeric($posted[$key]) && (float)$clean !== (float)$posted[$key]) {
            $messages[] = $field['label'] . ' was clamped to ' . $clean
                . ' (allowed range ' . $field['min'] . ' to ' . $field['max'] . ').';
        }
        if ($field['type'] !== 'number' && (string)$clean !== (string)$posted[$key]) {
            $messages[] = $field['label'] . ' was not recognised, so it was left at ' . $clean . '.';
        }
        $settings[$key] = $clean;
    }

    $before = $settings;
    $settings = settings_reconcile($settings);
    if ($settings['cooling_hours_max'] !== $before['cooling_hours_max']) {
        $messages[] = 'Cooling "cool by" was raised to ' . $settings['cooling_hours_max']
            . ' hours so that it is not earlier than the earliest safe unload.';
    }

    $ok = settings_write($settings);
    settings_load(true);
    return [$ok, $settings, $messages];
}

function settings_reset(): bool
{
    $ok = settings_write(settings_defaults());
    settings_load(true);
    return $ok;
}

function settings_write(array $settings): bool
{
    if (!db()) {
        return false;
    }
    $ok = true;
    foreach ($settings as $name => $value) {
        $statement = db_query(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [(string)$name, (string)$value]
        );
        $ok = $ok && $statement !== null;
    }
    return $ok;
}

/** Can settings be saved at all? */
function settings_writable(): bool
{
    return db() !== null && db_ready();
}

/** Which settings differ from the shipped defaults. */
function settings_changed(): array
{
    $changed = [];
    $current = settings_load();
    foreach (settings_defaults() as $key => $default) {
        if ((string)$current[$key] !== (string)$default) {
            $changed[$key] = $current[$key];
        }
    }
    return $changed;
}
