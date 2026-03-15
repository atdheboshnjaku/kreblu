<?php declare(strict_types=1);

namespace Kreblu\Tests;

use Kreblu\Core\App;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for all Kreblu tests.
 *
 * Provides helper methods for common test operations.
 * Resets the App singleton between tests to prevent state leakage.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the application container before each test
        App::resetInstance();
    }

    protected function tearDown(): void
    {
        // Reset again after test
        App::resetInstance();

        parent::tearDown();
    }

    /**
     * Get a fresh App instance with default test services registered.
     */
    protected function createApp(): App
    {
        $app = App::getInstance();

        $app->register('config', function () {
            $config = new \Kreblu\Core\Config();
            return $config;
        });

        $app->register('hooks', function () {
            return new \Kreblu\Core\Hooks\HookManager();
        });

        return $app;
    }
}
