<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 simplifie : Qlip\Foo\Bar -> src/Foo/Bar.php
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Qlip\\')) {
        return;
    }

    $relative = substr($class, 5);
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/Support/helpers.php';
