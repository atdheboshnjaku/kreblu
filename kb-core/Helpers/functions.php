<?php declare(strict_types=1);

/**
 * Kreblu Global Helper Functions
 *
 * These functions are available everywhere: in themes, plugins, and core.
 * They provide convenient access to the application container and its services.
 *
 * WordPress developers: these map directly to WP functions you already know.
 * See the function mapping table in the documentation.
 */

// ---------------------------------------------------------------
// Application access
// ---------------------------------------------------------------

/**
 * Get the application container instance.
 */
function kb_app(): Kreblu\Core\App
{
    return Kreblu\Core\App::getInstance();
}

/**
 * Get the database query builder.
 */
function kb_db(): Kreblu\Core\Database\Connection
{
    return kb_app()->db();
}

// ---------------------------------------------------------------
// Hooks (Actions & Filters)
// ---------------------------------------------------------------

/**
 * Register a callback to run at a hook point.
 */
function kb_add_action(string $hook, callable $callback, int $priority = 10): void
{
    kb_app()->hooks()->addAction($hook, $callback, $priority);
}

/**
 * Register a callback to modify data at a hook point.
 */
function kb_add_filter(string $hook, callable $callback, int $priority = 10): void
{
    kb_app()->hooks()->addFilter($hook, $callback, $priority);
}

/**
 * Fire an action hook.
 */
function kb_do_action(string $hook, mixed ...$args): void
{
    kb_app()->hooks()->doAction($hook, ...$args);
}

/**
 * Apply filters to a value.
 */
function kb_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return kb_app()->hooks()->applyFilters($hook, $value, ...$args);
}

/**
 * Remove a previously registered action.
 */
function kb_remove_action(string $hook, callable $callback, int $priority = 10): void
{
    kb_app()->hooks()->removeAction($hook, $callback, $priority);
}

/**
 * Remove a previously registered filter.
 */
function kb_remove_filter(string $hook, callable $callback, int $priority = 10): void
{
    kb_app()->hooks()->removeFilter($hook, $callback, $priority);
}

// ---------------------------------------------------------------
// Options (site settings)
// ---------------------------------------------------------------

/**
 * Get a site option value.
 */
function kb_get_option(string $key, mixed $default = null): mixed
{
    // TODO: Implement with database query + autoload cache
    return $default;
}

/**
 * Update a site option value.
 */
function kb_update_option(string $key, mixed $value): bool
{
    // TODO: Implement with database upsert + cache invalidation
    return false;
}

// ---------------------------------------------------------------
// Content
// ---------------------------------------------------------------

/**
 * Get a post by ID.
 */
function kb_get_post(int $id): ?object
{
    // TODO: Implement in Phase 1 (Content system)
    return null;
}

/**
 * Query posts.
 *
 * @param array<string, mixed> $args Query arguments
 * @return array<object>
 */
function kb_get_posts(array $args = []): array
{
    // TODO: Implement in Phase 1 (Content system)
    return [];
}

/**
 * Insert a new post.
 *
 * @param array<string, mixed> $data Post data
 */
function kb_insert_post(array $data): int|false
{
    // TODO: Implement in Phase 1 (Content system)
    return false;
}

/**
 * Update an existing post.
 *
 * @param array<string, mixed> $data Fields to update
 */
function kb_update_post(int $id, array $data): bool
{
    // TODO: Implement in Phase 1 (Content system)
    return false;
}

/**
 * Delete a post.
 */
function kb_delete_post(int $id, bool $force = false): bool
{
    // TODO: Implement in Phase 1 (Content system)
    return false;
}

// ---------------------------------------------------------------
// Users & Auth
// ---------------------------------------------------------------

/**
 * Check if the current visitor is logged in.
 */
function kb_is_logged_in(): bool
{
    return kb_app()->auth()->isLoggedIn();
}

/**
 * Get the current logged-in user's ID. Returns 0 if not logged in.
 */
function kb_current_user_id(): int
{
    return kb_app()->auth()->currentUserId();
}

/**
 * Get the current logged-in user object. Returns null if not logged in.
 */
function kb_current_user(): ?object
{
    return kb_app()->auth()->currentUser();
}

/**
 * Check if the current user has a specific capability.
 */
function kb_current_user_can(string $capability): bool
{
    return kb_app()->auth()->currentUserCan($capability);
}

// ---------------------------------------------------------------
// Security
// ---------------------------------------------------------------

/**
 * Sanitize a text string (strip all HTML, trim whitespace).
 */
function kb_sanitize_text(string $input): string
{
    // Remove script/style tags AND their contents before stripping remaining tags
    $input = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $input);
    return trim(strip_tags($input));
}

/**
 * Sanitize HTML (allow safe tags, strip dangerous ones).
 */
function kb_sanitize_html(string $input): string
{
    $allowed = '<p><br><strong><b><em><i><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6>'
             . '<blockquote><code><pre><img><figure><figcaption><table><thead><tbody>'
             . '<tr><th><td><span><div><hr><sub><sup>';
    return strip_tags($input, $allowed);
}

/**
 * Sanitize a URL.
 */
function kb_sanitize_url(string $input): string
{
    $url = filter_var(trim($input), FILTER_SANITIZE_URL);
    if ($url === false || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    // Only allow http and https schemes
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

/**
 * Sanitize an email address.
 */
function kb_sanitize_email(string $input): string
{
    $email = filter_var(trim($input), FILTER_SANITIZE_EMAIL);
    if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return $email;
}

/**
 * Generate a CSRF nonce token for a specific action.
 */
function kb_nonce_create(string $action): string
{
    // TODO: Implement in Phase 1 (Security)
    return '';
}

/**
 * Verify a CSRF nonce token.
 */
function kb_nonce_verify(string $token, string $action): bool
{
    // TODO: Implement in Phase 1 (Security)
    return false;
}

/**
 * Output a hidden nonce field for forms.
 */
function kb_nonce_field(string $action): string
{
    $token = kb_nonce_create($action);
    return '<input type="hidden" name="_kb_nonce" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// ---------------------------------------------------------------
// Cache
// ---------------------------------------------------------------

/**
 * Get a value from the object cache.
 */
function kb_cache_get(string $key): mixed
{
    return kb_app()->cache()->get($key);
}

/**
 * Set a value in the object cache.
 */
function kb_cache_set(string $key, mixed $value, int $ttl = 3600): void
{
    kb_app()->cache()->set($key, $value, $ttl);
}

/**
 * Delete a value from the object cache.
 */
function kb_cache_delete(string $key): void
{
    kb_app()->cache()->delete($key);
}

// ---------------------------------------------------------------
// AI Bridge
// ---------------------------------------------------------------

/**
 * Generate AI content through the configured provider.
 *
 * @param array<string, mixed> $config Task configuration
 */
function kb_ai_generate(array $config): object
{
    // TODO: Implement in Phase 3 (AI module)
    return (object) ['success' => false, 'text' => '', 'error' => 'AI module not initialized'];
}

// ---------------------------------------------------------------
// Email
// ---------------------------------------------------------------

/**
 * Send an email through the email system.
 *
 * @param array<string, string> $headers Optional additional headers
 */
function kb_send_email(string $to, string $subject, string $body, array $headers = []): bool
{
    // TODO: Implement in Phase 3 (Email module)
    return false;
}

// ---------------------------------------------------------------
// Misc utilities
// ---------------------------------------------------------------

/**
 * Redirect to a URL and exit.
 */
function kb_redirect(string $url, int $status = 302): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Abort with an HTTP error status.
 */
function kb_abort(int $code, string $message = ''): never
{
    http_response_code($code);
    if ($message !== '') {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

/**
 * Check if the current environment is debug mode.
 */
function kb_is_debug(): bool
{
    return (bool) kb_app()->config()->get('debug', false);
}
