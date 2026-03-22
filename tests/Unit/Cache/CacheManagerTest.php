<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Cache;

use Kreblu\Core\Cache\CacheManager;
use Kreblu\Tests\TestCase;

/**
 * Tests for the Cache Manager.
 */
final class CacheManagerTest extends TestCase
{
	private string $cacheDir;
	private CacheManager $cache;

	protected function setUp(): void
	{
		parent::setUp();

		$this->cacheDir = sys_get_temp_dir() . '/kreblu_cache_test_' . uniqid();
		mkdir($this->cacheDir, 0755, true);

		$this->cache = new CacheManager($this->cacheDir);
	}

	protected function tearDown(): void
	{
		$this->recursiveDelete($this->cacheDir);
		parent::tearDown();
	}

	private function recursiveDelete(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir) ?: [];
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
		}
		@rmdir($dir);
	}

	// ---------------------------------------------------------------
	// Page Cache
	// ---------------------------------------------------------------

	public function test_page_cache_set_and_get(): void
	{
		$html = '<html><body>Hello World</body></html>';

		$this->cache->setPageCache('/about', $html);

		$cached = $this->cache->getPageCache('/about');
		$this->assertNotNull($cached);
		$this->assertStringContainsString('Hello World', $cached);
	}

	public function test_page_cache_includes_timestamp(): void
	{
		$this->cache->setPageCache('/test', '<p>Content</p>');

		$cached = $this->cache->getPageCache('/test');
		$this->assertStringContainsString('Kreblu page cache:', $cached);
	}

	public function test_page_cache_returns_null_when_missing(): void
	{
		$this->assertNull($this->cache->getPageCache('/nonexistent'));
	}

	public function test_page_cache_home_page(): void
	{
		$this->cache->setPageCache('/', '<html>Home</html>');

		$cached = $this->cache->getPageCache('/');
		$this->assertNotNull($cached);
		$this->assertStringContainsString('Home', $cached);
	}

	public function test_page_cache_nested_path(): void
	{
		$this->cache->setPageCache('/blog/2026/my-post', '<p>Post content</p>');

		$cached = $this->cache->getPageCache('/blog/2026/my-post');
		$this->assertNotNull($cached);
		$this->assertStringContainsString('Post content', $cached);
	}

	public function test_page_cache_has(): void
	{
		$this->assertFalse($this->cache->hasPageCache('/test'));

		$this->cache->setPageCache('/test', 'content');
		$this->assertTrue($this->cache->hasPageCache('/test'));
	}

	public function test_page_cache_delete(): void
	{
		$this->cache->setPageCache('/deleteme', 'content');
		$this->assertTrue($this->cache->hasPageCache('/deleteme'));

		$this->cache->deletePageCache('/deleteme');
		$this->assertFalse($this->cache->hasPageCache('/deleteme'));
	}

	public function test_page_cache_clear_all(): void
	{
		$this->cache->setPageCache('/page1', 'content1');
		$this->cache->setPageCache('/page2', 'content2');
		$this->cache->setPageCache('/blog/post', 'content3');

		$cleared = $this->cache->clearPageCache();
		$this->assertGreaterThan(0, $cleared);

		$this->assertNull($this->cache->getPageCache('/page1'));
		$this->assertNull($this->cache->getPageCache('/page2'));
		$this->assertNull($this->cache->getPageCache('/blog/post'));
	}

	public function test_page_cache_disabled(): void
	{
		$disabled = new CacheManager($this->cacheDir, pageCacheEnabled: false);

		$disabled->setPageCache('/test', 'content');
		$this->assertNull($disabled->getPageCache('/test'));
		$this->assertFalse($disabled->hasPageCache('/test'));
	}

	public function test_page_cache_enabled_check(): void
	{
		$this->assertTrue($this->cache->isPageCacheEnabled());

		$disabled = new CacheManager($this->cacheDir, pageCacheEnabled: false);
		$this->assertFalse($disabled->isPageCacheEnabled());
	}

	// ---------------------------------------------------------------
	// Object Cache
	// ---------------------------------------------------------------

	public function test_object_cache_set_and_get(): void
	{
		$this->cache->set('my_key', 'my_value');

		$this->assertEquals('my_value', $this->cache->get('my_key'));
	}

	public function test_object_cache_returns_null_when_missing(): void
	{
		$this->assertNull($this->cache->get('nonexistent'));
	}

	public function test_object_cache_stores_arrays(): void
	{
		$data = ['name' => 'Kreblu', 'version' => 1, 'features' => ['a', 'b']];
		$this->cache->set('config', $data);

		$this->assertEquals($data, $this->cache->get('config'));
	}

	public function test_object_cache_stores_integers(): void
	{
		$this->cache->set('count', 42);
		$this->assertEquals(42, $this->cache->get('count'));
	}

	public function test_object_cache_stores_booleans(): void
	{
		$this->cache->set('flag', true);
		$this->assertTrue($this->cache->get('flag'));
	}

	public function test_object_cache_has(): void
	{
		$this->assertFalse($this->cache->has('test'));

		$this->cache->set('test', 'value');
		$this->assertTrue($this->cache->has('test'));
	}

	public function test_object_cache_delete(): void
	{
		$this->cache->set('deleteme', 'value');
		$this->assertTrue($this->cache->has('deleteme'));

		$this->cache->delete('deleteme');
		$this->assertFalse($this->cache->has('deleteme'));
		$this->assertNull($this->cache->get('deleteme'));
	}

	public function test_object_cache_overwrite(): void
	{
		$this->cache->set('key', 'first');
		$this->cache->set('key', 'second');

		$this->assertEquals('second', $this->cache->get('key'));
	}

	public function test_object_cache_clear(): void
	{
		$this->cache->set('a', 1);
		$this->cache->set('b', 2);

		$cleared = $this->cache->clearObjectCache();
		$this->assertGreaterThanOrEqual(2, $cleared);

		$this->assertNull($this->cache->get('a'));
		$this->assertNull($this->cache->get('b'));
	}

	// ---------------------------------------------------------------
	// Memory cache
	// ---------------------------------------------------------------

	public function test_memory_cache_serves_without_file_read(): void
	{
		$this->cache->set('mem_key', 'mem_value');

		// Delete the file cache behind the scenes
		$files = glob($this->cacheDir . '/objects/*.cache');
		foreach ($files ?: [] as $file) {
			@unlink($file);
		}

		// Memory cache should still serve the value
		$this->assertEquals('mem_value', $this->cache->get('mem_key'));
	}

	public function test_clear_memory_cache(): void
	{
		$this->cache->set('mem_only', 'value');

		// Delete file so only memory has it
		$files = glob($this->cacheDir . '/objects/*.cache');
		foreach ($files ?: [] as $file) {
			@unlink($file);
		}

		$this->cache->clearMemoryCache();

		// Now both memory and file are gone
		$this->assertNull($this->cache->get('mem_only'));
	}

	// ---------------------------------------------------------------
	// File persistence
	// ---------------------------------------------------------------

	public function test_object_cache_persists_to_file(): void
	{
		$this->cache->set('persist_key', 'persist_value');

		// Create a new cache instance (fresh memory, reads from file)
		$freshCache = new CacheManager($this->cacheDir);

		$this->assertEquals('persist_value', $freshCache->get('persist_key'));
	}

	// ---------------------------------------------------------------
	// TTL / Expiry
	// ---------------------------------------------------------------

	public function test_object_cache_ttl_expiry(): void
	{
		$this->cache->set('short_lived', 'value', ttl: 1);

		// Should exist immediately
		$this->assertEquals('value', $this->cache->get('short_lived'));

		// Wait for expiry
		sleep(2);

		// Clear memory cache to force file read
		$this->cache->clearMemoryCache();

		// Should be expired
		$this->assertNull($this->cache->get('short_lived'));
	}

	// ---------------------------------------------------------------
	// Remember pattern
	// ---------------------------------------------------------------

	public function test_remember_caches_callback_result(): void
	{
		$callCount = 0;

		$result = $this->cache->remember('computed', function () use (&$callCount) {
			$callCount++;
			return 'expensive_result';
		});

		$this->assertEquals('expensive_result', $result);
		$this->assertEquals(1, $callCount);

		// Second call should use cache
		$result = $this->cache->remember('computed', function () use (&$callCount) {
			$callCount++;
			return 'should_not_reach';
		});

		$this->assertEquals('expensive_result', $result);
		$this->assertEquals(1, $callCount);
	}

	// ---------------------------------------------------------------
	// Invalidation
	// ---------------------------------------------------------------

	public function test_invalidate_post(): void
	{
		$this->cache->setPageCache('/hello-world', '<p>Post</p>');
		$this->cache->setPageCache('/', '<p>Home</p>');
		$this->cache->set('post:hello-world', ['title' => 'Hello']);

		$this->cache->invalidatePost('hello-world');

		$this->assertNull($this->cache->getPageCache('/hello-world'));
		$this->assertNull($this->cache->getPageCache('/'));
		$this->assertNull($this->cache->get('post:hello-world'));
	}

	public function test_clear_all(): void
	{
		$this->cache->setPageCache('/page', 'html');
		$this->cache->set('obj', 'data');

		$result = $this->cache->clearAll();

		$this->assertArrayHasKey('pages', $result);
		$this->assertArrayHasKey('objects', $result);
		$this->assertNull($this->cache->getPageCache('/page'));
		$this->assertNull($this->cache->get('obj'));
	}

	// ---------------------------------------------------------------
	// Cleanup
	// ---------------------------------------------------------------

	public function test_cleanup_removes_expired(): void
	{
		$this->cache->set('expired_item', 'value', ttl: 1);

		sleep(2);

		$cleaned = $this->cache->cleanup();
		$this->assertGreaterThan(0, $cleaned);
	}

	// ---------------------------------------------------------------
	// Directory creation
	// ---------------------------------------------------------------

	public function test_creates_cache_directories(): void
	{
		$newDir = sys_get_temp_dir() . '/kreblu_cache_new_' . uniqid();

		new CacheManager($newDir);

		$this->assertDirectoryExists($newDir . '/pages');
		$this->assertDirectoryExists($newDir . '/objects');

		$this->recursiveDelete($newDir);
	}
}
