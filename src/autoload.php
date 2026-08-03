<?php
/**
 * Minimal PSR-4 autoloader mapping GameTracker\ onto src/.
 *
 * Deliberately dependency-free. The project has no composer.json, no vendor/
 * and no dependency manager, and the deploy story (git pull on an
 * intermittently-powered laptop) stays simpler without one. If a real
 * third-party dependency ever lands, replace this with composer's autoloader —
 * the namespace layout is already PSR-4 so that swap is mechanical.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'GameTracker\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
