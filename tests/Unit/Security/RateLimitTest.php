<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Security;

use Kreblu\Core\Security\RateLimit;
use Kreblu\Tests\TestCase;

final class RateLimitTest extends TestCase
{
	private RateLimit $limiter;
	private string $storagePath;

	protected function setUp(): void
	{
		parent::setUp();
		$this->storagePath = sys_get_temp_dir() . '/kreblu_ratelimit_test_' . uniqid();
		@mkdir($this->storagePath, 0755, true);
		$this->limiter = new RateLimit($this->storagePath);
	}

	protected function tearDown(): void
	{
		// Clean up test files
		$files = glob($this->storagePath . '/ratelimit_*.json');
		if ($files !== false) {
			foreach ($files as $file) {
				@unlink($file);
			}
		}
		@rmdir($this->storagePath);
		parent::tearDown();
	}

	public function test_no_attempts_initially(): void
	{
		$this->assertEquals(0, $this->limiter->attempts('login:1.2.3.4'));
	}

	public function test_hit_increments_attempts(): void
	{
		$this->limiter->hit('login:1.2.3.4');
		$this->assertEquals(1, $this->limiter->attempts('login:1.2.3.4'));

		$this->limiter->hit('login:1.2.3.4');
		$this->assertEquals(2, $this->limiter->attempts('login:1.2.3.4'));
	}

	public function test_not_limited_under_threshold(): void
	{
		$this->limiter->hit('login:1.2.3.4');
		$this->limiter->hit('login:1.2.3.4');

		$this->assertFalse($this->limiter->tooManyAttempts('login:1.2.3.4', 5));
	}

	public function test_limited_at_threshold(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->limiter->hit('login:1.2.3.4');
		}

		$this->assertTrue($this->limiter->tooManyAttempts('login:1.2.3.4', 5));
	}

	public function test_different_keys_tracked_independently(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->limiter->hit('login:1.2.3.4');
		}

		$this->assertTrue($this->limiter->tooManyAttempts('login:1.2.3.4', 5));
		$this->assertFalse($this->limiter->tooManyAttempts('login:5.6.7.8', 5));
	}

	public function test_clear_resets_attempts(): void
	{
		for ($i = 0; $i < 5; $i++) {
			$this->limiter->hit('login:1.2.3.4');
		}

		$this->limiter->clear('login:1.2.3.4');

		$this->assertEquals(0, $this->limiter->attempts('login:1.2.3.4'));
		$this->assertFalse($this->limiter->tooManyAttempts('login:1.2.3.4', 5));
	}

	public function test_remaining_attempts(): void
	{
		$this->limiter->hit('login:1.2.3.4');
		$this->limiter->hit('login:1.2.3.4');

		$this->assertEquals(3, $this->limiter->remainingAttempts('login:1.2.3.4', 5));
	}

	public function test_remaining_attempts_never_negative(): void
	{
		for ($i = 0; $i < 10; $i++) {
			$this->limiter->hit('login:1.2.3.4');
		}

		$this->assertEquals(0, $this->limiter->remainingAttempts('login:1.2.3.4', 5));
	}

	public function test_lock_blocks_requests(): void
	{
		$this->limiter->lock('login:1.2.3.4', 60);

		$this->assertTrue($this->limiter->tooManyAttempts('login:1.2.3.4', 100));
	}

	public function test_retry_after_returns_seconds(): void
	{
		$this->limiter->lock('login:1.2.3.4', 120);

		$retryAfter = $this->limiter->retryAfter('login:1.2.3.4');
		$this->assertGreaterThan(0, $retryAfter);
		$this->assertLessThanOrEqual(120, $retryAfter);
	}

	public function test_retry_after_zero_when_not_limited(): void
	{
		$this->assertEquals(0, $this->limiter->retryAfter('login:1.2.3.4'));
	}

	public function test_storage_directory_created_if_missing(): void
	{
		$newPath = sys_get_temp_dir() . '/kreblu_rl_auto_' . uniqid();
		$limiter = new RateLimit($newPath);
		$limiter->hit('test');

		$this->assertTrue(is_dir($newPath));

		// Cleanup
		$files = glob($newPath . '/*');
		if ($files !== false) {
			foreach ($files as $f) {
				@unlink($f);
			}
		}
		@rmdir($newPath);
	}
}
