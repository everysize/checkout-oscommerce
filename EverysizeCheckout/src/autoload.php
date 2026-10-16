<?php

/**
 * Registers an autoloader for the everysize checkout core (src/). osCommerce
 * extensions are not composer-installed in the shop, so the osCommerce layer
 * pulls in its namespaced classes through this file (required by every module
 * class that touches the core). Safe to require multiple times.
 */

if (!\function_exists('everysize_checkout_autoload')) {
    function everysize_checkout_autoload(string $class): void
    {
        $prefix = 'Everysize\\Checkout\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        // The shop layer lives in classes/ under its own namespace
        // (Everysize\Checkout\osCommerce\*), the pure core in src/.
        if (str_starts_with($relative, 'osCommerce\\')) {
            $file = dirname(__DIR__) . '/classes/' . str_replace('\\', '/', substr($relative, strlen('osCommerce\\'))) . '.php';
        } else {
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        }
        if (is_file($file)) {
            require_once $file;
        }
    }

    \spl_autoload_register('everysize_checkout_autoload');
}
