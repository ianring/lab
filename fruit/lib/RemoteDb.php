<?php
declare(strict_types=1);

/**
 * Connection for scripts that run on i-a-n.ca and talk to MySQL on ianring.com.
 * No PHP runs on the far end; only SQL crosses.
 *
 * Settings come from foraging-db.php beside the script (gitignored):
 *
 *   <?php return ['host'=>'127.0.0.1','port'=>3307,'db'=>'x','user'=>'y','pass'=>'z'];
 *
 * or FORAGE_DB_HOST / _PORT / _NAME / _USER / _PASS in the environment. With
 * neither, it falls back to a local getDbConn().
 *
 * Prefer an SSH tunnel over exposing 3306:
 *   ssh -f -N -L 3307:127.0.0.1:3306 root@ianring.com
 * then host=127.0.0.1 port=3307. MySQL sees a local connection, so there is no
 * bind-address change, no firewall hole, no extra grant, and SSH encrypts it.
 */
final class RemoteDb
{
    /** @var PDO|mysqli */
    private $conn;
    private bool $isPdo;
    public string $describe;
    public bool $tunnelled = false;

    private function __construct($conn, string $describe, bool $tunnelled)
    {
        $this->conn      = $conn;
        $this->isPdo     = $conn instanceof PDO;
        $this->describe  = $describe;
        $this->tunnelled = $tunnelled;
    }

    public static function config(string $dir): ?array
    {
        $file = $dir . '/foraging-db.php';
        if (is_readable($file)) {
            $c = require $file;
            if (is_array($c) && isset($c['db'], $c['user'])) {
                return $c + ['host' => '127.0.0.1', 'port' => 3306, 'pass' => ''];
            }
        }
        $host = getenv('FORAGE_DB_HOST');
        if ($host !== false && $host !== '') {
            return [
                'host' => $host,
                'port' => (int) (getenv('FORAGE_DB_PORT') ?: 3306),
                'db'   => (string) getenv('FORAGE_DB_NAME'),
                'user' => (string) getenv('FORAGE_DB_USER'),
                'pass' => (string) getenv('FORAGE_DB_PASS'),
            ];
        }
        return null;
    }

    public static function connect(string $dir): self
    {
        $cfg = self::config($dir);

        if ($cfg !== null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                (int) $cfg['port'],
                $cfg['db']
            );
            try {
                $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 10,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "could not reach {$cfg['host']}:{$cfg['port']} - " . $e->getMessage() . "\n\n"
                    . "  in the order these usually fail:\n"
                    . "  1. is MySQL on ianring.com listening off localhost? (bind-address)\n"
                    . "  2. is port {$cfg['port']} reachable from this machine?\n"
                    . "  3. does the grant cover this host, not just 'localhost'?\n\n"
                    . "  a tunnel avoids all three:\n"
                    . "    ssh -f -N -L 3307:127.0.0.1:3306 root@ianring.com\n"
                    . "    then host=127.0.0.1 port=3307\n"
                );
            }
            $local = in_array($cfg['host'], ['127.0.0.1', 'localhost', '::1'], true);
            return new self(
                $pdo,
                $cfg['host'] . ':' . $cfg['port']
                    . ($local ? ' (local - presumably a tunnel)' : ' (DIRECT - in the clear unless TLS is on)'),
                $local
            );
        }

        foreach (['/root/dbconn.php', '/root/dbconn'] as $f) {
            if (is_file($f)) {
                require_once $f;
                break;
            }
        }
        if (!function_exists('getDbConn')) {
            throw new RuntimeException(
                "No foraging-db.php, no FORAGE_DB_* environment, and no getDbConn().\n"
                . 'See the notes at the top of lib/RemoteDb.php.'
            );
        }
        $conn = getDbConn();
        if ($conn instanceof PDO) {
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return new self($conn, 'local getDbConn() (PDO)', true);
        }
        if ($conn instanceof mysqli) {
            return new self($conn, 'local getDbConn() (mysqli)', true);
        }
        throw new RuntimeException(
            'getDbConn() returned ' . (is_object($conn) ? get_class($conn) : gettype($conn))
            . '; expected PDO or mysqli.'
        );
    }

    public function rows(string $sql, array $args = []): array
    {
        if ($this->isPdo) {
            $st = $this->conn->prepare($sql);
            $st->execute($args);
            return $st->fetchAll();
        }
        $st = $this->conn->prepare($sql);
        if ($st === false) {
            throw new RuntimeException('prepare failed: ' . $this->conn->error . ' -- ' . $sql);
        }
        if ($args) {
            $types = '';
            foreach ($args as $a) {
                $types .= is_int($a) ? 'i' : (is_float($a) ? 'd' : 's');
            }
            $st->bind_param($types, ...array_values($args));
        }
        $st->execute();
        $res  = $st->get_result();
        $out  = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $out;
    }

    public function value(string $sql, array $args = [])
    {
        $r = $this->rows($sql, $args);
        return $r ? reset($r[0]) : null;
    }

    public function run(string $sql, array $args = []): int
    {
        if ($this->isPdo) {
            $st = $this->conn->prepare($sql);
            $st->execute($args);
            return $st->rowCount();
        }
        $st = $this->conn->prepare($sql);
        if ($st === false) {
            throw new RuntimeException('prepare failed: ' . $this->conn->error . ' -- ' . $sql);
        }
        if ($args) {
            $types = '';
            foreach ($args as $a) {
                $types .= is_int($a) ? 'i' : (is_float($a) ? 'd' : 's');
            }
            $st->bind_param($types, ...array_values($args));
        }
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        return $n;
    }

    public function exec(string $sql): void
    {
        if ($this->isPdo) {
            $this->conn->exec($sql);
            return;
        }
        if (!$this->conn->query($sql)) {
            throw new RuntimeException('query failed: ' . $this->conn->error);
        }
    }

    public function begin(): void
    {
        $this->isPdo ? $this->conn->beginTransaction() : $this->conn->begin_transaction();
    }

    public function commit(): void
    {
        $this->conn->commit();
    }

    /**
     * Columns with enough detail to judge whether they can hold a coordinate.
     * COLUMN_TYPE alone is not enough: decimal(11,0) reads as a decimal but has
     * no fractional digits at all, so it truncates exactly like an integer.
     *
     * @return array<string,array{type:string,data_type:string,scale:?int}>
     */
    public function columns(string $table): array
    {
        $db  = (string) $this->value('SELECT DATABASE()');
        $out = [];
        foreach ($this->rows(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$db, $table]
        ) as $c) {
            $out[$c['COLUMN_NAME']] = [
                'type'      => (string) $c['COLUMN_TYPE'],
                'data_type' => strtolower((string) $c['DATA_TYPE']),
                'precision' => $c['NUMERIC_PRECISION'] === null ? null : (int) $c['NUMERIC_PRECISION'],
                'scale'     => $c['NUMERIC_SCALE'] === null ? null : (int) $c['NUMERIC_SCALE'],
            ];
        }
        return $out;
    }

    /** Whichever names this table happens to use for the coordinate pair. */
    public static function coordColumns(array $cols): array
    {
        $find = static function (array $names) use ($cols): ?string {
            foreach ($names as $n) {
                if (isset($cols[$n])) {
                    return $n;
                }
            }
            return null;
        };
        return [
            'lat' => $find(['lat', 'latitude']),
            'lng' => $find(['lng', 'lon', 'long', 'longitude']),
        ];
    }

    /**
     * Can this column hold a usable coordinate? Both sides of the point matter,
     * and DECIMAL(M,D) gets each of them wrong in a different way:
     *
     *   D too small   decimal(11,0) - no fractional digits, 43.4516 -> 43
     *   M - D too small  decimal(11,11) - no INTEGER digits, so the largest
     *                    value is 0.99999999999 and 43.4516 will not fit at all
     *
     * Longitude reaches -180, so three digits are needed before the point;
     * street precision needs four or more after it. DECIMAL(10,7) is the
     * comfortable choice. float and double declare neither and are fine.
     *
     * @return true|string true, or why not
     */
    public static function coordinateProblem(array $col): ?string
    {
        if (in_array($col['data_type'], ['float', 'double'], true)) {
            return null;
        }
        if (!in_array($col['data_type'], ['decimal', 'numeric'], true)) {
            return $col['type'] . ' cannot hold a fractional coordinate at all';
        }

        $scale     = $col['scale'] ?? 0;
        $precision = $col['precision'] ?? 0;
        $whole     = $precision - $scale;

        if ($whole < 3) {
            return $col['type'] . " leaves only $whole digit(s) before the decimal point, so the "
                . 'largest value it can hold is ' . ($whole > 0 ? str_repeat('9', $whole) : '0')
                . '.' . str_repeat('9', min($scale, 6)) . '... - latitude 43.45 will not fit, '
                . 'and longitude needs room for -180';
        }
        if ($scale < 4) {
            return $col['type'] . " keeps only $scale digit(s) after the decimal point"
                . ($scale === 0 ? ', so 43.4516 stores as 43' : ', which is coarser than a city block');
        }
        return null;
    }

    public static function holdsCoordinate(array $col): bool
    {
        return self::coordinateProblem($col) === null;
    }
}
