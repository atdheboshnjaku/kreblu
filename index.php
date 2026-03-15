<?php declare(strict_types=1);

/**
 * Kreblu Front Controller
 *
 * Every request to the site comes through this file.
 * Apache/Nginx rewrite rules direct all non-file URLs here.
 */

// Define the root path (used everywhere)
define('KREBLU_ROOT', __DIR__);

// Bootstrap the application
require KREBLU_ROOT . '/os-core/bootstrap.php';

// If not installed, redirect to installer
if (!KREBLU_INSTALLED) {
    header('Location: /os-install/');
    exit;
}

// Get the application instance
$app = KREBLU\Core\App::getInstance();

// Create request from PHP globals
$request = KREBLU\Core\Http\Request::fromGlobals();

// Check page cache first (only for GET requests from non-logged-in visitors)
if ($request->isGet() && !$app->auth()->isLoggedIn()) {
    $cached = $app->cache()->getPageCache($request->path());
    if ($cached !== null) {
        header('X-KREBLU-Cache: HIT');
        echo $cached;
        exit;
    }
}

// Route the request
$response = $app->router()->dispatch($request);

// Apply output filters (plugins can modify the final HTML)
if ($response->isHtml()) {
    $html = $response->getBody();
    $html = $app->hooks()->applyFilters('after_render', $html, $request);
    $response->setBody($html);

    // Write page cache (only for public GET requests that returned 200)
    if ($request->isGet() && !$app->auth()->isLoggedIn() && $response->getStatusCode() === 200) {
        $app->cache()->setPageCache($request->path(), $response->getBody());
    }
}

// Send the response
$response->send();

// Fire shutdown hook (logging, cleanup, queued emails)
$app->hooks()->doAction('shutdown');
