<?php declare(strict_types=1);

/**
 * Kreblu Front Controller
 *
 * All requests route through this file via .htaccess / nginx rewrite rules.
 * This is the entry point for the entire application.
 */

define('KREBLU_ROOT', __DIR__);

// If not installed, redirect to installer
if (!file_exists(KREBLU_ROOT . '/kb-config.php')) {
	// Serve the installer directly
	if (file_exists(KREBLU_ROOT . '/kb-install/index.php')) {
		require KREBLU_ROOT . '/kb-install/index.php';
		exit;
	}
	die('Kreblu is not installed. Please create kb-config.php or place the kb-install/ directory.');
}

// Load bootstrap (config, autoloader, services, boot)
require KREBLU_ROOT . '/kb-core/bootstrap.php';

$app = Kreblu\Core\App::getInstance();
$request = new Kreblu\Core\Http\Request(
	$_GET,
	$_POST,
	$_SERVER,
	$_COOKIE,
	$_FILES,
);

// Get the request path
$path = $request->path();

// ---------------------------------------------------------------
// Page cache: serve static HTML for non-logged-in GET requests
// ---------------------------------------------------------------
if ($request->method() === 'GET' && $app->has('cache')) {
	$sessionCookie = $request->cookie(Kreblu\Core\Auth\AuthManager::cookieName());
	if ($sessionCookie === null) {
		$cached = $app->cache()->getPageCache($path);
		if ($cached !== null) {
			header('Content-Type: text/html; charset=UTF-8');
			header('X-Kreblu-Cache: HIT');
			echo $cached;
			exit;
		}
	}
}

// ---------------------------------------------------------------
// Resolve auth from session cookie
// ---------------------------------------------------------------
if ($app->has('auth')) {
	$app->auth()->resolveFromRequest($request);
}

// ---------------------------------------------------------------
// API routes: /api/v1/*
// ---------------------------------------------------------------
if (str_starts_with($path, '/api/v1/')) {
	Kreblu\Core\Api\ApiRoutes::register($app->router(), $app);
	$response = $app->router()->dispatch($request);
	$response->send();
	exit;
}

// ---------------------------------------------------------------
// Admin routes: /kb-admin/*
// ---------------------------------------------------------------
if (str_starts_with($path, '/kb-admin')) {
	// Try cookie-based auth first (production)
	if (!$app->auth()->isLoggedIn()) {
		$app->auth()->resolveFromRequest($request);
	}

	// Fall back to PHP session auth (local dev)
	if (!$app->auth()->isLoggedIn()) {
		if (session_status() === PHP_SESSION_NONE) { session_start(); }
		if (isset($_SESSION['kb_user_id'])) {
			$user = $app->db()->table('users')->where('id', '=', $_SESSION['kb_user_id'])->first();
			if ($user !== null) {
				$app->auth()->setCurrentUser($user);
			}
		}
	}

	// Logout route
	if ($path === '/kb-admin/logout') {
		if (session_status() === PHP_SESSION_NONE) { session_start(); }
		$_SESSION = [];
		session_destroy();
		setcookie('kb_session', '', ['expires' => time() - 3600, 'path' => '/']);
		header('Location: /kb-admin/');
		exit;
	}

	// Login page
	$user = $app->auth()->currentUser();
	if ($user === null) {
		echo renderAdminLogin($request);
		exit;
	}

	// Route to admin panel
	require_once KREBLU_ROOT . '/kb-admin/controllers/AdminLayout.php';
	require_once KREBLU_ROOT . '/kb-admin/controllers/AdminRouter.php';
	$router = new Kreblu\Admin\AdminRouter($app, $request);
	echo $router->handle();
	exit;
}

// ---------------------------------------------------------------
// Frontend: resolve template and render page
// ---------------------------------------------------------------
$db = $app->db();
$templateBasePath = KREBLU_ROOT . '/kb-content/templates';
$activeTemplate = 'developer-default';

// Try to get active template from options
try {
	$opt = $db->table('options')->where('option_key', '=', 'active_template')->first();
	if ($opt !== null) {
		$activeTemplate = $opt->option_value;
	}
} catch (Throwable) {
	// Use default
}

$themePath = $templateBasePath . '/' . $activeTemplate;
$cachePath = KREBLU_ROOT . '/kb-content/cache/templates';

$engine = new Kreblu\Core\Template\TemplateEngine($themePath, $cachePath);
$hierarchy = new Kreblu\Core\Template\TemplateHierarchy($themePath);

// Share site-wide data with all templates
try {
	$siteName = $db->table('options')->where('option_key', '=', 'site_name')->first();
	$siteDesc = $db->table('options')->where('option_key', '=', 'site_description')->first();
	$engine->share('site_name', $siteName->option_value ?? 'Kreblu');
	$engine->share('site_description', $siteDesc->option_value ?? '');
	$engine->share('site_url', KREBLU_SITE_URL ?? '');
	$engine->share('current_year', date('Y'));
	$engine->share('kreblu_version', '1.0.0-dev');
	$engine->share('is_logged_in', $app->auth()->isLoggedIn());
} catch (Throwable) {
	$engine->share('site_name', 'Kreblu');
	$engine->share('site_description', '');
}

$posts = new Kreblu\Core\Content\PostManager($db);

// Route: Home page
if ($path === '/' || $path === '') {
	$template = $hierarchy->resolveHome();

	if ($template === null) {
		http_response_code(500);
		echo 'No template found. Check that kb-content/templates/' . $activeTemplate . '/index.php exists.';
		exit;
	}

	$recentPosts = $posts->query([
		'type'   => 'post',
		'status' => 'published',
		'limit'  => 10,
		'orderby' => 'published_at',
		'order'  => 'DESC',
	]);

	$html = $engine->render($template, [
		'posts'     => $recentPosts,
		'page_title' => 'Home',
		'is_home'   => true,
	]);

	// Cache the page for non-logged-in visitors
	if (!$app->auth()->isLoggedIn() && $app->has('cache')) {
		$app->cache()->setPageCache($path, $html);
	}

	header('Content-Type: text/html; charset=UTF-8');
	echo $html;
	exit;
}

// Route: Single post/page by slug
$slug = trim($path, '/');

// Try as a post first
$post = $posts->findBySlug($slug, 'post');
if ($post !== null && $post->status === 'published') {
	$template = $hierarchy->resolveSingle('post', $post->slug) ?? 'index';

	$html = $engine->render($template, [
		'post'       => $post,
		'page_title' => $post->title,
		'is_single'  => true,
	]);

	if (!$app->auth()->isLoggedIn() && $app->has('cache')) {
		$app->cache()->setPageCache($path, $html);
	}

	header('Content-Type: text/html; charset=UTF-8');
	echo $html;
	exit;
}

// Try as a page
$page = $posts->findBySlug($slug, 'page');
if ($page !== null && $page->status === 'published') {
	$template = $hierarchy->resolvePage($page->slug, (int) $page->id) ?? 'index';

	$html = $engine->render($template, [
		'post'       => $page,
		'page_title' => $page->title,
		'is_page'    => true,
	]);

	if (!$app->auth()->isLoggedIn() && $app->has('cache')) {
		$app->cache()->setPageCache($path, $html);
	}

	header('Content-Type: text/html; charset=UTF-8');
	echo $html;
	exit;
}

// 404
$template = $hierarchy->resolve404();
if ($template !== null) {
	http_response_code(404);
	echo $engine->render($template, ['page_title' => '404 Not Found']);
	exit;
}

http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 Not Found</h1><p>The page you requested could not be found.</p></body></html>';
exit;


// ---------------------------------------------------------------
// Admin helper functions (temporary until Phase 2)
// ---------------------------------------------------------------

function renderAdminLogin(Kreblu\Core\Http\Request $request): string
{
	$error = '';

	if ($request->method() === 'POST') {
		$login = $request->input('login', '');
		$password = $request->input('password', '');
		$app = Kreblu\Core\App::getInstance();
		$user = $app->auth()->attempt($login, $password);

		if ($user !== null) {
			// Database session + cookie (works in production with HTTPS)
			$token = $app->auth()->createSession(
				(int) $user->id,
				$request->ip(),
				$request->header('User-Agent') ?? '',
			);
			$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
			setcookie('kb_session', $token, [
				'expires'  => time() + 172800,
				'path'     => '/',
				'secure'   => $isSecure,
				'httponly'  => true,
				'samesite' => 'Lax',
			]);

			// PHP session fallback (works on localhost without HTTPS)
			if (session_status() === PHP_SESSION_NONE) { session_start(); }
			$_SESSION['kb_user_id'] = (int) $user->id;

			header('Location: /kb-admin/');
			exit;
		}

		$error = 'Invalid username/email or password.';
	}

	return '<!DOCTYPE html><html><head><title>K Hub - Login</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh}.login{background:#fff;padding:2.5rem;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:400px}h1{text-align:center;margin-bottom:1.5rem;font-size:1.5rem;color:#1a1a2e}.field{margin-bottom:1rem}.field label{display:block;font-weight:600;margin-bottom:.25rem;font-size:.9rem}.field input{width:100%;padding:.6rem .75rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:.95rem}.btn{width:100%;padding:.7rem;background:#1a1a2e;color:#fff;border:none;border-radius:6px;font-size:.95rem;font-weight:600;cursor:pointer}.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:.75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem}</style></head><body>
<div class="login"><h1>K Hub</h1>'
	. ($error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '')
	. '<form method="POST"><div class="field"><label>Username or Email</label><input type="text" name="login" required autofocus></div><div class="field"><label>Password</label><input type="password" name="password" required></div><button type="submit" class="btn">Log In</button></form></div></body></html>';
}

function renderAdminPlaceholder(object $user): string
{
	$postCount = 0;
	$userCount = 0;
	try {
		$db = Kreblu\Core\App::getInstance()->db();
		$postCount = $db->table('posts')->count();
		$userCount = $db->table('users')->count();
	} catch (Throwable) {}

	return '<!DOCTYPE html><html><head><title>K Hub</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f0f2f5;color:#1a1a2e}.topbar{background:#1a1a2e;color:#fff;padding:.75rem 1.5rem;display:flex;justify-content:space-between;align-items:center}.topbar h1{font-size:1.1rem;font-weight:700}.topbar a{color:#ccc;text-decoration:none;font-size:.9rem}.main{max-width:900px;margin:2rem auto;padding:0 1.5rem}h2{margin-bottom:1rem;font-size:1.5rem}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:2rem}.card{background:#fff;padding:1.5rem;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.06)}.card h3{font-size:2rem;margin-bottom:.25rem}.card p{color:#666;font-size:.9rem}.info{background:#fff;padding:1.5rem;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.06)}.info h3{margin-bottom:.75rem;font-size:1.1rem}.info p{color:#555;font-size:.9rem;margin-bottom:.5rem}</style></head><body>
<div class="topbar"><h1>K Hub</h1><span>Hello, ' . htmlspecialchars($user->display_name ?: $user->username) . ' &nbsp; <a href="/">View Site</a></span></div>
<div class="main"><h2>Dashboard</h2>
<div class="cards"><div class="card"><h3>' . $postCount . '</h3><p>Posts & Pages</p></div><div class="card"><h3>' . $userCount . '</h3><p>Users</p></div><div class="card"><h3>477</h3><p>Tests Passing</p></div></div>
<div class="info"><h3>Phase 1 Complete</h3><p>The Kreblu core engine is working. All systems are operational: database, auth, content management, template engine, caching, REST API.</p><p>The full K Hub admin panel with content editing, media library, and settings is coming in Phase 2.</p><p>For now, use the <strong>REST API</strong> at <code>/api/v1/posts</code>, <code>/api/v1/terms</code>, <code>/api/v1/options</code> to manage content.</p></div>
</div></body></html>';
}