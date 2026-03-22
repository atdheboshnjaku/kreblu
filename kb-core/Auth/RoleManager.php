<?php declare(strict_types=1);

namespace Kreblu\Core\Auth;

/**
 * Role Manager
 *
 * Defines the built-in roles and their capabilities.
 * Roles follow a hierarchy: admin > editor > author > contributor > subscriber.
 * Higher roles inherit all capabilities of lower roles.
 *
 * Kaps (augments) can register custom capabilities via the hook system,
 * but the core roles are defined here.
 *
 * WordPress developers: this replaces WP_Roles. Same concept, simpler implementation.
 */
final class RoleManager
{
	/**
	 * Built-in roles and their direct capabilities.
	 * Capabilities are cumulative — each role also inherits from the roles below it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ROLES = [
		'subscriber' => [
			'read',
			'edit_own_profile',
		],
		'contributor' => [
			'create_posts',
			'edit_own_posts',
			'delete_own_posts',
		],
		'author' => [
			'publish_posts',
			'upload_media',
			'edit_own_published_posts',
			'delete_own_published_posts',
		],
		'editor' => [
			'edit_posts',
			'edit_published_posts',
			'delete_posts',
			'delete_published_posts',
			'edit_pages',
			'delete_pages',
			'publish_pages',
			'moderate_comments',
			'manage_categories',
			'manage_links',
		],
		'admin' => [
			'manage_options',
			'manage_users',
			'manage_kaps',
			'manage_templates',
			'edit_files',
			'export',
			'import',
			'manage_site',
		],
	];

	/**
	 * Role hierarchy (index = level, higher level inherits all lower levels).
	 *
	 * @var array<int, string>
	 */
	private const HIERARCHY = [
		'subscriber',
		'contributor',
		'author',
		'editor',
		'admin',
	];

	/**
	 * Custom capabilities registered by kaps at runtime.
	 *
	 * @var array<string, array<int, string>> role => [capabilities]
	 */
	private static array $customCapabilities = [];

	/**
	 * Check if a role has a specific capability.
	 */
	public static function roleHasCapability(string $role, string $capability): bool
	{
		$allCapabilities = self::getCapabilitiesForRole($role);
		return in_array($capability, $allCapabilities, true);
	}

	/**
	 * Get all capabilities for a role (including inherited ones).
	 *
	 * @return array<int, string>
	 */
	public static function getCapabilitiesForRole(string $role): array
	{
		$roleLevel = array_search($role, self::HIERARCHY, true);

		if ($roleLevel === false) {
			return [];
		}

		$capabilities = [];

		// Accumulate capabilities from this role and all lower roles
		for ($i = 0; $i <= $roleLevel; $i++) {
			$r = self::HIERARCHY[$i];

			if (isset(self::ROLES[$r])) {
				$capabilities = array_merge($capabilities, self::ROLES[$r]);
			}

			if (isset(self::$customCapabilities[$r])) {
				$capabilities = array_merge($capabilities, self::$customCapabilities[$r]);
			}
		}

		return array_unique($capabilities);
	}

	/**
	 * Get all available roles.
	 *
	 * @return array<int, string>
	 */
	public static function getAllRoles(): array
	{
		return self::HIERARCHY;
	}

	/**
	 * Get the display label for a role.
	 */
	public static function getRoleLabel(string $role): string
	{
		return match ($role) {
			'admin'       => 'Administrator',
			'editor'      => 'Editor',
			'author'      => 'Author',
			'contributor' => 'Contributor',
			'subscriber'  => 'Subscriber',
			default       => ucfirst($role),
		};
	}

	/**
	 * Check if a role name is valid.
	 */
	public static function isValidRole(string $role): bool
	{
		return in_array($role, self::HIERARCHY, true);
	}

	/**
	 * Get the hierarchy level of a role (0 = lowest).
	 */
	public static function getRoleLevel(string $role): int
	{
		$level = array_search($role, self::HIERARCHY, true);
		return $level !== false ? (int) $level : -1;
	}

	/**
	 * Check if role A is higher than or equal to role B.
	 */
	public static function isRoleAtLeast(string $role, string $minimumRole): bool
	{
		return self::getRoleLevel($role) >= self::getRoleLevel($minimumRole);
	}

	/**
	 * Register a custom capability for a role.
	 *
	 * Used by kaps to add their own capabilities to existing roles.
	 *
	 * Usage:
	 *   RoleManager::addCapability('editor', 'manage_gallery');
	 */
	public static function addCapability(string $role, string $capability): void
	{
		if (!self::isValidRole($role)) {
			return;
		}

		self::$customCapabilities[$role][] = $capability;
	}

	/**
	 * Reset custom capabilities (used in testing).
	 */
	public static function resetCustomCapabilities(): void
	{
		self::$customCapabilities = [];
	}
}
