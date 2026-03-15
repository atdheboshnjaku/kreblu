<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Database;

use Kreblu\Core\Database\Connection;
use Kreblu\Tests\TestCase;

/**
 * Unit tests for the Database Connection class.
 *
 * These tests verify the Connection object's behavior without
 * requiring a live database. Integration tests (which hit MySQL)
 * are in tests/Integration/.
 */
final class ConnectionTest extends TestCase
{
	public function test_connection_is_lazy(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
		);

		// Connection should NOT be established on construction
		$this->assertFalse($db->isConnected());
	}

	public function test_prefix_defaults_to_os(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
		);

		$this->assertEquals('kb_', $db->prefix());
	}

	public function test_custom_prefix(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
			prefix: 'kb_',
		);

		$this->assertEquals('kb_', $db->prefix());
	}

	public function test_table_name_adds_prefix(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
			prefix: 'kb_',
		);

		$this->assertEquals('kb_posts', $db->tableName('posts'));
		$this->assertEquals('kb_users', $db->tableName('users'));
		$this->assertEquals('kb_term_relationships', $db->tableName('term_relationships'));
	}

	public function test_disconnect_sets_not_connected(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
		);

		// Not connected initially
		$this->assertFalse($db->isConnected());

		// Disconnect on an unconnected instance should not throw
		$db->disconnect();
		$this->assertFalse($db->isConnected());
	}

	public function test_table_returns_query_builder(): void
	{
		$db = new Connection(
			host: 'localhost',
			port: 3306,
			name: 'test',
			user: 'test',
			pass: 'test',
		);

		$builder = $db->table('posts');
		$this->assertInstanceOf(\Kreblu\Core\Database\QueryBuilder::class, $builder);
	}
}
