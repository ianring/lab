<?php
declare(strict_types=1);

/**
 * Make PHP say what actually went wrong instead of handing the browser a bare
 * 500. Include this FIRST, before any other require, so its shutdown handler is
 * registered before the other files are even parsed - that way a parse error in
 * one of them still gets reported.
 *
 * Note the deliberate HTTP 200: some servers replace the body of a 500 with
 * their own ErrorDocument, which is exactly the "tells me nothing" behaviour
 * this file exists to defeat.
 *
 * A parse error in the *entry* script itself happens before any of this runs.
 * For that, on the server:  php -l staff.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!function_exists('sudoku_report_failure')) {

    function sudoku_report_failure(string $kind, string $message, string $file, int $line, string $trace = ''): void
    {
        $started = headers_sent();
        if (!$started) {
            header('Content-Type: text/plain; charset=utf-8', true, 200);
        }

        $body = "\n";
        $body .= str_repeat('=', 72) . "\n";
        $body .= $kind . "\n";
        $body .= str_repeat('=', 72) . "\n\n";
        $body .= $message . "\n\n";
        $body .= 'at ' . $file . ':' . $line . "\n";
        if ($trace !== '') {
            $body .= "\n" . $trace . "\n";
        }
        $body .= "\nPHP " . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
        $body .= 'memory peak ' . number_format(memory_get_peak_usage(true) / 1048576, 1) . " MB\n";

        echo $started ? "\n<pre>" . htmlspecialchars($body, ENT_QUOTES) . '</pre>' : $body;
    }

    set_exception_handler(static function (\Throwable $e): void {
        sudoku_report_failure(
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    });

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING, E_USER_ERROR];
        if (!in_array($e['type'], $fatal, true)) {
            return;
        }
        sudoku_report_failure('Fatal error', $e['message'], $e['file'], $e['line']);
    });
}
