<?php
declare(strict_types=1);

/**
 * Schema setup and the one-time import from the old JSON files.
 *
 * Reachable without signing in only until there is a user in the database —
 * sign-in needs the users table, so it cannot be the gate on creating it.
 * After that it requires a login like everything else.
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/auth.php';

$bootstrapping = !db_ready() || (int)(db_value('SELECT COUNT(*) FROM users') ?? 0) === 0;
if (!$bootstrapping) {
    require_login();
}

/** Every table, in dependency order. */
function install_schema(): array
{
    return [
        'firings' => "CREATE TABLE IF NOT EXISTS firings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kiln VARCHAR(16) NOT NULL DEFAULT '',
            fired_by INT UNSIGNED NOT NULL DEFAULT 0,
            fired_by_name VARCHAR(190) NOT NULL DEFAULT '',
            booking_day DATE NULL,
            type VARCHAR(16) NOT NULL DEFAULT 'glaze',
            program VARCHAR(32) NOT NULL DEFAULT '',
            cone VARCHAR(8) NOT NULL DEFAULT '',
            hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            controller_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            planned_start DATETIME NULL,
            planned_finish DATETIME NULL,
            planned_unload_by DATETIME NULL,
            planned_program_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            planned_preheat_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            planned_overhead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            actual_start DATETIME NULL,
            actual_end DATETIME NULL,
            manual_stop TINYINT(1) NOT NULL DEFAULT 0,
            shelves VARCHAR(255) NOT NULL DEFAULT '',
            maintenance TEXT NULL,
            notes TEXT NULL,
            legacy_cone_top VARCHAR(255) NOT NULL DEFAULT '',
            legacy_cone_middle VARCHAR(255) NOT NULL DEFAULT '',
            legacy_cone_bottom VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_kiln (kiln),
            INDEX idx_fired_by (fired_by),
            INDEX idx_fired_by_name (fired_by_name),
            INDEX idx_booking_day (booking_day),
            INDEX idx_created (created_at),
            INDEX idx_program (program, cone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'firing_temps' => "CREATE TABLE IF NOT EXISTS firing_temps (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            firing_id INT UNSIGNED NOT NULL,
            recorded_at DATETIME NOT NULL,
            celsius DECIMAL(6,1) NOT NULL,
            INDEX idx_firing (firing_id, recorded_at),
            CONSTRAINT fk_temps_firing FOREIGN KEY (firing_id)
                REFERENCES firings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'firing_checks' => "CREATE TABLE IF NOT EXISTS firing_checks (
            firing_id INT UNSIGNED NOT NULL,
            item VARCHAR(64) NOT NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (firing_id, item),
            CONSTRAINT fk_checks_firing FOREIGN KEY (firing_id)
                REFERENCES firings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'firing_cones' => "CREATE TABLE IF NOT EXISTS firing_cones (
            firing_id INT UNSIGNED NOT NULL,
            slot TINYINT UNSIGNED NOT NULL,
            cone VARCHAR(8) NOT NULL DEFAULT '',
            role VARCHAR(16) NOT NULL DEFAULT '',
            position TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (firing_id, slot),
            CONSTRAINT fk_cones_firing FOREIGN KEY (firing_id)
                REFERENCES firings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            google_sub VARCHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            name VARCHAR(190) NOT NULL DEFAULT '',
            picture VARCHAR(512) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT '',
            sms_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            sms_asked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login DATETIME NOT NULL,
            UNIQUE KEY uniq_sub (google_sub),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'reminders' => "CREATE TABLE IF NOT EXISTS reminders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            firing_id INT UNSIGNED NOT NULL,
            visit TINYINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(16) NOT NULL DEFAULT 'sms',
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            sent_at DATETIME NOT NULL,
            UNIQUE KEY uniq_send (firing_id, visit, channel),
            CONSTRAINT fk_reminders_firing FOREIGN KEY (firing_id)
                REFERENCES firings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

$done = [];
$failed = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db()) {
    if (p('action') === 'create' || p('action') === 'rebuild') {
        // CREATE TABLE IF NOT EXISTS only ever creates; it will not add a column
        // to a table that already exists. Rebuilding is the honest way to pick
        // up a schema change, and cheap while there is nothing precious in here.
        if (p('action') === 'rebuild') {
            db_query('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse(array_keys(install_schema())) as $table) {
                if (db_query('DROP TABLE IF EXISTS `' . $table . '`') === null) {
                    $failed[] = 'Could not drop ' . $table . ': ' . db_error();
                }
            }
            db_query('SET FOREIGN_KEY_CHECKS = 1');
            $done[] = 'Existing tables dropped.';
        }

        foreach (install_schema() as $table => $sql) {
            if (db_query($sql) === null) {
                $failed[] = $table . ': ' . db_error();
            } else {
                $done[] = 'Table ' . $table . ' ready.';
            }
        }
    }

    if (p('action') === 'import') {
        // Firings from data/firings.json
        if (is_file(DATA_FILE)) {
            $data = json_decode((string)file_get_contents(DATA_FILE), true);
            $rows = is_array($data['firings'] ?? null) ? $data['firings'] : [];
            $imported = 0;
            foreach ($rows as $old) {
                $id = firing_create($old);
                if ($id > 0) {
                    // Preserve the original timestamps rather than "now".
                    db_query('UPDATE firings SET created_at = ?, updated_at = ? WHERE id = ?', [
                        db_datetime($old['created_at'] ?? '') ?? db_datetime(now()),
                        db_datetime($old['updated_at'] ?? '') ?? db_datetime(now()),
                        $id,
                    ]);
                    $imported++;
                } else {
                    $failed[] = 'A firing could not be imported: ' . store_last_error();
                }
            }
            $done[] = $imported . ' firing' . ($imported === 1 ? '' : 's') . ' imported.';
        } else {
            $done[] = 'No data/firings.json to import.';
        }

        // Settings from data/settings.json
        if (is_file(SETTINGS_FILE)) {
            $stored = json_decode((string)file_get_contents(SETTINGS_FILE), true);
            if (is_array($stored)) {
                settings_write($stored);
                $done[] = count($stored) . ' settings imported.';
            }
        }

        // Users from data/users.json
        if (is_file(USERS_FILE)) {
            $stored = json_decode((string)file_get_contents(USERS_FILE), true);
            $users  = is_array($stored['users'] ?? null) ? $stored['users'] : [];
            foreach ($users as $user) {
                db_query(
                    'INSERT IGNORE INTO users (google_sub, email, name, picture, created_at, last_login)
                          VALUES (?, ?, ?, ?, ?, ?)',
                    [(string)($user['sub'] ?? ''), (string)($user['email'] ?? ''),
                     (string)($user['name'] ?? ''), (string)($user['picture'] ?? ''),
                     db_datetime($user['created_at'] ?? '') ?? db_datetime(now()),
                     db_datetime($user['last_login'] ?? '') ?? db_datetime(now())]
                );
            }
            $done[] = count($users) . ' user profile' . (count($users) === 1 ? '' : 's') . ' imported.';
        }
    }
}

$tables = [];
if (db()) {
    foreach (array_keys(install_schema()) as $table) {
        $exists = db_value('SHOW TABLES LIKE ' . db()->quote($table)) !== null;
        $tables[$table] = [
            'exists' => $exists,
            'rows'   => $exists ? (int)(db_value('SELECT COUNT(*) FROM `' . $table . '`') ?? 0) : 0,
        ];
    }
}

$pageTitle = 'Install';
$active    = '';
$hideNav   = $bootstrapping;
require __DIR__ . '/partials/header.php';
?>

<section class="card">
    <h2>Database</h2>
    <?php if (!db()): ?>
        <p class="alert alert--warn"><?= e(db_error()) ?></p>
        <p class="hint">Copy <code>config/db.example.php</code> to <code>config/db.php</code> on the server
            and fill in the host, database name, user and password. That file is gitignored, so the
            password never enters the repository.</p>
    <?php else: ?>
        <p class="alert alert--ok">Connected to <code><?= e(DB_NAME) ?></code> on <code><?= e(DB_HOST) ?></code>.</p>
    <?php endif; ?>
</section>

<?php foreach ($done as $message): ?>
    <p class="alert alert--ok"><?= e($message) ?></p>
<?php endforeach; ?>
<?php foreach ($failed as $message): ?>
    <p class="alert alert--warn"><?= e($message) ?></p>
<?php endforeach; ?>

<?php if (db()): ?>
<section class="card">
    <h2>Tables</h2>
    <div class="table-scroll">
        <table class="steps-table">
            <thead><tr><th>Table</th><th>State</th><th>Rows</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $table => $state): ?>
                <tr>
                    <td><code><?= e($table) ?></code></td>
                    <td><?= $state['exists'] ? 'ready' : '<span class="drift-late">missing</span>' ?></td>
                    <td><?= $state['exists'] ? e($state['rows']) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" action="install.php">
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn btn--primary">Create any missing tables</button>
    </form>
    <p class="hint">Safe to run more than once &mdash; every statement is CREATE TABLE IF NOT EXISTS.
        It cannot add a column to a table that already exists, though, so after a schema change
        use the rebuild below.</p>

    <form method="post" action="install.php" class="rebuild"
          onsubmit="return confirm('Drop every table and recreate them? Everything in the database is lost.');">
        <input type="hidden" name="action" value="rebuild">
        <button type="submit" class="btn btn--danger">Drop everything and rebuild</button>
    </form>
    <p class="hint">Deletes all firings, users and settings, then recreates the schema from scratch.
        You will be signed out, because your profile goes with it &mdash; sign in again and a fresh
        one is made.</p>
</section>

<section class="card">
    <h2>Import the old JSON</h2>
    <p class="hint">One-time move of whatever is still in <code>data/</code>. Run it once: firings are
        appended, so running it twice gives you every firing twice.</p>
    <ul class="quirks">
        <li><code>data/firings.json</code> &mdash; <?= is_file(DATA_FILE) ? 'present' : 'not there' ?></li>
        <li><code>data/settings.json</code> &mdash; <?= is_file(SETTINGS_FILE) ? 'present' : 'not there' ?></li>
        <li><code>data/users.json</code> &mdash; <?= is_file(USERS_FILE) ? 'present' : 'not there' ?></li>
    </ul>
    <form method="post" action="install.php"
          onsubmit="return confirm('Import the JSON files into the database? Only do this once.');">
        <input type="hidden" name="action" value="import">
        <button type="submit" class="btn">Import</button>
    </form>
</section>

<section class="card">
    <h2>Then</h2>
    <p class="hint">Once the tables exist and the import has run, <a href="login.php">sign in</a>.
        After the first account is in the database this page needs a login of its own, so it stops being
        an open door. When you are happy everything is in MySQL, the <code>data/*.json</code> files can be
        deleted &mdash; nothing reads them except the importer above.</p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
