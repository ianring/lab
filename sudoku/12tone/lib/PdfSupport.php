<?php
declare(strict_types=1);

/**
 * Locates TCPDF and FPDI, and works around the one hard limit of the free FPDI:
 * it cannot parse compressed cross-reference streams, i.e. anything above
 * PDF 1.4. LilyPond's PDF output may well be newer than that, so when an import
 * fails we hand the file to Ghostscript to be rewritten as 1.4 and try again.
 *
 * The libraries can arrive three ways, tried in this order:
 *   1. Composer            composer install
 *   2. A plain download    sh install-libs.sh   (no composer needed)
 *   3. An existing copy    SUDOKU_VENDOR=/path/to/vendor
 *                          TCPDF_PATH=/path/to/tcpdf.php  FPDI_SRC=/path/to/fpdi/src
 */
final class PdfSupport
{
    private static ?bool $loaded = null;
    private static array $notes = [];
    private static ?string $gs = null;
    private static bool $gsChecked = false;

    /** Composer autoloaders to try, nearest first. */
    private static function autoloadCandidates(): array
    {
        $paths = [];
        $env = getenv('SUDOKU_VENDOR');
        if ($env) {
            $paths[] = rtrim($env, '/') . '/autoload.php';
        }
        $paths[] = dirname(__DIR__) . '/vendor/autoload.php';           // 12tone/vendor
        $paths[] = dirname(__DIR__, 2) . '/vendor/autoload.php';        // sudoku/vendor
        $paths[] = dirname(__DIR__, 3) . '/vendor/autoload.php';        // lab/vendor
        $paths[] = '/usr/share/php/vendor/autoload.php';
        return $paths;
    }

    public static function load(): bool
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        foreach (self::autoloadCandidates() as $file) {
            if (is_file($file)) {
                require_once $file;
                self::$notes[] = 'autoloader: ' . $file;
                break;
            }
        }

        // Distribution packages ship TCPDF without an autoloader.
        if (!class_exists('TCPDF')) {
            $direct = array_filter([
                getenv('TCPDF_PATH') ?: null,
                '/usr/share/php/tcpdf/tcpdf.php',
                '/usr/share/tcpdf/tcpdf.php',
            ]);
            foreach ($direct as $file) {
                if (is_file($file)) {
                    require_once $file;
                    self::$notes[] = 'tcpdf: ' . $file;
                    break;
                }
            }
        }

        // No composer? Fall back to a plain ./libs/ download.
        if (!class_exists('TCPDF') || !class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            self::registerManual();
        }

        $hasTcpdf = class_exists('TCPDF');
        $hasFpdi  = class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi');

        if (!$hasTcpdf) {
            self::$notes[] = 'TCPDF not found';
        }
        if (!$hasFpdi) {
            self::$notes[] = 'FPDI (setasign\\Fpdi\\Tcpdf\\Fpdi) not found';
        }

        self::$loaded = $hasTcpdf && $hasFpdi;
        return self::$loaded;
    }

    /**
     * Wire up a Composer-free install: TCPDF is a single include, FPDI just
     * needs a PSR-4 mapping for its one namespace. Neither library has runtime
     * dependencies of its own, so this is all the autoloading they require.
     */
    private static function registerManual(): void
    {
        $libs = dirname(__DIR__) . '/libs';

        if (!class_exists('TCPDF')) {
            $candidates = array_filter([
                getenv('TCPDF_PATH') ?: null,
                $libs . '/tcpdf/tcpdf.php',
            ]);
            foreach ($candidates as $file) {
                if (is_file($file)) {
                    require_once $file;
                    self::$notes[] = 'tcpdf (manual): ' . $file;
                    break;
                }
            }
        }

        if (class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            return;
        }

        $src = getenv('FPDI_SRC') ?: $libs . '/fpdi/src';
        if (!is_dir($src)) {
            return;
        }
        $src = rtrim($src, '/');
        self::$notes[] = 'fpdi (manual): ' . $src;

        spl_autoload_register(static function (string $class) use ($src): void {
            $prefix = 'setasign\\Fpdi\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $src . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public static function available(): bool
    {
        return self::load();
    }

    /** @return array{ok:bool,tcpdf:bool,fpdi:bool,ghostscript:?string,notes:string[]} */
    public static function status(): array
    {
        $ok = self::load();
        return [
            'ok'          => $ok,
            'tcpdf'       => class_exists('TCPDF'),
            'fpdi'        => class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi'),
            'ghostscript' => self::ghostscript(),
            'notes'       => self::$notes,
        ];
    }

    public static function installHint(): string
    {
        $dir = dirname(__DIR__);
        return "Any one of these will do, from $dir:\n\n"
            . "  1. With composer:\n"
            . "       composer install\n\n"
            . "  2. Without composer (downloads into ./libs/):\n"
            . "       sh install-libs.sh\n\n"
            . "  3. Point at copies you already have:\n"
            . "       SUDOKU_VENDOR=/path/to/vendor\n"
            . "       or TCPDF_PATH=/path/to/tcpdf.php and FPDI_SRC=/path/to/fpdi/src\n\n"
            . "To install composer itself:\n"
            . "  php -r \"copy('https://getcomposer.org/installer','/tmp/composer-setup.php');\"\n"
            . "  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer";
    }

    // ------------------------------------------------------------ ghostscript

    public static function ghostscript(): ?string
    {
        if (self::$gsChecked) {
            return self::$gs;
        }
        self::$gsChecked = true;

        $bin = getenv('GS_BIN') ?: 'gs';
        $out = [];
        $status = 1;
        @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $status);
        if ($status === 0 && $out) {
            self::$gs = $out[0];
        }
        return self::$gs;
    }

    /**
     * Rewrite a PDF as version 1.4 so the free FPDI can parse it.
     * Vector line art survives this losslessly - there are no raster images in
     * a LilyPond score to be downsampled.
     */
    public static function downgrade(string $in, string $out): bool
    {
        $gs = self::ghostscript();
        if ($gs === null || !is_file($in)) {
            return false;
        }
        $cmd = escapeshellarg($gs)
            . ' -q -dNOPAUSE -dBATCH -dSAFER'
            . ' -sDEVICE=pdfwrite -dCompatibilityLevel=1.4'
            . ' -sOutputFile=' . escapeshellarg($out)
            . ' ' . escapeshellarg($in);

        $lines = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $lines, $status);

        return $status === 0 && is_file($out) && filesize($out) > 0;
    }

    /** Version string from a PDF's header, e.g. "1.4". */
    public static function pdfVersion(string $file): ?string
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return null;
        }
        $head = (string) fread($fh, 16);
        fclose($fh);
        return preg_match('/%PDF-(\d\.\d)/', $head, $m) ? $m[1] : null;
    }
}
