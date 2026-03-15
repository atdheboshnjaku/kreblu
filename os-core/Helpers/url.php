<?php declare(strict_types=1);

/**
 * Kreblu URL Helpers
 *
 * URL generation, permalink building, asset URL management.
 */

/**
 * Get the site's base URL (no trailing slash).
 */
function os_site_url(string $path = ''): string
{
    $base = rtrim((string) os_app()->config()->get('site_url', ''), '/');
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Get the URL to the admin panel.
 */
function os_admin_url(string $path = ''): string
{
    return os_site_url('os-admin/' . ltrim($path, '/'));
}

/**
 * Get the URL to the REST API.
 */
function os_api_url(string $path = ''): string
{
    return os_site_url('api/v1/' . ltrim($path, '/'));
}

/**
 * Get the URL to a theme asset file with cache-busting.
 */
function os_asset(string $path): string
{
    $fullPath = KREBLU_ROOT . '/os-content/themes/' . $path;
    $version = file_exists($fullPath) ? substr(md5_file($fullPath) ?: '', 0, 8) : KREBLU_VERSION;
    return os_site_url('os-content/themes/' . $path) . '?v=' . $version;
}

/**
 * Get the URL to an admin asset file.
 */
function os_admin_asset(string $path): string
{
    $fullPath = KREBLU_ROOT . '/os-admin/assets/' . $path;
    $version = file_exists($fullPath) ? substr(md5_file($fullPath) ?: '', 0, 8) : KREBLU_VERSION;
    return os_site_url('os-admin/assets/' . $path) . '?v=' . $version;
}

/**
 * Get the URL to an uploaded media file.
 */
function os_upload_url(string $path): string
{
    return os_site_url('os-content/uploads/' . ltrim($path, '/'));
}

/**
 * Get the URL to a post by ID.
 */
function os_post_url(int $id): string
{
    $post = os_get_post($id);
    if ($post === null) {
        return '';
    }
    // TODO: Use permalink structure from settings
    return os_site_url($post->slug ?? '');
}

/**
 * Get the URL to a media file by ID.
 */
function os_media_url(int $id): string
{
    // TODO: Implement with media manager lookup
    return '';
}

/**
 * Get the current request path (without query string).
 */
function os_current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return $path ?: '/';
}

/**
 * Check if the current URL matches a given path pattern.
 */
function os_is_path(string $pattern): bool
{
    $current = os_current_path();
    // Simple wildcard matching
    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool) preg_match($regex, $current);
}

/**
 * Get the current locale string (e.g., 'en_US').
 */
function os_locale(): string
{
    // TODO: Implement with i18n module
    return os_get_option('site_locale', 'en_US') ?: 'en_US';
}
