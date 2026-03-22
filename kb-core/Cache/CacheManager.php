<?php declare(strict_types=1);

namespace Kreblu\Core\Cache;

/**
 * Cache Manager
 *
 * Two-layer caching system:
 *
 * 1. Page Cache: Saves fully rendered HTML pages as static files.
 *    On subsequent requests for the same URL (by non-logged-in visitors),
 *    the static file is served directly from bootstrap.php before any
 *    database queries or PHP processing. This is the main performance win.
 *
 * 2. Object Cache: In-memory key-value store for database query results,
 *    option lookups, and other computed data. Falls back to file-based
 *    storage for persistence across requests. Optional Redis/Memcached
 *    drivers can replace the file backend for high-traffic sites.
 *
 * Cache invalidation happens automatically when content changes
 * (posts saved, options updated, etc.) via the hook system.
 */
final class CacheManager
{
	/** @var array<string, mixed> In-memory object cache (lives for one request) */
	private array $memoryCache = [];

	private readonly string $pageCachePath;
	private readonly string $objectCachePath;

	public function __construct(
		string $cachePath,
		private readonly bool $pageCacheEnabled = true,
		private readonly int $objectCacheTtl = 3600,
	) {
		$this->pageCachePath = rtrim($cachePath, '/') . '/pages';
		$this->objectCachePath = rtrim($cachePath, '/') . '/objects';

		if (!is_dir($this->pageCachePath)) {
			@mkdir($this->pageCachePath, 0755, true);
		}

		if (!is_dir($this->objectCachePath)) {
			@mkdir($this->objectCachePath, 0755, true);
		}
	}

	// ---------------------------------------------------------------
	// Page Cache
	// ---------------------------------------------------------------

	/**
	 * Get a cached page by its URL path.
	 *
	 * Returns the cached HTML string, or null if no valid cache exists.
	 * This is called very early in the request lifecycle (bootstrap.php)
	 * before any database connection is made.
	 */
	public function getPageCache(string $path): ?string
	{
		if (!$this->pageCacheEnabled) {
			return null;
		}

		$file = $this->pageFilePath($path);

		if (!file_exists($file)) {
			return null;
		}

		// Check if cache file has expired (default: 1 hour)
		$mtime = filemtime($file);
		if ($mtime === false || (time() - $mtime) > 3600) {
			@unlink($file);
			return null;
		}

		$content = @file_get_contents($file);
		return $content !== false ? $content : null;
	}

	/**
	 * Store a rendered page in the cache.
	 *
	 * Only cache pages that should be cached:
	 * - GET requests only
	 * - Non-logged-in visitors only
	 * - 200 status code only
	 * - No query strings (or normalize them)
	 *
	 * The caller (index.php) is responsible for these checks.
	 */
	public function setPageCache(string $path, string $html): void
	{
		if (!$this->pageCacheEnabled) {
			return;
		}

		$file = $this->pageFilePath($path);
		$dir = dirname($file);

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		// Add a cache timestamp comment at the end of the HTML
		$timestamp = date('Y-m-d H:i:s');
		$html .= "\n<!-- Kreblu page cache: {$timestamp} -->";

		@file_put_contents($file, $html, LOCK_EX);
	}

	/**
	 * Delete a specific page from the cache.
	 */
	public function deletePageCache(string $path): void
	{
		$file = $this->pageFilePath($path);
		if (file_exists($file)) {
			@unlink($file);
		}
	}

	/**
	 * Clear the entire page cache.
	 *
	 * Called when global changes happen (theme switch, permalink change, etc.)
	 */
	public function clearPageCache(): int
	{
		return $this->deleteDirectory($this->pageCachePath);
	}

	/**
	 * Check if a page is cached.
	 */
	public function hasPageCache(string $path): bool
	{
		if (!$this->pageCacheEnabled) {
			return false;
		}

		$file = $this->pageFilePath($path);

		if (!file_exists($file)) {
			return false;
		}

		$mtime = filemtime($file);
		return $mtime !== false && (time() - $mtime) <= 3600;
	}

	/**
	 * Check if page caching is enabled.
	 */
	public function isPageCacheEnabled(): bool
	{
		return $this->pageCacheEnabled;
	}

	// ---------------------------------------------------------------
	// Object Cache
	// ---------------------------------------------------------------

	/**
	 * Get a value from the object cache.
	 *
	 * Checks in-memory first, then file cache.
	 */
	public function get(string $key): mixed
	{
		// Check memory cache first (fastest)
		if (array_key_exists($key, $this->memoryCache)) {
			return $this->memoryCache[$key];
		}

		// Check file cache
		$file = $this->objectFilePath($key);

		if (!file_exists($file)) {
			return null;
		}

		$content = @file_get_contents($file);
		if ($content === false) {
			return null;
		}

		$data = @unserialize($content);
		if ($data === false || !is_array($data)) {
			@unlink($file);
			return null;
		}

		// Check expiry
		if (isset($data['expires_at']) && $data['expires_at'] < time()) {
			@unlink($file);
			return null;
		}

		$value = $data['value'] ?? null;

		// Store in memory for subsequent reads in this request
		$this->memoryCache[$key] = $value;

		return $value;
	}

	/**
	 * Set a value in the object cache.
	 *
	 * @param string $key Cache key
	 * @param mixed $value Value to cache (must be serializable)
	 * @param int $ttl Time to live in seconds (0 = use default)
	 */
	public function set(string $key, mixed $value, int $ttl = 0): void
	{
		if ($ttl <= 0) {
			$ttl = $this->objectCacheTtl;
		}

		// Store in memory
		$this->memoryCache[$key] = $value;

		// Store in file
		$file = $this->objectFilePath($key);
		$dir = dirname($file);

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		$data = [
			'value'      => $value,
			'expires_at' => time() + $ttl,
			'created_at' => time(),
		];

		@file_put_contents($file, serialize($data), LOCK_EX);
	}

	/**
	 * Delete a value from the object cache.
	 */
	public function delete(string $key): void
	{
		unset($this->memoryCache[$key]);

		$file = $this->objectFilePath($key);
		if (file_exists($file)) {
			@unlink($file);
		}
	}

	/**
	 * Check if a key exists in the object cache.
	 */
	public function has(string $key): bool
	{
		if (array_key_exists($key, $this->memoryCache)) {
			return true;
		}

		return $this->get($key) !== null;
	}

	/**
	 * Clear the entire object cache.
	 */
	public function clearObjectCache(): int
	{
		$this->memoryCache = [];
		return $this->deleteDirectory($this->objectCachePath);
	}

	/**
	 * Clear the in-memory cache only (file cache persists).
	 * Useful for long-running processes.
	 */
	public function clearMemoryCache(): void
	{
		$this->memoryCache = [];
	}

	/**
	 * Get or compute a value. If the key is cached, return it.
	 * If not, call the callback, cache the result, and return it.
	 *
	 * @param string $key Cache key
	 * @param callable(): mixed $callback Function that computes the value
	 * @param int $ttl Time to live in seconds
	 */
	public function remember(string $key, callable $callback, int $ttl = 0): mixed
	{
		$value = $this->get($key);

		if ($value !== null) {
			return $value;
		}

		$value = $callback();
		$this->set($key, $value, $ttl);

		return $value;
	}

	// ---------------------------------------------------------------
	// Cache invalidation helpers
	// ---------------------------------------------------------------

	/**
	 * Invalidate all caches related to a specific post.
	 *
	 * Clears the post's page cache plus any archive/home pages
	 * that might list the post.
	 */
	public function invalidatePost(string $postSlug): void
	{
		// Clear the specific post page
		$this->deletePageCache('/' . $postSlug);

		// Clear home page and common archive pages
		$this->deletePageCache('/');
		$this->deletePageCache('/blog');

		// Clear any object cache entries for this post
		$this->delete('post:' . $postSlug);
	}

	/**
	 * Invalidate all caches. Nuclear option.
	 *
	 * @return array{pages: int, objects: int} Count of cleared items
	 */
	public function clearAll(): array
	{
		return [
			'pages'   => $this->clearPageCache(),
			'objects' => $this->clearObjectCache(),
		];
	}

	// ---------------------------------------------------------------
	// Cleanup
	// ---------------------------------------------------------------

	/**
	 * Remove expired object cache files.
	 * Should be called periodically via cron.
	 */
	public function cleanup(): int
	{
		$cleaned = 0;
		$files = glob($this->objectCachePath . '/*.cache');

		if ($files === false) {
			return 0;
		}

		foreach ($files as $file) {
			$content = @file_get_contents($file);
			if ($content === false) {
				@unlink($file);
				$cleaned++;
				continue;
			}

			$data = @unserialize($content);
			if (!is_array($data) || !isset($data['expires_at']) || $data['expires_at'] < time()) {
				@unlink($file);
				$cleaned++;
			}
		}

		return $cleaned;
	}

	// ---------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------

	/**
	 * Get the file path for a page cache entry.
	 *
	 * Converts URL path to a directory structure:
	 * /blog/my-post => /pages/blog/my-post.html
	 * / => /pages/index.html
	 */
	private function pageFilePath(string $path): string
	{
		$path = trim($path, '/');

		if ($path === '') {
			return $this->pageCachePath . '/index.html';
		}

		// Sanitize path segments
		$segments = explode('/', $path);
		$safe = array_map(function (string $segment): string {
			return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $segment) ?? $segment;
		}, $segments);

		return $this->pageCachePath . '/' . implode('/', $safe) . '.html';
	}

	/**
	 * Get the file path for an object cache entry.
	 */
	private function objectFilePath(string $key): string
	{
		$hash = md5($key);
		return $this->objectCachePath . '/' . $hash . '.cache';
	}

	/**
	 * Recursively delete all files in a directory (but keep the directory).
	 *
	 * @return int Number of files deleted
	 */
	private function deleteDirectory(string $dir): int
	{
		if (!is_dir($dir)) {
			return 0;
		}

		$count = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $item) {
			if ($item->isFile()) {
				@unlink($item->getPathname());
				$count++;
			} elseif ($item->isDir()) {
				@rmdir($item->getPathname());
			}
		}

		return $count;
	}
}
