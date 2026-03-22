<?php declare(strict_types=1);

namespace Kreblu\Core\Api;

use Kreblu\Core\Database\Connection;
use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;

/**
 * API Authentication
 *
 * Validates API requests using either:
 * 1. API key via Authorization: Bearer header (for external services)
 * 2. Session cookie (for admin panel AJAX requests)
 *
 * API keys are stored as SHA-256 hashes in kb_options.
 * Each key has scoped permissions: read, write, admin.
 */
final class ApiAuth
{
	public function __construct(
		private readonly Connection $db,
	) {}

	/**
	 * Authenticate an API request.
	 *
	 * Returns the authenticated user/key info, or null if unauthenticated.
	 *
	 * @return object|null Object with properties: user_id, scope (read|write|admin)
	 */
	public function authenticate(Request $request): ?object
	{
		// Try Bearer token first
		$token = $request->bearerToken();

		if ($token !== null) {
			return $this->authenticateApiKey($token);
		}

		return null;
	}

	/**
	 * Validate an API key.
	 */
	private function authenticateApiKey(string $key): ?object
	{
		$keyHash = hash('sha256', $key);

		$stored = $this->db->table('options')
			->where('option_key', 'LIKE', 'api_key_%')
			->get();

		foreach ($stored as $option) {
			$data = json_decode($option->option_value, true);

			if (!is_array($data) || !isset($data['key_hash'])) {
				continue;
			}

			if (hash_equals($data['key_hash'], $keyHash)) {
				// Check if key is active
				if (($data['active'] ?? true) === false) {
					return null;
				}

				return (object) [
					'user_id' => $data['user_id'] ?? 0,
					'scope'   => $data['scope'] ?? 'read',
					'name'    => $data['name'] ?? 'API Key',
				];
			}
		}

		return null;
	}

	/**
	 * Check if a scope has sufficient permissions for an action.
	 */
	public static function scopeAllows(string $scope, string $requiredScope): bool
	{
		$levels = ['read' => 1, 'write' => 2, 'admin' => 3];

		$has = $levels[$scope] ?? 0;
		$needs = $levels[$requiredScope] ?? 0;

		return $has >= $needs;
	}
}
