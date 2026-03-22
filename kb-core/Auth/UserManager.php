<?php declare(strict_types=1);

namespace Kreblu\Core\Auth;

use Kreblu\Core\Database\Connection;

/**
 * User Manager
 *
 * CRUD operations for user accounts. Handles creation with password
 * hashing, updates, deletion, and queries.
 */
final class UserManager
{
	public function __construct(
		private readonly Connection  $db,
		private readonly AuthManager $auth,
	) {}

	/**
	 * Create a new user account.
	 *
	 * @param array{email: string, username: string, password: string, display_name?: string, role?: string} $data
	 * @return int The new user's ID
	 * @throws \InvalidArgumentException If required fields are missing or invalid
	 */
	public function create(array $data): int
	{
		$this->validateCreateData($data);

		$email = strtolower(trim($data['email']));
		$username = trim($data['username']);

		// Check for duplicates
		if ($this->emailExists($email)) {
			throw new \InvalidArgumentException('A user with this email already exists.');
		}

		if ($this->usernameExists($username)) {
			throw new \InvalidArgumentException('A user with this username already exists.');
		}

		$role = $data['role'] ?? 'subscriber';
		if (!RoleManager::isValidRole($role)) {
			throw new \InvalidArgumentException("Invalid role: {$role}");
		}

		return $this->db->table('users')->insert([
			'email'         => $email,
			'username'      => $username,
			'password_hash' => $this->auth->hashPassword($data['password']),
			'display_name'  => $data['display_name'] ?? $username,
			'role'          => $role,
			'status'        => 'active',
		]);
	}

	/**
	 * Update a user's data.
	 *
	 * @param array<string, mixed> $data Fields to update
	 * @return bool True if the update affected a row
	 */
	public function update(int $id, array $data): bool
	{
		$allowed = ['email', 'username', 'display_name', 'role', 'status', 'meta', 'two_factor_secret'];
		$updateData = [];

		foreach ($allowed as $field) {
			if (array_key_exists($field, $data)) {
				$updateData[$field] = $data[$field];
			}
		}

		// Handle password separately (needs hashing)
		if (isset($data['password']) && $data['password'] !== '') {
			$updateData['password_hash'] = $this->auth->hashPassword($data['password']);
		}

		// Validate email uniqueness if changing
		if (isset($updateData['email'])) {
			$updateData['email'] = strtolower(trim($updateData['email']));
			$existing = $this->findByEmail($updateData['email']);
			if ($existing !== null && (int) $existing->id !== $id) {
				throw new \InvalidArgumentException('A user with this email already exists.');
			}
		}

		// Validate username uniqueness if changing
		if (isset($updateData['username'])) {
			$updateData['username'] = trim($updateData['username']);
			$existing = $this->findByUsername($updateData['username']);
			if ($existing !== null && (int) $existing->id !== $id) {
				throw new \InvalidArgumentException('A user with this username already exists.');
			}
		}

		// Validate role if changing
		if (isset($updateData['role']) && !RoleManager::isValidRole($updateData['role'])) {
			throw new \InvalidArgumentException("Invalid role: {$updateData['role']}");
		}

		if (empty($updateData)) {
			return false;
		}

		$affected = $this->db->table('users')
			->where('id', '=', $id)
			->update($updateData);

		return $affected > 0;
	}

	/**
	 * Delete a user account and all their sessions.
	 *
	 * Posts by this user are handled by database CASCADE rules.
	 */
	public function delete(int $id): bool
	{
		$affected = $this->db->table('users')
			->where('id', '=', $id)
			->delete();

		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Finders
	// ---------------------------------------------------------------

	/**
	 * Find a user by ID.
	 */
	public function findById(int $id): ?object
	{
		return $this->db->table('users')
			->where('id', '=', $id)
			->first();
	}

	/**
	 * Find a user by email address.
	 */
	public function findByEmail(string $email): ?object
	{
		return $this->db->table('users')
			->where('email', '=', strtolower(trim($email)))
			->first();
	}

	/**
	 * Find a user by username.
	 */
	public function findByUsername(string $username): ?object
	{
		return $this->db->table('users')
			->where('username', '=', trim($username))
			->first();
	}

	/**
	 * Check if an email is already registered.
	 */
	public function emailExists(string $email): bool
	{
		return $this->db->table('users')
			->where('email', '=', strtolower(trim($email)))
			->exists();
	}

	/**
	 * Check if a username is already taken.
	 */
	public function usernameExists(string $username): bool
	{
		return $this->db->table('users')
			->where('username', '=', trim($username))
			->exists();
	}

	/**
	 * List users with optional filters.
	 *
	 * @param array{role?: string, status?: string, search?: string, limit?: int, offset?: int, orderby?: string, order?: string} $args
	 * @return array<int, object>
	 */
	public function list(array $args = []): array
	{
		$query = $this->db->table('users');

		if (isset($args['role'])) {
			$query->where('role', '=', $args['role']);
		}

		if (isset($args['status'])) {
			$query->where('status', '=', $args['status']);
		}

		if (isset($args['search']) && $args['search'] !== '') {
			$search = '%' . $args['search'] . '%';
			// Search in email, username, and display_name
			// For now, search username only (multi-column LIKE requires raw SQL)
			$query->where('username', 'LIKE', $search);
		}

		$orderBy = $args['orderby'] ?? 'created_at';
		$order = strtoupper($args['order'] ?? 'DESC');
		if ($order !== 'ASC' && $order !== 'DESC') {
			$order = 'DESC';
		}
		$query->orderBy($orderBy, $order);

		if (isset($args['limit'])) {
			$query->limit((int) $args['limit']);
		}

		if (isset($args['offset'])) {
			$query->offset((int) $args['offset']);
		}

		return $query->get();
	}

	/**
	 * Count users with optional role filter.
	 */
	public function count(?string $role = null): int
	{
		$query = $this->db->table('users');

		if ($role !== null) {
			$query->where('role', '=', $role);
		}

		return $query->count();
	}

	// ---------------------------------------------------------------
	// Validation
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 */
	private function validateCreateData(array $data): void
	{
		if (!isset($data['email']) || trim($data['email']) === '') {
			throw new \InvalidArgumentException('Email is required.');
		}

		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Invalid email address.');
		}

		if (!isset($data['username']) || trim($data['username']) === '') {
			throw new \InvalidArgumentException('Username is required.');
		}

		if (strlen($data['username']) < 3) {
			throw new \InvalidArgumentException('Username must be at least 3 characters.');
		}

		if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $data['username'])) {
			throw new \InvalidArgumentException('Username can only contain letters, numbers, underscores, dots, and hyphens.');
		}

		if (!isset($data['password']) || $data['password'] === '') {
			throw new \InvalidArgumentException('Password is required.');
		}

		if (strlen($data['password']) < 8) {
			throw new \InvalidArgumentException('Password must be at least 8 characters.');
		}
	}
}
