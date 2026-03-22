<?php declare(strict_types=1);

namespace Kreblu\Core\Security;

/**
 * Rate Limiter
 *
 * Tracks request/attempt counts by a key (typically IP address or user ID).
 * Uses file-based storage so it works on any shared host without Redis.
 *
 * Supports progressive lockout: after exceeding the limit, the lockout
 * duration increases with each subsequent violation.
 *
 * Usage:
 *   $limiter = new RateLimit('/path/to/cache');
 *   if ($limiter->tooManyAttempts('login:' . $ip, 5, 900)) {
 *       // Blocked — too many attempts in 15 minutes
 *       $retryAfter = $limiter->retryAfter('login:' . $ip);
 *   }
 *   $limiter->hit('login:' . $ip);
 */
final class RateLimit
{
	public function __construct(
		private readonly string $storagePath,
	) {
		if (!is_dir($this->storagePath)) {
			@mkdir($this->storagePath, 0755, true);
		}
	}

	/**
	 * Record a hit (attempt) for a key.
	 *
	 * @param string $key Unique identifier (e.g., 'login:192.168.1.1')
	 * @param int $decaySeconds Time window in seconds before attempts reset
	 */
	public function hit(string $key, int $decaySeconds = 900): void
	{
		$data = $this->getData($key);
		$now = time();

		// Reset if the decay window has passed
		if ($data['expires_at'] < $now) {
			$data = [
				'attempts' => 0,
				'expires_at' => $now + $decaySeconds,
				'locked_until' => 0,
			];
		}

		$data['attempts']++;
		$this->saveData($key, $data);
	}

	/**
	 * Check if a key has exceeded the attempt limit.
	 *
	 * @param string $key Unique identifier
	 * @param int $maxAttempts Maximum allowed attempts
	 * @param int $decaySeconds Time window in seconds
	 * @return bool True if the limit has been exceeded
	 */
	public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds = 900): bool
	{
		$data = $this->getData($key);
		$now = time();

		// Check active lockout
		if ($data['locked_until'] > $now) {
			return true;
		}

		// Check if window expired — not limited
		if ($data['expires_at'] < $now) {
			return false;
		}

		return $data['attempts'] >= $maxAttempts;
	}

	/**
	 * Get the number of seconds until the key can retry.
	 *
	 * @return int Seconds until retry is allowed, 0 if not limited
	 */
	public function retryAfter(string $key): int
	{
		$data = $this->getData($key);
		$now = time();

		if ($data['locked_until'] > $now) {
			return $data['locked_until'] - $now;
		}

		if ($data['expires_at'] > $now) {
			return $data['expires_at'] - $now;
		}

		return 0;
	}

	/**
	 * Get the current attempt count for a key.
	 */
	public function attempts(string $key): int
	{
		$data = $this->getData($key);

		if ($data['expires_at'] < time()) {
			return 0;
		}

		return $data['attempts'];
	}

	/**
	 * Lock a key for a specified duration.
	 *
	 * Used for progressive lockout after repeated violations.
	 *
	 * @param string $key Unique identifier
	 * @param int $seconds Duration of lockout
	 */
	public function lock(string $key, int $seconds): void
	{
		$data = $this->getData($key);
		$data['locked_until'] = time() + $seconds;
		$this->saveData($key, $data);
	}

	/**
	 * Clear all attempts and lockout for a key.
	 */
	public function clear(string $key): void
	{
		$file = $this->getFilePath($key);
		if (file_exists($file)) {
			@unlink($file);
		}
	}

	/**
	 * Get the remaining attempts before the limit is reached.
	 */
	public function remainingAttempts(string $key, int $maxAttempts): int
	{
		$attempts = $this->attempts($key);
		return max(0, $maxAttempts - $attempts);
	}

	/**
	 * Get the stored data for a key.
	 *
	 * @return array{attempts: int, expires_at: int, locked_until: int}
	 */
	private function getData(string $key): array
	{
		$file = $this->getFilePath($key);

		if (!file_exists($file)) {
			return ['attempts' => 0, 'expires_at' => 0, 'locked_until' => 0];
		}

		$contents = @file_get_contents($file);
		if ($contents === false) {
			return ['attempts' => 0, 'expires_at' => 0, 'locked_until' => 0];
		}

		$data = @json_decode($contents, true);
		if (!is_array($data)) {
			return ['attempts' => 0, 'expires_at' => 0, 'locked_until' => 0];
		}

		return [
			'attempts'     => (int) ($data['attempts'] ?? 0),
			'expires_at'   => (int) ($data['expires_at'] ?? 0),
			'locked_until' => (int) ($data['locked_until'] ?? 0),
		];
	}

	/**
	 * Save data for a key.
	 *
	 * @param array{attempts: int, expires_at: int, locked_until: int} $data
	 */
	private function saveData(string $key, array $data): void
	{
		$file = $this->getFilePath($key);
		$json = json_encode($data, JSON_THROW_ON_ERROR);
		file_put_contents($file, $json, LOCK_EX);
	}

	/**
	 * Get the file path for a rate limit key.
	 *
	 * Keys are hashed to avoid filesystem issues with special characters.
	 */
	private function getFilePath(string $key): string
	{
		$hash = hash('sha256', $key);
		return $this->storagePath . '/ratelimit_' . $hash . '.json';
	}
}
