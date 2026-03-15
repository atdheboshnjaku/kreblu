<?php declare(strict_types=1);

/**
 * Kreblu Test Bootstrap
 *
 * Sets up the environment for PHPUnit tests.
 * Uses Composer autoloader in dev (for PHPUnit classes)
 * plus our own autoloader for Kreblu classes.
 */

// Define root path
define('KREBLU_ROOT', dirname(__DIR__));

// Use Composer autoloader for dev dependencies (PHPUnit, etc.)
require KREBLU_ROOT . '/vendor/autoload.php';

// Load our autoloader (for Kreblu classes)
require KREBLU_ROOT . '/kb-core/autoload.php';

// Mark as installed for testing (config comes from phpunit.xml env vars)
define('KREBLU_INSTALLED', true);
