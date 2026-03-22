<?php declare(strict_types=1);

/**
 * Kreblu Bootstrap
 *
 * This file is required by every entry point (index.php, kb-admin/index.php, cron.php, etc.)
 * It loads configuration, sets up autoloading, and initializes core services.
 */

// Prevent direct access
if (!defined('KREBLU_ROOT')) {
    die('Direct access not permitted.');
}

// Record start time for performance measurement
define('KREBLU_START_TIME', microtime(true));

// Version
define('KREBLU_VERSION', '1.0.0-dev');

// Minimum requirements
define('KREBLU_MIN_PHP', '8.5.0');
define('KREBLU_MIN_MYSQL', '8.4.0');

// Check PHP version before anything else
if (version_compare(PHP_VERSION, KREBLU_MIN_PHP, '<')) {
    die(sprintf(
        'Kreblu requires PHP %s or newer. You are running PHP %s.',
        KREBLU_MIN_PHP,
        PHP_VERSION
    ));
}

// Load configuration
$configPath = KREBLU_ROOT . '/kb-config.php';
if (file_exists($configPath)) {
    require $configPath;
    define('KREBLU_INSTALLED', true);
} else {
    define('KREBLU_INSTALLED', false);
}

// Load the autoloader
require KREBLU_ROOT . '/kb-core/autoload.php';

// Load global helper functions
require KREBLU_ROOT . '/kb-core/Helpers/functions.php';
require KREBLU_ROOT . '/kb-core/Helpers/formatting.php';
require KREBLU_ROOT . '/kb-core/Helpers/url.php';
require KREBLU_ROOT . '/kb-core/Helpers/content.php';

// Initialize the application container
$app = Kreblu\Core\App::getInstance();

// Register core services (lazy-loaded)
$app->register('config', function () {
    return new Kreblu\Core\Config();
});

// Database connection is lazy — only connects on first query
$app->register('db', function () use ($app) {
    return new Kreblu\Core\Database\Connection(
        host: $app->config()->get('db_host', 'localhost'),
        port: (int) $app->config()->get('db_port', 3306),
        name: $app->config()->get('db_name', ''),
        user: $app->config()->get('db_user', ''),
        pass: $app->config()->get('db_pass', ''),
        prefix: $app->config()->get('db_prefix', 'kb_'),
    );
});

$app->register('hooks', function () {
    return new Kreblu\Core\Hooks\HookManager();
});

$app->register('cache', function () {
    return new Kreblu\Core\Cache\CacheManager(
        KREBLU_ROOT . '/kb-content/cache/'
    );
});

$app->register('auth', function () use ($app) {
    return new Kreblu\Core\Auth\AuthManager($app->get('db'));
});

$app->register('router', function () {
    return new Kreblu\Core\Http\Router();
});

// If not installed, only the installer should run
if (!KREBLU_INSTALLED) {
    return;
}

// Boot the application (loads plugins, fires init hooks, etc.)
$app->boot();
