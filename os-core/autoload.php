<?php declare(strict_types=1);

/**
 * Kreblu PSR-4 Autoloader
 *
 * This autoloader works WITHOUT Composer at runtime.
 * Composer is used only during development for dev tools (PHPUnit, PHPStan).
 * In production, this file handles all class loading.
 */

spl_autoload_register(function (string $class): void {
    // Namespace prefix => base directory mappings
    $prefixes = [
        'Kreblu\\Core\\'  => KREBLU_ROOT . '/os-core/',
        'Kreblu\\Admin\\' => KREBLU_ROOT . '/os-admin/controllers/',
        'Kreblu\\CLI\\'   => KREBLU_ROOT . '/os-cli/commands/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $prefixLength = strlen($prefix);

        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            continue;
        }

        // Get the relative class name after the namespace prefix
        $relativeClass = substr($class, $prefixLength);

        // Replace namespace separators with directory separators
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
