<?php declare(strict_types=1);

/**
 * Kreblu URL Helpers
 *
 * URL generation, permalink building, asset URL management.
 */

/**
 * Get the site's base URL (no trailing slash).
 */
function kb_site_url(string $path = ''): string
{
    $base = rtrim((string) kb_app()->config()->get('site_url', ''), '/');
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Get the URL to the admin panel.
 */
function kb_admin_url(string $path = ''): string
{
    return kb_site_url('kb-admin/' . ltrim($path, '/'));
}

/**
 * Get the URL to the REST API.
 */
function kb_api_url(string $path = ''): string
{
    return kb_site_url('api/v1/' . ltrim($path, '/'));
}

/**
 * Get the URL to a theme asset file with cache-busting.
 */
function kb_asset(string $path): string
{
    $fullPath = KREBLU_ROOT . '/kb-content/themes/' . $path;
    $version = file_exists($fullPath) ? substr(md5_file($fullPath) ?: '', 0, 8) : KREBLU_VERSION;
    return kb_site_url('kb-content/themes/' . $path) . '?v=' . $version;
}

/**
 * Get the URL to an admin asset file.
 */
function kb_admin_asset(string $path): string
{
    $fullPath = KREBLU_ROOT . '/kb-admin/assets/' . $path;
    $version = file_exists($fullPath) ? substr(md5_file($fullPath) ?: '', 0, 8) : KREBLU_VERSION;
    return kb_site_url('kb-admin/assets/' . $path) . '?v=' . $version;
}

/**
 * Get the URL to an uploaded media file.
 */
function kb_upload_url(string $path): string
{
    return kb_site_url('kb-content/uploads/' . ltrim($path, '/'));
}

/**
 * Get the URL to a post by ID.
 */
function kb_post_url(int $id): string
{
    $post = kb_get_post($id);
    if ($post === null) {
        return '';
    }
    // TODO: Use permalink structure from settings
    return kb_site_url($post->slug ?? '');
}

/**
 * Get the URL to a media file by ID.
 */
function kb_media_url(int $id): string
{
    // TODO: Implement with media manager lookup
    return '';
}

/**
 * Get the current request path (without query string).
 */
function kb_current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return $path ?: '/';
}

/**
 * Check if the current URL matches a given path pattern.
 */
function kb_is_path(string $pattern): bool
{
    $current = kb_current_path();
    // Simple wildcard matching
    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $current);
}

/**
 * Get the current locale string (e.g., 'en_US').
 */
function kb_locale(): string
{
    // TODO: Implement with i18n module
    return kb_get_option('site_locale', 'en_US') ?: 'en_US';
}
