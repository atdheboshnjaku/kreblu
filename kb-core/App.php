<?php declare(strict_types=1);

namespace Kreblu\Core;

/**
 * Application Container
 *
 * Lightweight service container. Holds references to all core services
 * and provides them to plugins and themes through kb_app().
 *
 * Services are lazy-loaded: the factory callable is only executed
 * on first access, then the result is cached.
 */
final class App
{
    private static ?App $instance = null;

    /** @var array<string, callable> Service factories */
    private array $services = [];

    /** @var array<string, mixed> Resolved service instances */
    private array $resolved = [];

    /** Whether the application has been booted */
    private bool $booted = false;

    private function __construct()
    {
        // Singleton — use getInstance()
    }

    /**
     * Get the singleton App instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the instance (used in testing only).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Register a service factory.
     *
     * The callable receives the App instance and should return the service.
     * It will only be called once — on first access.
     */
    public function register(string $key, callable $factory): void
    {
        $this->services[$key] = $factory;
        // Clear resolved instance if re-registering (useful in tests)
        unset($this->resolved[$key]);
    }

    /**
     * Get a service by key.
     *
     * @throws \RuntimeException If the service is not registered.
     */
    public function get(string $key): mixed
    {
        if (!isset($this->resolved[$key])) {
            if (!isset($this->services[$key])) {
                throw new \RuntimeException(
                    sprintf('Service "%s" is not registered in the application container.', $key)
                );
            }
            $this->resolved[$key] = ($this->services[$key])($this);
        }
        return $this->resolved[$key];
    }

    /**
     * Check if a service is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->services[$key]);
    }

    // ---------------------------------------------------------------
    // Typed shorthand accessors for core services
    // These exist so that IDE autocompletion works everywhere.
    // ---------------------------------------------------------------

    public function config(): Config
    {
        return $this->get('config');
    }

    public function db(): Database\Connection
    {
        return $this->get('db');
    }

    public function hooks(): Hooks\HookManager
    {
        return $this->get('hooks');
    }

    public function cache(): Cache\CacheManager
    {
        return $this->get('cache');
    }

    public function auth(): Auth\AuthManager
    {
        return $this->get('auth');
    }

    public function router(): Http\Router
    {
        return $this->get('router');
    }

    // ---------------------------------------------------------------
    // Application lifecycle
    // ---------------------------------------------------------------

    /**
     * Boot the application.
     *
     * Called after all services are registered and config is loaded.
     * Loads plugins, fires init hooks, sets up routes.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Load active plugins
        if ($this->has('db') && defined('KREBLU_INSTALLED') && KREBLU_INSTALLED) {
            $pluginLoader = new Plugin\PluginLoader($this);
            $pluginLoader->loadActivePlugins();

            // Fire: all plugins are loaded, they can register hooks now
            $this->hooks()->doAction('plugins_loaded');

            // Initialize built-in modules that are enabled
            $this->initializeModules();

            // Fire: everything is initialized, register post types / taxonomies here
            $this->hooks()->doAction('init');
        }

        $this->booted = true;
    }

    /**
     * Check if the application has been booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Initialize enabled built-in modules.
     */
    private function initializeModules(): void
    {
        $modules = [
            'forms'     => Modules\Forms\FormsModule::class,
            'seo'       => Modules\SEO\SEOModule::class,
            'i18n'      => Modules\i18n\I18nModule::class,
            'analytics' => Modules\Analytics\AnalyticsModule::class,
            'commerce'  => Modules\Commerce\CommerceModule::class,
            'ai'        => Modules\AI\AIModule::class,
            'email'     => Modules\Email\EmailModule::class,
        ];

        foreach ($modules as $key => $className) {
            $enabled = $this->config()->get("module_{$key}_enabled", true);
            if ($enabled && class_exists($className)) {
                $module = new $className($this);
                $module->initialize();
            }
        }
    }
}
