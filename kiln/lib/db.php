<?php
declare(strict_types=1);

/**
 * The database connection.
 *
 * Credentials are constants in config/db.php, which is gitignored — copy
 * config/db.example.php to it on the server. Everything else in the app talks
 * to lib/store.php, not to PDO directly.
 */

function db_config_loaded(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    if (is_file(DB_CONFIG_FILE)) {
        require_once DB_CONFIG_FILE;
    }
    $loaded = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER');
    return $loaded;
}

/** Why the database is not usable, in plain words. Empty when it is fine. */
function db_error(?string $set = null): string
{
    static $error = '';
    if ($set !== null) {
        $error = $set;
    }
    return $error;
}

/**
 * The connection, or null if it cannot be made. Never throws — callers decide
 * how loudly to complain, and the login page needs to render either way.
 */
function db(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    if (!db_config_loaded()) {
        db_error('No config/db.php yet. Copy config/db.example.php to config/db.php on the server '
            . 'and put the database credentials in it.');
        return null;
    }

    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    $port    = defined('DB_PORT') ? (int)DB_PORT : 3306;
    $dsn     = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . $charset;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // No SET time_zone: every datetime is written and read in the app's
        // zone by db_datetime()/db_iso(), and nothing here uses MySQL's NOW().
        // That also keeps this free of any ordering dependency on the settings,
        // which are themselves loaded from the database.
        db_error('');
    } catch (PDOException $e) {
        $pdo = null;
        db_error('Could not connect to the database: ' . $e->getMessage());
    }

    return $pdo;
}

/** Run a query and hand back the statement. Returns null if the DB is down. */
function db_query(string $sql, array $params = []): ?PDOStatement
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        db_error('');
        return $statement;
    } catch (PDOException $e) {
        db_error('Database error: ' . $e->getMessage());
        return null;
    }
}

function db_rows(string $sql, array $params = []): array
{
    $statement = db_query($sql, $params);
    return $statement ? $statement->fetchAll() : [];
}

function db_row(string $sql, array $params = []): ?array
{
    $statement = db_query($sql, $params);
    if (!$statement) {
        return null;
    }
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $statement = db_query($sql, $params);
    if (!$statement) {
        return null;
    }
    $value = $statement->fetchColumn();
    return $value === false ? null : $value;
}

/** True when the schema is present. */
function db_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!db()) {
        $ready = false;
        return $ready;
    }
    $ready = db_value('SHOW TABLES LIKE ' . db()->quote('firings')) !== null;
    return $ready;
}

/** DATETIME for the database, from anything parse_dt understands. */
function db_datetime($value): ?string
{
    $date = $value instanceof DateTimeImmutable ? $value : parse_dt($value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}

/** Back the other way: a stored DATETIME as the ISO string the app passes around. */
function db_iso(?string $value): string
{
    if ($value === null || $value === '' || strpos($value, '0000') === 0) {
        return '';
    }
    $date = parse_dt($value);
    return $date ? $date->format('c') : '';
}
