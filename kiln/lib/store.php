<?php
declare(strict_types=1);

/**
 * Firing records, in MySQL.
 *
 * Four tables:
 *   firings         one row per firing
 *   firing_temps    the hourly temperature readings
 *   firing_checks   which checklist items are ticked, and when
 *   firing_cones    the witness cone pack, one row per cone
 *
 * The rest of the app never sees any of that. It passes around one nested
 * array per firing — the same shape it always has — and this file assembles
 * and takes apart that shape. Every function here kept its old signature, so
 * the move from JSON changed nothing above this line.
 */

/** Why the last write failed, if it did. */
function store_last_error(?string $set = null): string
{
    static $error = '';
    if ($set !== null) {
        $error = $set;
    }
    return $error;
}

/** Columns the firings table actually has, whatever the code expects. */
function firings_columns_present(): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }
    $columns = [];
    foreach (db_rows('SHOW COLUMNS FROM firings') as $row) {
        $columns[(string)$row['Field']] = true;
    }
    return $columns;
}

/** Columns this version of the code writes but the table has not got. */
function store_missing_columns(): array
{
    if (!db_ready()) {
        return [];
    }
    $present = firings_columns_present();
    $wanted  = array_keys(firing_columns(firing_blank()));
    return array_values(array_filter($wanted, static function ($name) use ($present) {
        return !isset($present[$name]);
    }));
}

/** A plain-language check of whether firings can be read and written. */
function store_diagnose(): string
{
    if (!db()) {
        return db_error() ?: 'The database is not reachable.';
    }
    if (!db_ready()) {
        return 'The database is connected but has no tables yet. Open install.php to create them.';
    }
    $missing = store_missing_columns();
    if ($missing) {
        return 'The firings table is behind the code — it has no '
            . implode(', ', $missing) . ' column' . (count($missing) === 1 ? '' : 's')
            . '. Open install.php and rebuild, or those fields will not be saved.';
    }
    return '';
}

/* ------------------------------------------------------------------ */
/* Shapes                                                              */
/* ------------------------------------------------------------------ */

function firing_blank(): array
{
    return [
        'id'            => 0,
        'created_at'    => '',
        'updated_at'    => '',
        'kiln'          => DEFAULT_KILN,
        // Who fired it: either a users.id, or a bare name for somebody who
        // will never sign in — imported logs are full of those.
        'fired_by'      => 0,
        'fired_by_name' => '',
        // The named day of the booking. Access runs 6 pm the evening before to
        // 6 pm the day after, so one booking covers three calendar days.
        'booking_day'   => '',
        'type'          => 'glaze',
        'program'       => DEFAULT_PROGRAM,
        'cone'          => '6',
        'hold_minutes'  => 0,
        // What the controller itself said the firing took, read off CPLT. Zero
        // when it has not been recorded, in which case the times are used.
        'controller_minutes' => 0,
        'planned'       => [
            'start_at'         => '',
            'finish_at'        => '',
            'unload_by'        => '',
            'program_minutes'  => 540,
            'preheat_minutes'  => 0,
            'overhead_minutes' => (int)round(PREHEAT_OVERHEAD_HOURS * 60),
        ],
        'actual'        => [
            'start_at'    => '',
            'end_at'      => '',
            'manual_stop' => false,
        ],
        'cone_pack'     => [],
        'cone_packs'    => ['top' => '', 'middle' => '', 'bottom' => ''],
        'shelves'       => '',
        'maintenance'   => '',
        'notes'         => '',
        'checklist'     => [],
        'temps'         => [],
    ];
}

/** Fill in anything a record is missing, so callers never have to guard. */
function firing_normalize(array $firing): array
{
    $blank = firing_blank();
    $out   = array_merge($blank, $firing);

    foreach (['planned', 'actual', 'cone_packs'] as $key) {
        $out[$key] = array_merge($blank[$key], is_array($firing[$key] ?? null) ? $firing[$key] : []);
    }

    $out['fired_by']      = (int)($firing['fired_by'] ?? 0);
    $out['fired_by_name'] = trim((string)($firing['fired_by_name'] ?? ''));
    $out['checklist']     = is_array($firing['checklist'] ?? null) ? $firing['checklist'] : [];
    $out['temps']         = is_array($firing['temps'] ?? null) ? $firing['temps'] : [];
    $out['cone_pack']     = is_array($firing['cone_pack'] ?? null) ? $firing['cone_pack'] : [];

    return $out;
}

/**
 * One database row, as the nested array the app expects.
 *
 * Every column is read with a default. A database that is a schema version
 * behind then renders fine instead of filling the log with warnings — and
 * install.php can be run to catch it up.
 */
function firing_from_row(array $row, bool $withChildren = true): array
{
    $firing = [
        'id'            => (int)($row['id'] ?? 0),
        'created_at'    => db_iso($row['created_at'] ?? null),
        'updated_at'    => db_iso($row['updated_at'] ?? null),
        'kiln'          => (string)($row['kiln'] ?? ''),
        'fired_by'      => (int)($row['fired_by'] ?? 0),
        'fired_by_name' => (string)($row['fired_by_name'] ?? ''),
        'booking_day'   => (string)($row['booking_day'] ?? ''),
        'type'          => (string)($row['type'] ?? 'glaze'),
        'program'       => (string)($row['program'] ?? ''),
        'cone'          => (string)($row['cone'] ?? ''),
        'hold_minutes'  => (int)($row['hold_minutes'] ?? 0),
        'controller_minutes' => (int)($row['controller_minutes'] ?? 0),
        'planned'       => [
            'start_at'         => db_iso($row['planned_start'] ?? null),
            'finish_at'        => db_iso($row['planned_finish'] ?? null),
            'unload_by'        => db_iso($row['planned_unload_by'] ?? null),
            'program_minutes'  => (int)($row['planned_program_minutes'] ?? 0),
            'preheat_minutes'  => (int)($row['planned_preheat_minutes'] ?? 0),
            'overhead_minutes' => (int)($row['planned_overhead_minutes'] ?? 0),
        ],
        'actual'        => [
            'start_at'    => db_iso($row['actual_start'] ?? null),
            'end_at'      => db_iso($row['actual_end'] ?? null),
            'manual_stop' => (bool)($row['manual_stop'] ?? false),
        ],
        'cone_packs'    => [
            'top'    => (string)($row['legacy_cone_top'] ?? ''),
            'middle' => (string)($row['legacy_cone_middle'] ?? ''),
            'bottom' => (string)($row['legacy_cone_bottom'] ?? ''),
        ],
        'shelves'       => (string)($row['shelves'] ?? ''),
        'maintenance'   => (string)($row['maintenance'] ?? ''),
        'notes'         => (string)($row['notes'] ?? ''),
        'checklist'     => [],
        'temps'         => [],
        'cone_pack'     => [],
    ];

    if ($withChildren) {
        $id = $firing['id'];

        foreach (db_rows('SELECT item, checked_at FROM firing_checks WHERE firing_id = ?', [$id]) as $check) {
            $firing['checklist'][(string)$check['item']] = db_iso($check['checked_at']);
        }
        foreach (db_rows('SELECT recorded_at, celsius FROM firing_temps WHERE firing_id = ? ORDER BY recorded_at', [$id]) as $temp) {
            $firing['temps'][] = ['at' => db_iso($temp['recorded_at']), 'c' => (float)$temp['celsius']];
        }
        foreach (db_rows('SELECT slot, cone, role, position FROM firing_cones WHERE firing_id = ? ORDER BY slot', [$id]) as $cone) {
            $firing['cone_pack'][] = [
                'cone' => (string)$cone['cone'],
                'role' => (string)$cone['role'],
                'pos'  => (int)$cone['position'],
            ];
        }
    }

    return firing_normalize($firing);
}

/**
 * Who fired it, as a name to show.
 *
 * A firing points at a signed-in user, or carries a bare name for somebody who
 * never will be one — an imported log is mostly the latter. The user id wins
 * when both are set, so linking an old record to a real account is a matter of
 * setting fired_by and leaving the name where it is.
 */
function firing_firer_name(array $firing, string $fallback = 'Unassigned'): string
{
    $id = (int)($firing['fired_by'] ?? 0);
    if ($id > 0 && function_exists('user_name')) {
        return user_name($id, $fallback);
    }
    $name = trim((string)($firing['fired_by_name'] ?? ''));
    return $name !== '' ? $name : $fallback;
}

/**
 * The booking day, falling back to working it out from the planned start for
 * records written before the column existed.
 */
function firing_booking_day(array $firing): ?DateTimeImmutable
{
    $stored = parse_dt((string)($firing['booking_day'] ?? ''));
    if ($stored) {
        return $stored->setTime(0, 0);
    }
    $start = parse_dt($firing['planned']['start_at'] ?? '')
        ?? parse_dt($firing['actual']['start_at'] ?? '')
        ?? parse_dt($firing['created_at'] ?? '');
    return $start ? booking_day_from_start($start) : null;
}

/** True when this firing is attributed to somebody who has never signed in. */
function firing_firer_is_guest(array $firing): bool
{
    return (int)($firing['fired_by'] ?? 0) === 0
        && trim((string)($firing['fired_by_name'] ?? '')) !== '';
}

/* ------------------------------------------------------------------ */
/* Reads                                                               */
/* ------------------------------------------------------------------ */

/** Newest first. */
function firings_all(): array
{
    if (!db_ready()) {
        return [];
    }
    $firings = [];
    foreach (db_rows('SELECT * FROM firings ORDER BY created_at DESC, id DESC') as $row) {
        $firings[] = firing_from_row($row);
    }
    return $firings;
}

function firing_get(int $id): ?array
{
    if (!db_ready()) {
        return null;
    }
    $row = db_row('SELECT * FROM firings WHERE id = ?', [$id]);
    return $row ? firing_from_row($row) : null;
}

/**
 * Firings that would share a kiln with a booking on this day.
 *
 * A booking runs from 6 pm the evening before its named day to 6 pm the day
 * after, so it occupies three calendar days. Two of those windows overlap
 * whenever their named days are within one day of each other — which is why
 * bookings on one kiln have to fall on alternating days. Two days apart is
 * fine: one ends at 6 pm exactly as the next begins.
 *
 * @param int $exceptId ignore this firing, when checking one against itself
 */
function booking_conflicts(string $kilnId, DateTimeImmutable $day, int $exceptId = 0): array
{
    if (!db_ready()) {
        return [];
    }
    $rows = db_rows(
        'SELECT * FROM firings
          WHERE kiln = ?
            AND id <> ?
            AND booking_day IS NOT NULL
            AND booking_day BETWEEN ? AND ?
          ORDER BY booking_day',
        [
            $kilnId,
            $exceptId,
            $day->modify('-1 day')->format('Y-m-d'),
            $day->modify('+1 day')->format('Y-m-d'),
        ]
    );

    $conflicts = [];
    foreach ($rows as $row) {
        $conflicts[] = firing_from_row($row, false);
    }
    return $conflicts;
}

/** The next day this kiln is free, at or after the one asked for. */
function booking_next_free(string $kilnId, DateTimeImmutable $day, int $exceptId = 0): DateTimeImmutable
{
    for ($i = 0; $i < 60; $i++) {
        $candidate = $day->modify('+' . $i . ' days');
        if (!booking_conflicts($kilnId, $candidate, $exceptId)) {
            return $candidate;
        }
    }
    return $day;
}

/* ------------------------------------------------------------------ */
/* Writes                                                              */
/* ------------------------------------------------------------------ */

/** The firings-table columns a set of nested fields touches. */
function firing_columns(array $fields): array
{
    $map = [];

    foreach (['kiln', 'fired_by_name', 'type', 'program', 'cone',
              'shelves', 'maintenance', 'notes'] as $name) {
        if (array_key_exists($name, $fields)) {
            $map[$name] = (string)$fields[$name];
        }
    }
    if (array_key_exists('booking_day', $fields)) {
        $day = parse_dt($fields['booking_day']);
        $map['booking_day'] = $day ? $day->format('Y-m-d') : null;
    }
    foreach (['fired_by', 'hold_minutes', 'controller_minutes'] as $name) {
        if (array_key_exists($name, $fields)) {
            $map[$name] = (int)$fields[$name];
        }
    }

    if (isset($fields['planned']) && is_array($fields['planned'])) {
        $planned = $fields['planned'];
        $map['planned_start']            = db_datetime($planned['start_at'] ?? '');
        $map['planned_finish']           = db_datetime($planned['finish_at'] ?? '');
        $map['planned_unload_by']        = db_datetime($planned['unload_by'] ?? '');
        $map['planned_program_minutes']  = (int)($planned['program_minutes'] ?? 0);
        $map['planned_preheat_minutes']  = (int)($planned['preheat_minutes'] ?? 0);
        $map['planned_overhead_minutes'] = (int)($planned['overhead_minutes'] ?? 0);
    }

    if (isset($fields['actual']) && is_array($fields['actual'])) {
        $actual = $fields['actual'];
        $map['actual_start'] = db_datetime($actual['start_at'] ?? '');
        $map['actual_end']   = db_datetime($actual['end_at'] ?? '');
        $map['manual_stop']  = !empty($actual['manual_stop']) ? 1 : 0;
    }

    if (isset($fields['cone_packs']) && is_array($fields['cone_packs'])) {
        $map['legacy_cone_top']    = (string)($fields['cone_packs']['top'] ?? '');
        $map['legacy_cone_middle'] = (string)($fields['cone_packs']['middle'] ?? '');
        $map['legacy_cone_bottom'] = (string)($fields['cone_packs']['bottom'] ?? '');
    }

    return $map;
}

function firing_create(array $fields): int
{
    store_last_error('');
    if (!db_ready()) {
        store_last_error(store_diagnose());
        return 0;
    }

    $record = array_merge(firing_blank(), $fields);
    $columns = firing_columns($record);
    $columns['created_at'] = db_datetime(now());
    $columns['updated_at'] = db_datetime(now());

    // A table that predates a column would otherwise fail the whole insert.
    // Dropping the unknown column loses that one field; store_diagnose() says
    // so on the page, loudly, rather than letting it pass unnoticed.
    $columns = array_intersect_key($columns, firings_columns_present());

    $names  = array_keys($columns);
    $holders = implode(', ', array_fill(0, count($names), '?'));
    $sql = 'INSERT INTO firings (`' . implode('`, `', $names) . '`) VALUES (' . $holders . ')';

    if (!db_query($sql, array_values($columns))) {
        store_last_error(db_error());
        return 0;
    }
    $id = (int)db()->lastInsertId();

    // Children, if the caller handed any over at creation time.
    if (!empty($record['checklist'])) {
        firing_write_checks($id, $record['checklist']);
    }
    if (!empty($record['temps'])) {
        firing_write_temps($id, $record['temps']);
    }
    if (!empty($record['cone_pack'])) {
        firing_write_cones($id, $record['cone_pack']);
    }

    return $id;
}

function firing_update(int $id, array $fields): bool
{
    store_last_error('');
    if (!db_ready()) {
        store_last_error(store_diagnose());
        return false;
    }

    $columns = firing_columns($fields);
    // What is being fired is settled when the firing is created. Changing it
    // afterwards would invalidate the checklist, the cone and the program that
    // were chosen for it — delete the firing and plan a new one instead.
    unset($columns['type'], $columns['program'], $columns['kiln']);
    $columns['updated_at'] = db_datetime(now());
    $columns = array_intersect_key($columns, firings_columns_present());

    $sets = [];
    foreach (array_keys($columns) as $name) {
        $sets[] = '`' . $name . '` = ?';
    }
    $params = array_values($columns);
    $params[] = $id;

    if (!db_query('UPDATE firings SET ' . implode(', ', $sets) . ' WHERE id = ?', $params)) {
        store_last_error(db_error());
        return false;
    }

    $ok = true;
    if (array_key_exists('checklist', $fields)) {
        $ok = firing_write_checks($id, is_array($fields['checklist']) ? $fields['checklist'] : []) && $ok;
    }
    if (array_key_exists('temps', $fields)) {
        $ok = firing_write_temps($id, is_array($fields['temps']) ? $fields['temps'] : []) && $ok;
    }
    if (array_key_exists('cone_pack', $fields)) {
        $ok = firing_write_cones($id, is_array($fields['cone_pack']) ? $fields['cone_pack'] : []) && $ok;
    }

    if (!$ok && store_last_error() === '') {
        store_last_error(db_error() ?: 'Part of that firing could not be saved.');
    }
    return $ok;
}

function firing_delete(int $id): bool
{
    store_last_error('');
    if (!db_ready()) {
        store_last_error(store_diagnose());
        return false;
    }
    // The child tables are ON DELETE CASCADE.
    if (!db_query('DELETE FROM firings WHERE id = ?', [$id])) {
        store_last_error(db_error());
        return false;
    }
    return true;
}

/* ---- child tables: replace wholesale, which matches how callers think ---- */

function firing_write_checks(int $id, array $checklist): bool
{
    if (!db_query('DELETE FROM firing_checks WHERE firing_id = ?', [$id])) {
        return false;
    }
    foreach ($checklist as $item => $when) {
        $at = db_datetime($when) ?? db_datetime(now());
        if (!db_query('INSERT INTO firing_checks (firing_id, item, checked_at) VALUES (?, ?, ?)',
                      [$id, (string)$item, $at])) {
            return false;
        }
    }
    return true;
}

function firing_write_temps(int $id, array $temps): bool
{
    if (!db_query('DELETE FROM firing_temps WHERE firing_id = ?', [$id])) {
        return false;
    }
    foreach ($temps as $temp) {
        $at = db_datetime($temp['at'] ?? '');
        if ($at === null) {
            continue;
        }
        if (!db_query('INSERT INTO firing_temps (firing_id, recorded_at, celsius) VALUES (?, ?, ?)',
                      [$id, $at, (float)($temp['c'] ?? 0)])) {
            return false;
        }
    }
    return true;
}

function firing_write_cones(int $id, array $pack): bool
{
    if (!db_query('DELETE FROM firing_cones WHERE firing_id = ?', [$id])) {
        return false;
    }
    foreach (array_values($pack) as $slot => $cone) {
        if (!db_query('INSERT INTO firing_cones (firing_id, slot, cone, role, position) VALUES (?, ?, ?, ?, ?)',
                      [$id, $slot, (string)($cone['cone'] ?? ''), (string)($cone['role'] ?? ''), (int)($cone['pos'] ?? 0)])) {
            return false;
        }
    }
    return true;
}

/* ------------------------------------------------------------------ */
/* Derived numbers                                                     */
/* ------------------------------------------------------------------ */

/**
 * How long the firing took.
 *
 * The controller's own figure wins when you have recorded it: it is the kiln's
 * measurement, and it does not depend on you having written down the moment you
 * pressed START. Falls back to the gap between the recorded times.
 */
function firing_actual_minutes(array $firing): ?int
{
    $reported = (int)($firing['controller_minutes'] ?? 0);
    if ($reported > 0) {
        return $reported;
    }
    return firing_elapsed_minutes($firing);
}

/**
 * Where a firing stands right now: 'done', 'running', 'upcoming' or 'stalled'.
 *
 * A missing end time on its own means nothing — every firing that has not been
 * fired yet is missing one. Being under way is a claim about the clock, so it
 * is read from the clock: a booking in November is not in progress in August,
 * and neither is one from last spring that nobody finished writing up.
 */
function firing_state(array $firing): string
{
    if (firing_actual_minutes($firing) !== null) {
        return 'done';
    }

    $start = parse_dt($firing['actual']['start_at'] ?? '')
        ?? parse_dt($firing['planned']['start_at'] ?? '');
    if (!$start) {
        return 'upcoming';
    }

    $now = now();
    if ($start > $now) {
        return 'upcoming';
    }

    // Started, with no end recorded. Only call that "in progress" while it
    // still could be — after the expected finish it is an unfinished record.
    $expected = parse_dt($firing['planned']['finish_at'] ?? '');
    if (!$expected) {
        $minutes = (int)($firing['planned']['program_minutes'] ?? 0)
            + (int)($firing['hold_minutes'] ?? 0);
        $preheat = (int)($firing['planned']['preheat_minutes'] ?? 0);
        if ($preheat > 0) {
            $minutes += $preheat + (int)($firing['planned']['overhead_minutes'] ?? 0);
        }
        // A day is a generous outside figure for any program on these kilns.
        $expected = add_minutes($start, $minutes > 0 ? $minutes : 24 * 60);
    }

    return $now <= $expected ? 'running' : 'stalled';
}

/** Strictly the gap between the recorded start and end, or null. */
function firing_elapsed_minutes(array $firing): ?int
{
    $start = parse_dt($firing['actual']['start_at'] ?? '');
    $end   = parse_dt($firing['actual']['end_at'] ?? '');
    if (!$start || !$end || $end <= $start) {
        return null;
    }
    return minutes_between($start, $end);
}

/**
 * What this program has actually taken you in the past — the number the
 * calculator should really be using instead of the manual's ballpark.
 * Matches on kiln + program + cone, then falls back to program + cone.
 */
function firing_history_estimate(string $kiln, string $program, string $cone): ?array
{
    if (!db_ready()) {
        return null;
    }

    $rows = db_rows(
        'SELECT kiln, actual_start, actual_end, controller_minutes, hold_minutes,
                planned_program_minutes, planned_preheat_minutes, planned_overhead_minutes
           FROM firings
          WHERE program = ? AND cone = ? AND manual_stop = 0
            AND (controller_minutes > 0
                 OR (actual_start IS NOT NULL AND actual_end IS NOT NULL
                     AND actual_end > actual_start))',
        [$program, $cone]
    );

    $exact = [];
    $loose = [];
    foreach ($rows as $row) {
        // The kiln's own figure where we have it, the clock otherwise.
        $minutes = (int)$row['controller_minutes'];
        if ($minutes <= 0) {
            $start = parse_dt($row['actual_start']);
            $end   = parse_dt($row['actual_end']);
            if (!$start || !$end) {
                continue;
            }
            $minutes = minutes_between($start, $end);
        }
        $preheat  = (int)$row['planned_preheat_minutes'];
        $overhead = (int)$row['planned_overhead_minutes'];
        // Strip the preheat and the hold: the field this feeds is the cone fire
        // program length on its own.
        $bare = $minutes - ($preheat > 0 ? $preheat + $overhead : 0) - (int)($row['hold_minutes'] ?? 0);
        if ($bare <= 0) {
            continue;
        }
        $loose[] = $bare;
        if ((string)$row['kiln'] === $kiln) {
            $exact[] = $bare;
        }
    }

    $sample = $exact ?: $loose;
    if (!$sample) {
        return null;
    }
    return [
        'minutes' => (int)round(array_sum($sample) / count($sample)),
        'count'   => count($sample),
        'scope'   => $exact ? 'this kiln' : 'all kilns',
    ];
}
