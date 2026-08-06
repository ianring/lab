<?php
declare(strict_types=1);

/**
 * Kiln Timer — configuration.
 *
 * The values below come from data/settings.json and are editable from the
 * Settings page. They are turned into constants here so the rest of the app
 * can just use them. Defaults live in lib/settings.php.
 *
 * The three constants at the top are fixed: they say where things are, so they
 * cannot themselves be stored in a settings file.
 */

const APP_NAME      = 'Kiln Timer';

// Database credentials: constants in a gitignored file on the server.
const DB_CONFIG_FILE = __DIR__ . '/../config/db.php';

// The old flat files. Nothing writes to these any more; install.php reads them
// once to import whatever was logged before the move to MySQL.
const DATA_FILE     = __DIR__ . '/../data/firings.json';
const SETTINGS_FILE = __DIR__ . '/../data/settings.json';

/**
 * The kiln manifest: hand-edited on the server, shown read-only at kilns.php.
 *
 * The live file is deliberately NOT in the repository — only the .example copy
 * is, and the app falls back to it when the live one is absent. That way
 * re-uploading the app can never clobber edits you made on the server, which is
 * exactly what happens if the real file ships with the code.
 */
const KILNS_FILE            = __DIR__ . '/../config/kilns.json';
const KILNS_EXAMPLE_FILE    = __DIR__ . '/../config/kilns.example.json';

// Google sign-in. auth.json holds a client secret, so it never enters the
// repository; users.json is the profile store the app writes.
const AUTH_FILE             = __DIR__ . '/../config/auth.json';
const SMS_CONFIG_FILE       = __DIR__ . '/../config/sms.php';

/**
 * Text reminders: off, and hidden with it.
 *
 * The code is all still here — lib/sms.php, cron.php, the phone and opt-in
 * columns, the reminders table. This one switch decides whether any of it is
 * offered in the UI. Set it to true when you come back to Twilio; nothing else
 * needs changing.
 */
const FEATURE_SMS           = false;
const AUTH_EXAMPLE_FILE     = __DIR__ . '/../config/auth.example.json';
const USERS_FILE            = __DIR__ . '/../data/users.json';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

$settings = settings_load();

// General
define('APP_TZ', (string)$settings['timezone']);
define('DEFAULT_KILN', (string)$settings['default_kiln']);
define('DEFAULT_PROGRAM', (string)$settings['default_program']);
define('UNLOAD_HOUR', (int)$settings['unload_hour']);

// Preheat: the ramp to 93C before the programmed hold starts, and how coarsely
// to round the hold so it is quick to type into the controller.
define('PREHEAT_OVERHEAD_HOURS', (float)$settings['preheat_overhead_hours']);
define('PREHEAT_ROUND_MINUTES', (int)$settings['preheat_round_minutes']);

// Presence rules and cooling window.
define('PRESENT_START_HOURS', (float)$settings['present_start_hours']);
define('PRESENT_END_HOURS', (float)$settings['present_end_hours']);
define('COOLING_HOURS_MIN', (int)$settings['cooling_hours_min']);
define('COOLING_HOURS_MAX', (int)$settings['cooling_hours_max']);

// How long it takes to empty the kiln and clean up afterwards. The timeline
// works back from the unload deadline by this much.
define('UNLOADING_MINUTES', (int)$settings['unloading_minutes']);

// Never fire the glaze kilns above this cone at WPW.
define('MAX_GLAZE_CONE', (int)$settings['max_glaze_cone']);

unset($settings);

date_default_timezone_set(APP_TZ);
