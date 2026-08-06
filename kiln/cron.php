<?php
declare(strict_types=1);

/**
 * Reminder sender. Run me from cron.
 *
 *     */15 * * * * /usr/bin/php /path/to/kiln/cron.php >> /path/to/kiln/data/cron.log 2>&1
 *
 * Every quarter of an hour it looks for a firing whose next visit falls inside
 * the next 24 hours, and texts whoever fired it — once. "Once" is enforced by a
 * unique key on (firing_id, visit, channel) in the reminders table, not by the
 * schedule, so running this more often, or twice at the same moment, cannot
 * produce two messages.
 *
 * Nothing is sent to anyone who has not ticked the box on their profile.
 *
 * Add --dry-run to see what it would do and send nothing.
 * Add --verbose to print every firing it considered.
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/timing.php';
require __DIR__ . '/lib/sms.php';
require __DIR__ . '/lib/auth.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    // Not for browsers. If a host can only schedule things over HTTP, gate it
    // on a secret in config/sms.php rather than leaving it open.
    $secret = defined('CRON_SECRET') ? (string)CRON_SECRET : '';
    if ($secret === '' || (string)q('key', '') !== $secret) {
        http_response_code(403);
        exit("Run this from the command line.\n");
    }
    header('Content-Type: text/plain');
}

$argvList = $isCli ? $_SERVER['argv'] : [];
$dryRun   = in_array('--dry-run', $argvList, true);
$verbose  = in_array('--verbose', $argvList, true) || $dryRun;

$log = static function (string $line) {
    echo now()->format('Y-m-d H:i') . '  ' . $line . "\n";
};

if (!db_ready()) {
    $log('SKIP  ' . (store_diagnose() ?: 'the database is not ready'));
    exit(1);
}

if (!sms_available()) {
    $log('SKIP  text reminders are switched off — set FEATURE_SMS to true in lib/config.php');
    exit(0);
}

const REMINDER_LEAD_HOURS = 24;

$visits    = firing_visits();
$horizon   = now()->modify('+' . REMINDER_LEAD_HOURS . ' hours');
$sent      = 0;
$skipped   = 0;

/*
 * Only firings that could still have a visit ahead of them. The unload deadline
 * is the last thing that happens, so anything whose deadline has passed is done
 * with — and a booking more than a few days out cannot have a visit within 24
 * hours, so there is no point rebuilding its schedule.
 */
$rows = db_rows(
    'SELECT * FROM firings
      WHERE COALESCE(planned_unload_by, planned_finish, planned_start) >= ?
        AND COALESCE(planned_start, created_at) <= ?
      ORDER BY id',
    [now()->modify('-1 day')->format('Y-m-d H:i:s'), $horizon->modify('+2 days')->format('Y-m-d H:i:s')]
);

foreach ($rows as $row) {
    $firing = firing_from_row($row, false);
    $plan   = firing_plan($firing);
    $times  = firing_visit_times($plan);

    $userId = (int)$firing['fired_by'];
    $user   = $userId > 0 ? user_get($userId) : null;

    foreach ($times as $visit => $when) {
        if ($when <= now() || $when > $horizon) {
            continue;   // already passed, or further off than the lead time
        }

        $kilnName = kiln_get((string)$firing['kiln'])['name'];

        if (!$user) {
            if ($verbose) {
                $log('skip  firing ' . $firing['id'] . ' visit ' . $visit . ' — nobody with an account fired it');
            }
            $skipped++;
            continue;
        }
        if (empty($user['sms_opt_in'])) {
            if ($verbose) {
                $log('skip  firing ' . $firing['id'] . ' visit ' . $visit . ' — ' . $user['name'] . ' has not opted in');
            }
            $skipped++;
            continue;
        }
        if (sms_normalise_number((string)$user['phone']) === '') {
            if ($verbose) {
                $log('skip  firing ' . $firing['id'] . ' visit ' . $visit . ' — ' . $user['name'] . ' has no usable number');
            }
            $skipped++;
            continue;
        }

        // Claim it first. The unique key means a second run — or a second copy
        // of this script — loses the race and sends nothing.
        if (!$dryRun) {
            $claim = db_query(
                'INSERT IGNORE INTO reminders (firing_id, visit, user_id, channel, status, sent_at)
                      VALUES (?, ?, ?, ?, ?, ?)',
                [$firing['id'], $visit, $userId, 'sms', 'sending', db_datetime(now())]
            );
            if (!$claim || $claim->rowCount() === 0) {
                continue;   // already sent, quietly
            }
        } else {
            $already = db_value('SELECT id FROM reminders WHERE firing_id = ? AND visit = ? AND channel = ?',
                                [$firing['id'], $visit, 'sms']);
            if ($already !== null) {
                continue;
            }
        }

        $body = reminder_text($visits[$visit], $visit, $kilnName, $firing, $when);

        if ($dryRun) {
            $log('would send to ' . $user['phone'] . ': ' . $body);
            $sent++;
            continue;
        }

        [$ok, $detail] = sms_send((string)$user['phone'], $body);
        db_query('UPDATE reminders SET status = ?, detail = ?, sent_at = ? WHERE firing_id = ? AND visit = ? AND channel = ?',
                 [$ok ? 'sent' : 'failed', substr($detail, 0, 255), db_datetime(now()), $firing['id'], $visit, 'sms']);

        if ($ok) {
            $log('sent  firing ' . $firing['id'] . ' visit ' . $visit . ' to ' . $user['name'] . ' (' . $detail . ')');
            $sent++;
        } else {
            $log('FAIL  firing ' . $firing['id'] . ' visit ' . $visit . ' to ' . $user['name'] . ' — ' . $detail);
            // Left as 'failed' rather than deleted, so a broken number does not
            // get retried every fifteen minutes forever. Clear the row by hand
            // to try again.
        }
    }
}

if ($sent > 0 || $verbose) {
    $log('done  ' . $sent . ' sent, ' . $skipped . ' skipped, ' . count($rows) . ' firings considered'
        . ($dryRun ? ' (dry run)' : ''));
}

/** What the message actually says. Short — it is a text message. */
function reminder_text(array $meta, int $visit, string $kilnName, array $firing, DateTimeImmutable $when): string
{
    $what = ucfirst((string)$firing['type']) . ' cone ' . $firing['cone'];
    $time = ltrim($when->format('D g:i a'), '0');

    switch ($visit) {
        case 1:
            $detail = 'Load and start it.';
            break;
        case 2:
            $detail = 'Be there for the last two hours.';
            break;
        default:
            $detail = 'Unload and clean up.';
            break;
    }

    return $kilnName . ' — ' . $what . "\n" . $detail . ' ' . $time . ' (24 hours away).';
}
