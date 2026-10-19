<?php

declare(strict_types=1);

/**
 * Hand-rolled PSR-4-style autoloader: Puzzlebook2\Foo\Bar -> lib/Foo/Bar.php.
 * No Composer dependency -- nothing to install, just a namespace-to-path
 * mapping rooted at this file's own directory.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Puzzlebook2\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
