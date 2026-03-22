<?php declare(strict_types=1);

namespace Kreblu\Core\Security;

/**
 * Nonce (CSRF Token) Manager
 *
 * Generates and verifies CSRF tokens to protect forms and API mutations
 * from cross-site request forgery attacks.
 *
 * Nonces are HMAC-based: computed from the action name, a time window,
 * and a secret key. No database storage needed — verification recomputes
 * the hash and compares.
 *
 * Each nonce is valid for a configurable time window (default 12 hours).
 * The previous time window is also accepted to handle boundary crossings.
 */
final class Nonce
{
	private const DEFAULT_LIFETIME = 43200; // 12 hours in seconds

	public function __construct(
		private readonly string $secretKey,
		private readonly int    $lifetime = self::DEFAULT_LIFETIME,
	) {
		if ($secretKey === '' || $secretKey === 'put-your-unique-phrase-here') {
			throw new \RuntimeException(
				'Security keys are not configured. Update kb-config.php with unique secret keys.'
			);
		}
	}

	/**
	 * Generate a nonce token for a specific action.
	 *
	 * @param string $action The action this nonce protects (e.g., 'save_post', 'delete_user')
	 * @return string The nonce token (hex string)
	 */
	public function create(string $action): string
	{
		$tick = $this->currentTick();
		return $this->computeHash($action, $tick);
	}

	/**
	 * Verify a nonce token.
	 *
	 * Accepts tokens from the current and previous time window.
	 *
	 * @param string $token The token to verify
	 * @param string $action The action this token should protect
	 * @return bool True if valid
	 */
	public function verify(string $token, string $action): bool
	{
		if ($token === '') {
			return false;
		}

		$tick = $this->currentTick();

		// Check current time window
		if (hash_equals($this->computeHash($action, $tick), $token)) {
			return true;
		}

		// Check previous time window
		if (hash_equals($this->computeHash($action, $tick - 1), $token)) {
			return true;
		}

		return false;
	}

	/**
	 * Generate a hidden HTML input field with a nonce token.
	 */
	public function field(string $action, string $fieldName = '_kb_nonce'): string
	{
		$token = $this->create($action);
		return sprintf(
			'<input type="hidden" name="%s" value="%s">',
			htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
		);
	}

	/**
	 * Get the current time window tick.
	 */
	private function currentTick(): int
	{
		return (int) ceil(time() / $this->lifetime);
	}

	/**
	 * Compute the HMAC hash for a nonce.
	 */
	private function computeHash(string $action, int $tick): string
	{
		$data = $action . '|' . $tick;
		return hash_hmac('sha256', $data, $this->secretKey);
	}
}
