<?php declare(strict_types=1);

namespace Kreblu\Core;

/**
 * Configuration Manager
 *
 * Reads configuration from os-config.php constants and environment variables.
 * Environment variables (prefixed with OS_) override config file values.
 * This allows Docker and CI environments to inject config without modifying files.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct()
    {
        $this->loadFromConstants();
        $this->loadFromEnvironment();
    }

    /**
     * Get a config value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Set a config value at runtime.
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Check if a config key exists.
     */
    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Get all config values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Load configuration from os-config.php defined constants.
     *
     * Constants follow the pattern: OS_DB_HOST, OS_DB_NAME, etc.
     * They are mapped to config keys: db_host, db_name, etc.
     */
    private function loadFromConstants(): void
    {
        $mapping = [
            'OS_DB_HOST'     => 'db_host',
            'OS_DB_PORT'     => 'db_port',
            'OS_DB_NAME'     => 'db_name',
            'OS_DB_USER'     => 'db_user',
            'OS_DB_PASS'     => 'db_pass',
            'OS_DB_PREFIX'   => 'db_prefix',
            'OS_SITE_URL'    => 'site_url',
            'OS_DEBUG'       => 'debug',
            'OS_ENV'         => 'environment',
            'OS_AUTH_KEY'    => 'auth_key',
            'OS_SECURE_KEY'  => 'secure_key',
            'OS_NONCE_KEY'   => 'nonce_key',
            'OS_NONCE_SALT'  => 'nonce_salt',
        ];

        foreach ($mapping as $constant => $key) {
            if (defined($constant)) {
                $this->values[$key] = constant($constant);
            }
        }

        // Defaults
        $this->values['db_host']    ??= 'localhost';
        $this->values['db_port']    ??= 3306;
        $this->values['db_prefix']  ??= 'os_';
        $this->values['debug']      ??= false;
        $this->values['environment'] ??= 'production';
    }

    /**
     * Load configuration from environment variables.
     *
     * Environment variables override constants from os-config.php.
     * This allows Docker, CI, and hosting platforms to inject config.
     */
    private function loadFromEnvironment(): void
    {
        $envMapping = [
            'OS_DB_HOST'   => 'db_host',
            'OS_DB_PORT'   => 'db_port',
            'OS_DB_NAME'   => 'db_name',
            'OS_DB_USER'   => 'db_user',
            'OS_DB_PASS'   => 'db_pass',
            'OS_DB_PREFIX' => 'db_prefix',
            'OS_SITE_URL'  => 'site_url',
            'OS_DEBUG'     => 'debug',
            'OS_ENV'       => 'environment',
        ];

        foreach ($envMapping as $envVar => $key) {
            $value = getenv($envVar);
            if ($value !== false) {
                // Type casting for known types
                $this->values[$key] = match ($key) {
                    'db_port' => (int) $value,
                    'debug'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    default   => $value,
                };
            }
        }
    }
}
