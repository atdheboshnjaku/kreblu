<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit;

use Kreblu\Core\Config;
use Kreblu\Tests\TestCase;

/**
 * Tests for the Config manager.
 */
final class ConfigTest extends TestCase
{
    public function test_get_returns_default_for_missing_key(): void
    {
        $config = new Config();
        $this->assertEquals('fallback', $config->get('nonexistent', 'fallback'));
    }

    public function test_get_returns_null_for_missing_key_without_default(): void
    {
        $config = new Config();
        $this->assertNull($config->get('nonexistent'));
    }

    public function test_set_and_get(): void
    {
        $config = new Config();
        $config->set('test_key', 'test_value');
        $this->assertEquals('test_value', $config->get('test_key'));
    }

    public function test_has_returns_true_for_set_key(): void
    {
        $config = new Config();
        $config->set('exists', true);
        $this->assertTrue($config->has('exists'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $config = new Config();
        $this->assertFalse($config->has('nonexistent'));
    }

    public function test_all_returns_array(): void
    {
        $config = new Config();
        $this->assertIsArray($config->all());
    }

    public function test_loads_defaults_for_db_host(): void
    {
        $config = new Config();
        // Should have a default db_host even without config file
        $this->assertNotNull($config->get('db_host'));
    }

    public function test_loads_defaults_for_db_prefix(): void
    {
        $config = new Config();
        // In test environment, phpunit.xml sets this to kb_test_
        $this->assertNotNull($config->get('db_prefix'));
        $this->assertStringEndsWith('_', $config->get('db_prefix'));
    }

    public function test_environment_variable_override(): void
    {
        // Set an env var
        putenv('KB_DB_HOST=test_host');

        $config = new Config();
        $this->assertEquals('test_host', $config->get('db_host'));

        // Clean up
        putenv('KB_DB_HOST');
    }

    public function test_debug_env_parsed_as_boolean(): void
    {
        putenv('KB_DEBUG=true');

        $config = new Config();
        $this->assertTrue($config->get('debug'));

        putenv('KB_DEBUG');
    }

    public function test_port_env_parsed_as_integer(): void
    {
        putenv('KB_DB_PORT=3307');

        $config = new Config();
        $this->assertSame(3307, $config->get('db_port'));

        putenv('KB_DB_PORT');
    }
}
