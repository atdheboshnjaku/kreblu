<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Auth;

use Kreblu\Core\Auth\RoleManager;
use Kreblu\Tests\TestCase;

/**
 * Tests for the Role Manager.
 */
final class RoleManagerTest extends TestCase
{
	protected function tearDown(): void
	{
		RoleManager::resetCustomCapabilities();
		parent::tearDown();
	}

	public function test_admin_has_manage_options(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'manage_options'));
	}

	public function test_admin_inherits_editor_capabilities(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'edit_posts'));
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'moderate_comments'));
	}

	public function test_admin_inherits_all_lower_capabilities(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'read'));
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'create_posts'));
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'publish_posts'));
	}

	public function test_editor_cannot_manage_options(): void
	{
		$this->assertFalse(RoleManager::roleHasCapability('editor', 'manage_options'));
	}

	public function test_editor_can_edit_posts(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('editor', 'edit_posts'));
	}

	public function test_author_can_publish_posts(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('author', 'publish_posts'));
	}

	public function test_author_cannot_edit_others_posts(): void
	{
		$this->assertFalse(RoleManager::roleHasCapability('author', 'edit_posts'));
	}

	public function test_contributor_can_create_posts(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('contributor', 'create_posts'));
	}

	public function test_contributor_cannot_publish(): void
	{
		$this->assertFalse(RoleManager::roleHasCapability('contributor', 'publish_posts'));
	}

	public function test_subscriber_can_read(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('subscriber', 'read'));
	}

	public function test_subscriber_cannot_create_posts(): void
	{
		$this->assertFalse(RoleManager::roleHasCapability('subscriber', 'create_posts'));
	}

	public function test_invalid_role_has_no_capabilities(): void
	{
		$this->assertFalse(RoleManager::roleHasCapability('nonexistent', 'read'));
	}

	public function test_get_all_roles(): void
	{
		$roles = RoleManager::getAllRoles();

		$this->assertContains('admin', $roles);
		$this->assertContains('editor', $roles);
		$this->assertContains('author', $roles);
		$this->assertContains('contributor', $roles);
		$this->assertContains('subscriber', $roles);
		$this->assertCount(5, $roles);
	}

	public function test_role_labels(): void
	{
		$this->assertEquals('Administrator', RoleManager::getRoleLabel('admin'));
		$this->assertEquals('Editor', RoleManager::getRoleLabel('editor'));
		$this->assertEquals('Subscriber', RoleManager::getRoleLabel('subscriber'));
	}

	public function test_is_valid_role(): void
	{
		$this->assertTrue(RoleManager::isValidRole('admin'));
		$this->assertTrue(RoleManager::isValidRole('subscriber'));
		$this->assertFalse(RoleManager::isValidRole('superadmin'));
		$this->assertFalse(RoleManager::isValidRole(''));
	}

	public function test_role_level(): void
	{
		$this->assertEquals(4, RoleManager::getRoleLevel('admin'));
		$this->assertEquals(3, RoleManager::getRoleLevel('editor'));
		$this->assertEquals(0, RoleManager::getRoleLevel('subscriber'));
		$this->assertEquals(-1, RoleManager::getRoleLevel('invalid'));
	}

	public function test_is_role_at_least(): void
	{
		$this->assertTrue(RoleManager::isRoleAtLeast('admin', 'editor'));
		$this->assertTrue(RoleManager::isRoleAtLeast('editor', 'editor'));
		$this->assertFalse(RoleManager::isRoleAtLeast('author', 'editor'));
		$this->assertTrue(RoleManager::isRoleAtLeast('admin', 'subscriber'));
	}

	public function test_custom_capability(): void
	{
		RoleManager::addCapability('editor', 'manage_gallery');

		$this->assertTrue(RoleManager::roleHasCapability('editor', 'manage_gallery'));
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'manage_gallery'));
		$this->assertFalse(RoleManager::roleHasCapability('author', 'manage_gallery'));
	}

	public function test_reset_custom_capabilities(): void
	{
		RoleManager::addCapability('editor', 'custom_cap');
		$this->assertTrue(RoleManager::roleHasCapability('editor', 'custom_cap'));

		RoleManager::resetCustomCapabilities();
		$this->assertFalse(RoleManager::roleHasCapability('editor', 'custom_cap'));
	}

	public function test_admin_has_manage_kaps(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'manage_kaps'));
	}

	public function test_admin_has_manage_templates(): void
	{
		$this->assertTrue(RoleManager::roleHasCapability('admin', 'manage_templates'));
	}
}
