<?php declare(strict_types=1);

namespace Kreblu\Core;

/**
 * Configuration Manager
 *
 * Reads configuration from kb-config.php constants and environment variables.
 * Environment variables (prefixed with KB_) override config file values.
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
     * Load configuration from kb-config.php defined constants.
     *
     * Constants follow the pattern: KB_DB_HOST, KB_DB_NAME, etc.
     * They are mapped to config keys: db_host, db_name, etc.
     */
    private function loadFromConstants(): void
    {
        $mapping = [
            'KREBLU_DB_HOST'     => 'db_host',
            'KREBLU_DB_PORT'     => 'db_port',
            'KREBLU_DB_NAME'     => 'db_name',
            'KREBLU_DB_USER'     => 'db_user',
            'KREBLU_DB_PASS'     => 'db_pass',
            'KREBLU_DB_PREFIX'   => 'db_prefix',
            'KREBLU_SITE_URL'    => 'site_url',
            'KREBLU_SITE_NAME'   => 'site_name',
            'KREBLU_DEBUG'       => 'debug',
            'KREBLU_ENV'         => 'environment',
            'KREBLU_AUTH_SALT'   => 'auth_salt',
            'KREBLU_AUTH_KEY'    => 'auth_key',
            'KREBLU_SECURE_KEY'  => 'secure_key',
            'KREBLU_NONCE_KEY'   => 'nonce_key',
            'KREBLU_NONCE_SALT'  => 'nonce_salt',
        ];

        foreach ($mapping as $constant => $key) {
            if (defined($constant)) {
                $this->values[$key] = constant($constant);
            }
        }

        // Defaults
        $this->values['db_host']    ??= 'localhost';
        $this->values['db_port']    ??= 3306;
        $this->values['db_prefix']  ??= 'kb_';
        $this->values['debug']      ??= false;
        $this->values['environment'] ??= 'production';
    }

    /**
     * Load configuration from environment variables.
     *
     * Environment variables override constants from kb-config.php.
     * This allows Docker, CI, and hosting platforms to inject config.
     */
    private function loadFromEnvironment(): void
    {
        $envMapping = [
            'KREBLU_DB_HOST'   => 'db_host',
            'KREBLU_DB_PORT'   => 'db_port',
            'KREBLU_DB_NAME'   => 'db_name',
            'KREBLU_DB_USER'   => 'db_user',
            'KREBLU_DB_PASS'   => 'db_pass',
            'KREBLU_DB_PREFIX' => 'db_prefix',
            'KREBLU_SITE_URL'  => 'site_url',
            'KREBLU_DEBUG'     => 'debug',
            'KREBLU_ENV'       => 'environment',
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
