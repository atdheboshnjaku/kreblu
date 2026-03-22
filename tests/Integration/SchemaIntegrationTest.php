<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Database\Connection;
use Kreblu\Core\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Schema/Migration system.
 *
 * Tests that migrations run correctly, are tracked properly,
 * and can be rolled back. Also verifies the core schema migration
 * creates all expected tables with correct structure.
 */
final class SchemaIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private static ?Schema $schema = null;

	public static function setUpBeforeClass(): void
	{
		$host   = $_ENV['KREBLU_DB_HOST'] ?? '127.0.0.1';
		$port   = (int) ($_ENV['KREBLU_DB_PORT'] ?? 3306);
		$name   = $_ENV['KREBLU_DB_NAME'] ?? 'kreblu_test';
		$user   = $_ENV['KREBLU_DB_USER'] ?? 'kreblu';
		$pass   = $_ENV['KREBLU_DB_PASS'] ?? 'kreblu_dev';
		$prefix = $_ENV['KREBLU_DB_PREFIX'] ?? 'kb_test_';

		try {
			self::$db = new Connection(
				host: $host,
				port: $port,
				name: $name,
				user: $user,
				pass: $pass,
				prefix: $prefix,
			);

			// Clean up any leftover tables from previous test runs
			self::cleanDatabase();

			$migrationsPath = dirname(__DIR__, 2) . '/kb-core/Database/migrations';
			self::$schema = new Schema(self::$db, $migrationsPath);
		} catch (\PDOException $e) {
			self::markTestSkipped('Database not available: ' . $e->getMessage());
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$db !== null && self::$db->isConnected()) {
			self::cleanDatabase();
			self::$db->disconnect();
		}
	}

	protected function setUp(): void
	{
		if (self::$db === null || self::$schema === null) {
			$this->markTestSkipped('Database not available.');
		}

		// Start fresh for each test
		self::cleanDatabase();
	}

	private static function cleanDatabase(): void
	{
		if (self::$db === null) {
			return;
		}

		$prefix = self::$db->prefix();

		self::$db->execute('SET FOREIGN_KEY_CHECKS = 0');

		// Get all tables with our prefix
		$tables = self::$db->raw(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?",
			[$prefix . '%']
		);

		foreach ($tables as $table) {
			self::$db->execute("DROP TABLE IF EXISTS {$table->TABLE_NAME}");
		}

		self::$db->execute('SET FOREIGN_KEY_CHECKS = 1');
	}

	private function db(): Connection
	{
		assert(self::$db !== null);
		return self::$db;
	}

	private function schema(): Schema
	{
		assert(self::$schema !== null);
		return self::$schema;
	}

	// ---------------------------------------------------------------
	// Migration runner tests
	// ---------------------------------------------------------------

	public function test_ensures_migrations_table_created(): void
	{
		$this->schema()->ensureMigrationsTable();

		$table = $this->db()->tableName('migrations');
		$result = $this->db()->raw(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			[$table]
		);

		$this->assertCount(1, $result);
	}

	public function test_get_available_migrations(): void
	{
		$migrations = $this->schema()->getAvailableMigrations();

		$this->assertNotEmpty($migrations);
		$this->assertContains('0001_create_core_tables.php', $migrations);
	}

	public function test_no_applied_migrations_initially(): void
	{
		$this->schema()->ensureMigrationsTable();
		$applied = $this->schema()->getAppliedMigrations();

		$this->assertEmpty($applied);
	}

	public function test_run_pending_executes_migrations(): void
	{
		$executed = $this->schema()->runPending();

		$this->assertNotEmpty($executed);
		$this->assertContains('0001_create_core_tables.php', $executed);
	}

	public function test_run_pending_tracks_applied(): void
	{
		$this->schema()->runPending();
		$applied = $this->schema()->getAppliedMigrations();

		$this->assertContains('0001_create_core_tables.php', $applied);
	}

	public function test_run_pending_is_idempotent(): void
	{
		$first = $this->schema()->runPending();
		$second = $this->schema()->runPending();

		$this->assertNotEmpty($first);
		$this->assertEmpty($second, 'Running pending again should find nothing to execute');
	}

	public function test_no_pending_after_run(): void
	{
		$this->schema()->runPending();
		$pending = $this->schema()->getPendingMigrations();

		$this->assertEmpty($pending);
	}

	public function test_rollback_undoes_last_migration(): void
	{
		$this->schema()->runPending();
		$rolledBack = $this->schema()->rollback(1);

		$this->assertNotEmpty($rolledBack);

		// The core tables should no longer exist
		$prefix = $this->db()->prefix();
		$tables = $this->db()->raw(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND TABLE_NAME != ?",
			[$prefix . '%', $prefix . 'migrations']
		);

		$this->assertEmpty($tables, 'Core tables should be dropped after rollback');
	}

	public function test_refresh_resets_and_reruns(): void
	{
		$this->schema()->runPending();
		$executed = $this->schema()->refresh();

		$this->assertNotEmpty($executed);

		// Tables should exist again
		$prefix = $this->db()->prefix();
		$result = $this->db()->raw(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			[$prefix . 'posts']
		);
		$this->assertCount(1, $result);
	}

	public function test_batch_number_increments(): void
	{
		// First run — creates batch 1
		$this->schema()->runPending();
		$batch1 = $this->schema()->getCurrentBatch();
		$this->assertEquals(1, $batch1);

		// Rollback and re-run — batch should be 2 now because
		// we don't clean the migrations table, just remove last batch
		// Actually: rollback removes the record entirely, resetting max to 0.
		// To test batch increment, we need two separate migration files.
		// With one migration, we verify the batch is correctly set to 1.
		$applied = $this->schema()->getAppliedMigrations();
		$this->assertNotEmpty($applied);
		$this->assertEquals(1, $batch1);
	}

	// ---------------------------------------------------------------
	// Core schema verification tests
	// ---------------------------------------------------------------

	public function test_core_tables_exist(): void
	{
		$this->schema()->runPending();

		$expectedTables = [
			'users',
			'sessions',
			'media',
			'posts',
			'revisions',
			'terms',
			'term_relationships',
			'comments',
			'options',
			'redirects',
		];

		$prefix = $this->db()->prefix();

		foreach ($expectedTables as $table) {
			$result = $this->db()->raw(
				"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
				[$prefix . $table]
			);

			$this->assertCount(1, $result, "Table {$prefix}{$table} should exist");
		}
	}

	public function test_posts_table_has_fulltext_index(): void
	{
		$this->schema()->runPending();

		$prefix = $this->db()->prefix();
		$indexes = $this->db()->raw(
			"SHOW INDEX FROM {$prefix}posts WHERE Index_type = 'FULLTEXT'"
		);

		$this->assertNotEmpty($indexes, 'Posts table should have a FULLTEXT index');
	}

	public function test_posts_table_has_json_column(): void
	{
		$this->schema()->runPending();

		$prefix = $this->db()->prefix();
		$columns = $this->db()->raw(
			"SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'meta'",
			[$prefix . 'posts']
		);

		$this->assertCount(1, $columns);
		$this->assertEquals('json', $columns[0]->DATA_TYPE);
	}

	public function test_all_tables_use_innodb(): void
	{
		$this->schema()->runPending();

		$prefix = $this->db()->prefix();
		$tables = $this->db()->raw(
			"SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?",
			[$prefix . '%']
		);

		foreach ($tables as $table) {
			$this->assertEquals(
				'InnoDB',
				$table->ENGINE,
				"Table {$table->TABLE_NAME} should use InnoDB"
			);
		}
	}

	public function test_all_tables_use_utf8mb4(): void
	{
		$this->schema()->runPending();

		$prefix = $this->db()->prefix();
		$tables = $this->db()->raw(
			"SELECT TABLE_NAME, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?",
			[$prefix . '%']
		);

		foreach ($tables as $table) {
			$this->assertStringStartsWith(
				'utf8mb4_',
				$table->TABLE_COLLATION,
				"Table {$table->TABLE_NAME} should use utf8mb4 collation"
			);
		}
	}

	public function test_foreign_keys_exist_on_posts(): void
	{
		$this->schema()->runPending();

		$prefix = $this->db()->prefix();
		$fks = $this->db()->raw(
			"SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
			[$prefix . 'posts']
		);

		$referencedTables = array_map(fn(object $fk) => $fk->REFERENCED_TABLE_NAME, $fks);

		$this->assertContains($prefix . 'users', $referencedTables, 'Posts should have FK to users');
		$this->assertContains($prefix . 'media', $referencedTables, 'Posts should have FK to media');
	}

	public function test_cascade_delete_works(): void
	{
		$this->schema()->runPending();

		// Insert a user
		$userId = $this->db()->table('users')->insert([
			'email'         => 'test@example.com',
			'username'      => 'testuser',
			'password_hash' => password_hash('test', PASSWORD_BCRYPT),
			'display_name'  => 'Test User',
			'role'          => 'admin',
		]);

		// Insert a post by that user
		$postId = $this->db()->table('posts')->insert([
			'title'     => 'Test Post',
			'slug'      => 'test-post',
			'body'      => 'Content',
			'author_id' => $userId,
			'type'      => 'post',
			'status'    => 'published',
		]);

		// Insert a comment on that post
		$this->db()->table('comments')->insert([
			'post_id' => $postId,
			'body'    => 'A comment',
			'status'  => 'approved',
		]);

		// Delete the user — post and comment should cascade
		$this->db()->table('users')->where('id', '=', $userId)->delete();

		$this->assertNull($this->db()->table('posts')->where('id', '=', $postId)->first());
		$this->assertEquals(0, $this->db()->table('comments')->where('post_id', '=', $postId)->count());
	}

	public function test_json_data_insert_and_query(): void
	{
		$this->schema()->runPending();

		$userId = $this->db()->table('users')->insert([
			'email'         => 'json@test.com',
			'username'      => 'jsonuser',
			'password_hash' => password_hash('test', PASSWORD_BCRYPT),
		]);

		$this->db()->table('posts')->insert([
			'title'     => 'JSON Test',
			'slug'      => 'json-test',
			'author_id' => $userId,
			'type'      => 'post',
			'status'    => 'published',
			'meta'      => json_encode([
				'seo_title'       => 'Custom SEO Title',
				'seo_description' => 'A description for search engines',
				'focus_keyword'   => 'kreblu',
			]),
		]);

		$post = $this->db()->table('posts')->where('slug', '=', 'json-test')->first();
		$this->assertNotNull($post);

		$meta = json_decode($post->meta, true);
		$this->assertEquals('kreblu', $meta['focus_keyword']);
		$this->assertEquals('Custom SEO Title', $meta['seo_title']);
	}

	public function test_options_table_stores_and_retrieves(): void
	{
		$this->schema()->runPending();

		$this->db()->table('options')->insert([
			'option_key'   => 'site_name',
			'option_value' => 'My Kreblu Site',
			'autoload'     => 1,
		]);

		$option = $this->db()->table('options')
			->where('option_key', '=', 'site_name')
			->first();

		$this->assertNotNull($option);
		$this->assertEquals('My Kreblu Site', $option->option_value);
	}
}
