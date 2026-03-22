<?php declare(strict_types=1);

namespace Kreblu\Core\Auth;

use Kreblu\Core\Database\Connection;
use Kreblu\Core\Http\Request;

/**
 * Authentication Manager
 *
 * Handles login, logout, session creation/validation, and current user resolution.
 * Sessions are stored in the kb_sessions database table with hashed tokens.
 * The session token is stored in a secure HTTP-only cookie on the client.
 *
 * Password hashing uses bcrypt with a configurable cost factor.
 * Login attempts are tracked externally by the RateLimit class.
 */
final class AuthManager
{
	private const COOKIE_NAME = 'kb_session';
	private const TOKEN_LENGTH = 64;

	private ?object $currentUser = null;
	private bool $resolved = false;

	public function __construct(
		private readonly Connection $db,
		private readonly int $sessionLifetime = 172800, // 48 hours in seconds
		private readonly int $bcryptCost = 12,
	) {}

	// ---------------------------------------------------------------
	// Login / Logout
	// ---------------------------------------------------------------

	/**
	 * Attempt to log in with email/username and password.
	 *
	 * Returns the user object on success, or null on failure.
	 * Does NOT set the session cookie — call createSession() after this.
	 */
	public function attempt(string $login, string $password): ?object
	{
		// Look up user by email or username
		$user = $this->db->table('users')
			->where('email', '=', $login)
			->first();

		if ($user === null) {
			$user = $this->db->table('users')
				->where('username', '=', $login)
				->first();
		}

		if ($user === null) {
			return null;
		}

		// Check account is active
		if (($user->status ?? '') !== 'active') {
			return null;
		}

		// Verify password
		if (!password_verify($password, $user->password_hash)) {
			return null;
		}

		// Check if bcrypt cost needs upgrading
		if (password_needs_rehash($user->password_hash, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost])) {
			$newHash = $this->hashPassword($password);
			$this->db->table('users')
				->where('id', '=', $user->id)
				->update(['password_hash' => $newHash]);
		}

		// Update last login timestamp
		$this->db->table('users')
			->where('id', '=', $user->id)
			->update(['last_login' => date('Y-m-d H:i:s')]);

		return $user;
	}

	/**
	 * Create a session for an authenticated user.
	 *
	 * Returns the raw session token that should be stored in a cookie.
	 * The database stores a hash of this token, not the token itself.
	 */
	public function createSession(int $userId, string $ipAddress = '', string $userAgent = ''): string
	{
		// Generate a cryptographically secure random token
		$token = bin2hex(random_bytes(self::TOKEN_LENGTH));
		$tokenHash = hash('sha256', $token);

		$expiresAt = date('Y-m-d H:i:s', time() + $this->sessionLifetime);

		$this->db->table('sessions')->insert([
			'user_id'    => $userId,
			'token_hash' => $tokenHash,
			'ip_address' => $ipAddress,
			'user_agent' => mb_substr($userAgent, 0, 500),
			'expires_at' => $expiresAt,
		]);

		// Set the resolved user
		$this->currentUser = $this->db->table('users')
			->where('id', '=', $userId)
			->first();
		$this->resolved = true;

		return $token;
	}

	/**
	 * Destroy the current session (logout).
	 */
	public function destroySession(string $token): void
	{
		$tokenHash = hash('sha256', $token);

		$this->db->table('sessions')
			->where('token_hash', '=', $tokenHash)
			->delete();

		$this->currentUser = null;
		$this->resolved = true;
	}

	/**
	 * Destroy all sessions for a user (force logout everywhere).
	 */
	public function destroyAllSessions(int $userId): void
	{
		$this->db->table('sessions')
			->where('user_id', '=', $userId)
			->delete();

		if ($this->currentUser !== null && $this->currentUser->id === $userId) {
			$this->currentUser = null;
		}
	}

	// ---------------------------------------------------------------
	// Session validation
	// ---------------------------------------------------------------

	/**
	 * Validate a session token and resolve the current user.
	 *
	 * Call this once per request (typically in bootstrap or middleware).
	 * Returns the user object if the session is valid, null otherwise.
	 */
	public function validateSession(string $token): ?object
	{
		if ($token === '') {
			$this->currentUser = null;
			$this->resolved = true;
			return null;
		}

		$tokenHash = hash('sha256', $token);

		$session = $this->db->table('sessions')
			->where('token_hash', '=', $tokenHash)
			->where('expires_at', '>', date('Y-m-d H:i:s'))
			->first();

		if ($session === null) {
			$this->currentUser = null;
			$this->resolved = true;
			return null;
		}

		$user = $this->db->table('users')
			->where('id', '=', $session->user_id)
			->where('status', '=', 'active')
			->first();

		$this->currentUser = $user;
		$this->resolved = true;

		return $user;
	}

	/**
	 * Resolve the current user from a Request object.
	 *
	 * Reads the session cookie from the request and validates it.
	 */
	public function resolveFromRequest(Request $request): ?object
	{
		$token = $request->cookie(self::COOKIE_NAME);

		if ($token === null || !is_string($token)) {
			$this->currentUser = null;
			$this->resolved = true;
			return null;
		}

		return $this->validateSession($token);
	}

	// ---------------------------------------------------------------
	// Current user
	// ---------------------------------------------------------------

	/**
	 * Check if a user is currently logged in.
	 */
	public function isLoggedIn(): bool
	{
		return $this->currentUser() !== null;
	}

	/**
	 * Get the current logged-in user object, or null.
	 */
	public function currentUser(): ?object
	{
		return $this->currentUser;
	}

	/**
	 * Get the current logged-in user's ID. Returns 0 if not logged in.
	 */
	public function currentUserId(): int
	{
		return (int) ($this->currentUser?->id ?? 0);
	}

	/**
	 * Check if the current user has a specific capability.
	 */
	public function currentUserCan(string $capability): bool
	{
		if ($this->currentUser === null) {
			return false;
		}

		$role = $this->currentUser->role ?? 'subscriber';
		return RoleManager::roleHasCapability($role, $capability);
	}

	/**
	 * Set the current user directly (used in testing and CLI).
	 */
	public function setCurrentUser(?object $user): void
	{
		$this->currentUser = $user;
		$this->resolved = true;
	}

	// ---------------------------------------------------------------
	// Password hashing
	// ---------------------------------------------------------------

	/**
	 * Hash a password using bcrypt.
	 */
	public function hashPassword(string $password): string
	{
		$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);

		if ($hash === false) {
			throw new \RuntimeException('Password hashing failed.');
		}

		return $hash;
	}

	/**
	 * Verify a password against a hash.
	 */
	public function verifyPassword(string $password, string $hash): bool
	{
		return password_verify($password, $hash);
	}

	// ---------------------------------------------------------------
	// Session cleanup
	// ---------------------------------------------------------------

	/**
	 * Remove all expired sessions from the database.
	 * Should be called periodically via cron.
	 *
	 * @return int Number of expired sessions removed
	 */
	public function cleanExpiredSessions(): int
	{
		return $this->db->table('sessions')
			->where('expires_at', '<', date('Y-m-d H:i:s'))
			->delete();
	}

	/**
	 * Get all active sessions for a user.
	 *
	 * @return array<int, object>
	 */
	public function getUserSessions(int $userId): array
	{
		return $this->db->table('sessions')
			->where('user_id', '=', $userId)
			->where('expires_at', '>', date('Y-m-d H:i:s'))
			->orderBy('created_at', 'DESC')
			->get();
	}

	// ---------------------------------------------------------------
	// Cookie helpers
	// ---------------------------------------------------------------

	/**
	 * Get the cookie name used for sessions.
	 */
	public static function cookieName(): string
	{
		return self::COOKIE_NAME;
	}

	/**
	 * Get the cookie parameters for setting the session cookie.
	 *
	 * @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}
	 */
	public function cookieParams(): array
	{
		return [
			'expires'  => time() + $this->sessionLifetime,
			'path'     => '/',
			'secure'   => true,
			'httponly'  => true,
			'samesite' => 'Lax',
		];
	}
}
