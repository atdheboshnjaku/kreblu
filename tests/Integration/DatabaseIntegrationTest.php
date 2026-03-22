<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Database Connection and QueryBuilder.
 *
 * These tests require a running MySQL database (via Docker).
 * They create a temporary test table, run queries, and clean up.
 *
 * Environment variables from phpunit.xml provide the connection details.
 */
final class DatabaseIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private const TEST_TABLE = 'test_items';

	public static function setUpBeforeClass(): void
	{
		$host = $_ENV['KREBLU_DB_HOST'] ?? '127.0.0.1';
		$port = (int) ($_ENV['KREBLU_DB_PORT'] ?? 3306);
		$name = $_ENV['KREBLU_DB_NAME'] ?? 'kreblu_test';
		$user = $_ENV['KREBLU_DB_USER'] ?? 'kreblu';
		$pass = $_ENV['KREBLU_DB_PASS'] ?? 'kreblu_dev';
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

			// Create the test table
			$tableName = self::$db->tableName(self::TEST_TABLE);
			self::$db->execute("DROP TABLE IF EXISTS {$tableName}");
			self::$db->execute("
				CREATE TABLE {$tableName} (
					id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(255) NOT NULL,
					status VARCHAR(50) NOT NULL DEFAULT 'active',
					score INT DEFAULT 0,
					meta JSON DEFAULT NULL,
					created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
			");
		} catch (\PDOException $e) {
			self::markTestSkipped('Database not available: ' . $e->getMessage());
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$db !== null && self::$db->isConnected()) {
			$tableName = self::$db->tableName(self::TEST_TABLE);
			self::$db->execute("DROP TABLE IF EXISTS {$tableName}");
			self::$db->disconnect();
		}
	}

	protected function setUp(): void
	{
		if (self::$db === null) {
			$this->markTestSkipped('Database not available.');
		}

		// Clear table before each test
		$tableName = self::$db->tableName(self::TEST_TABLE);
		self::$db->execute("TRUNCATE TABLE {$tableName}");
	}

	private function db(): Connection
	{
		assert(self::$db !== null);
		return self::$db;
	}

	// ---------------------------------------------------------------
	// Connection tests
	// ---------------------------------------------------------------

	public function test_connection_is_established(): void
	{
		$this->assertTrue($this->db()->isConnected());
	}

	public function test_pdo_instance_returned(): void
	{
		$this->assertInstanceOf(\PDO::class, $this->db()->pdo());
	}

	// ---------------------------------------------------------------
	// INSERT tests
	// ---------------------------------------------------------------

	public function test_insert_returns_id(): void
	{
		$id = $this->db()->table(self::TEST_TABLE)->insert([
			'name'   => 'First Item',
			'status' => 'active',
			'score'  => 10,
		]);

		$this->assertGreaterThan(0, $id);
	}

	public function test_insert_multiple_rows(): void
	{
		$count = $this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'Item A', 'status' => 'active', 'score' => 5],
			['name' => 'Item B', 'status' => 'draft', 'score' => 15],
			['name' => 'Item C', 'status' => 'active', 'score' => 25],
		]);

		$this->assertEquals(3, $count);
	}

	// ---------------------------------------------------------------
	// SELECT tests
	// ---------------------------------------------------------------

	public function test_get_returns_all_rows(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'A', 'status' => 'active']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'B', 'status' => 'active']);

		$results = $this->db()->table(self::TEST_TABLE)->get();
		$this->assertCount(2, $results);
	}

	public function test_first_returns_single_object(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Only One', 'status' => 'active']);

		$result = $this->db()->table(self::TEST_TABLE)->first();
		$this->assertNotNull($result);
		$this->assertEquals('Only One', $result->name);
	}

	public function test_first_returns_null_when_empty(): void
	{
		$result = $this->db()->table(self::TEST_TABLE)->first();
		$this->assertNull($result);
	}

	public function test_select_specific_columns(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Test', 'status' => 'active', 'score' => 42]);

		$result = $this->db()->table(self::TEST_TABLE)->select('name', 'score')->first();
		$this->assertNotNull($result);
		$this->assertEquals('Test', $result->name);
		$this->assertEquals(42, $result->score);
	}

	public function test_where_equals(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Active', 'status' => 'active']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Draft', 'status' => 'draft']);

		$results = $this->db()->table(self::TEST_TABLE)->where('status', '=', 'active')->get();
		$this->assertCount(1, $results);
		$this->assertEquals('Active', $results[0]->name);
	}

	public function test_where_greater_than(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Low', 'score' => 5]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'High', 'score' => 50]);

		$results = $this->db()->table(self::TEST_TABLE)->where('score', '>', 10)->get();
		$this->assertCount(1, $results);
		$this->assertEquals('High', $results[0]->name);
	}

	public function test_where_in(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'A', 'status' => 'active']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'B', 'status' => 'draft']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'C', 'status' => 'trash']);

		$results = $this->db()->table(self::TEST_TABLE)
			->whereIn('status', ['active', 'draft'])
			->get();
		$this->assertCount(2, $results);
	}

	public function test_where_null(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'No Meta', 'meta' => null]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Has Meta', 'meta' => '{"key":"val"}']);

		$results = $this->db()->table(self::TEST_TABLE)->whereNull('meta')->get();
		$this->assertCount(1, $results);
		$this->assertEquals('No Meta', $results[0]->name);
	}

	public function test_where_not_null(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'No Meta', 'meta' => null]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Has Meta', 'meta' => '{"key":"val"}']);

		$results = $this->db()->table(self::TEST_TABLE)->whereNotNull('meta')->get();
		$this->assertCount(1, $results);
		$this->assertEquals('Has Meta', $results[0]->name);
	}

	public function test_multiple_where_conditions(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Match', 'status' => 'active', 'score' => 50]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Wrong Status', 'status' => 'draft', 'score' => 50]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Wrong Score', 'status' => 'active', 'score' => 5]);

		$results = $this->db()->table(self::TEST_TABLE)
			->where('status', '=', 'active')
			->where('score', '>=', 10)
			->get();

		$this->assertCount(1, $results);
		$this->assertEquals('Match', $results[0]->name);
	}

	public function test_where_like(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Hello World']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Goodbye World']);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Hello PHP']);

		$results = $this->db()->table(self::TEST_TABLE)
			->where('name', 'LIKE', 'Hello%')
			->get();

		$this->assertCount(2, $results);
	}

	// ---------------------------------------------------------------
	// ORDER, LIMIT, OFFSET tests
	// ---------------------------------------------------------------

	public function test_order_by(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'B', 'score' => 20]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'A', 'score' => 10]);
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'C', 'score' => 30]);

		$results = $this->db()->table(self::TEST_TABLE)->orderBy('score', 'ASC')->get();
		$this->assertEquals('A', $results[0]->name);
		$this->assertEquals('C', $results[2]->name);
	}

	public function test_limit(): void
	{
		$this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'A', 'status' => 'active'],
			['name' => 'B', 'status' => 'active'],
			['name' => 'C', 'status' => 'active'],
		]);

		$results = $this->db()->table(self::TEST_TABLE)->limit(2)->get();
		$this->assertCount(2, $results);
	}

	public function test_offset(): void
	{
		$this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'A', 'score' => 1],
			['name' => 'B', 'score' => 2],
			['name' => 'C', 'score' => 3],
		]);

		$results = $this->db()->table(self::TEST_TABLE)
			->orderBy('score', 'ASC')
			->limit(2)
			->offset(1)
			->get();

		$this->assertCount(2, $results);
		$this->assertEquals('B', $results[0]->name);
	}

	// ---------------------------------------------------------------
	// COUNT and EXISTS tests
	// ---------------------------------------------------------------

	public function test_count(): void
	{
		$this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'A', 'status' => 'active'],
			['name' => 'B', 'status' => 'draft'],
			['name' => 'C', 'status' => 'active'],
		]);

		$this->assertEquals(3, $this->db()->table(self::TEST_TABLE)->count());
		$this->assertEquals(2, $this->db()->table(self::TEST_TABLE)->where('status', '=', 'active')->count());
	}

	public function test_exists(): void
	{
		$this->assertFalse($this->db()->table(self::TEST_TABLE)->exists());

		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Test']);

		$this->assertTrue($this->db()->table(self::TEST_TABLE)->exists());
		$this->assertTrue($this->db()->table(self::TEST_TABLE)->where('name', '=', 'Test')->exists());
		$this->assertFalse($this->db()->table(self::TEST_TABLE)->where('name', '=', 'Nope')->exists());
	}

	// ---------------------------------------------------------------
	// UPDATE tests
	// ---------------------------------------------------------------

	public function test_update(): void
	{
		$id = $this->db()->table(self::TEST_TABLE)->insert(['name' => 'Original', 'score' => 0]);

		$affected = $this->db()->table(self::TEST_TABLE)
			->where('id', '=', $id)
			->update(['name' => 'Updated', 'score' => 100]);

		$this->assertEquals(1, $affected);

		$row = $this->db()->table(self::TEST_TABLE)->where('id', '=', $id)->first();
		$this->assertEquals('Updated', $row->name);
		$this->assertEquals(100, $row->score);
	}

	public function test_update_multiple_rows(): void
	{
		$this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'A', 'status' => 'draft'],
			['name' => 'B', 'status' => 'draft'],
			['name' => 'C', 'status' => 'active'],
		]);

		$affected = $this->db()->table(self::TEST_TABLE)
			->where('status', '=', 'draft')
			->update(['status' => 'published']);

		$this->assertEquals(2, $affected);
	}

	// ---------------------------------------------------------------
	// DELETE tests
	// ---------------------------------------------------------------

	public function test_delete(): void
	{
		$id = $this->db()->table(self::TEST_TABLE)->insert(['name' => 'Doomed']);

		$affected = $this->db()->table(self::TEST_TABLE)
			->where('id', '=', $id)
			->delete();

		$this->assertEquals(1, $affected);
		$this->assertNull($this->db()->table(self::TEST_TABLE)->where('id', '=', $id)->first());
	}

	public function test_delete_with_condition(): void
	{
		$this->db()->table(self::TEST_TABLE)->insertMany([
			['name' => 'Keep', 'status' => 'active'],
			['name' => 'Remove 1', 'status' => 'trash'],
			['name' => 'Remove 2', 'status' => 'trash'],
		]);

		$affected = $this->db()->table(self::TEST_TABLE)
			->where('status', '=', 'trash')
			->delete();

		$this->assertEquals(2, $affected);
		$this->assertEquals(1, $this->db()->table(self::TEST_TABLE)->count());
	}

	// ---------------------------------------------------------------
	// Transaction tests
	// ---------------------------------------------------------------

	public function test_transaction_commits_on_success(): void
	{
		$this->db()->transaction(function (Connection $db) {
			$db->table(self::TEST_TABLE)->insert(['name' => 'In Transaction']);
		});

		$this->assertEquals(1, $this->db()->table(self::TEST_TABLE)->count());
	}

	public function test_transaction_rolls_back_on_exception(): void
	{
		try {
			$this->db()->transaction(function (Connection $db) {
				$db->table(self::TEST_TABLE)->insert(['name' => 'Should Not Persist']);
				throw new \RuntimeException('Simulated failure');
			});
		} catch (\RuntimeException) {
			// Expected
		}

		$this->assertEquals(0, $this->db()->table(self::TEST_TABLE)->count());
	}

	// ---------------------------------------------------------------
	// Raw query tests
	// ---------------------------------------------------------------

	public function test_raw_select(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Raw Test', 'score' => 42]);
		$tableName = $this->db()->tableName(self::TEST_TABLE);

		$results = $this->db()->raw(
			"SELECT name, score FROM {$tableName} WHERE score = ?",
			[42]
		);

		$this->assertCount(1, $results);
		$this->assertEquals('Raw Test', $results[0]->name);
	}

	public function test_raw_with_named_params(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert(['name' => 'Named', 'score' => 99]);
		$tableName = $this->db()->tableName(self::TEST_TABLE);

		$results = $this->db()->raw(
			"SELECT * FROM {$tableName} WHERE name = :name AND score = :score",
			['name' => 'Named', 'score' => 99]
		);

		$this->assertCount(1, $results);
	}

	// ---------------------------------------------------------------
	// JSON column tests (MySQL 8.4 feature)
	// ---------------------------------------------------------------

	public function test_insert_and_query_json(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert([
			'name' => 'JSON Test',
			'meta' => json_encode(['tags' => ['featured', 'php'], 'priority' => 'high']),
		]);

		$result = $this->db()->table(self::TEST_TABLE)->where('name', '=', 'JSON Test')->first();
		$this->assertNotNull($result);

		$meta = json_decode($result->meta, true);
		$this->assertEquals('high', $meta['priority']);
		$this->assertContains('php', $meta['tags']);
	}

	public function test_where_json_contains(): void
	{
		$this->db()->table(self::TEST_TABLE)->insert([
			'name' => 'Has Featured',
			'meta' => json_encode(['tags' => ['featured', 'news']]),
		]);
		$this->db()->table(self::TEST_TABLE)->insert([
			'name' => 'No Featured',
			'meta' => json_encode(['tags' => ['blog', 'update']]),
		]);

		$results = $this->db()->table(self::TEST_TABLE)
			->whereJsonContains('meta->tags', 'featured')
			->get();

		$this->assertCount(1, $results);
		$this->assertEquals('Has Featured', $results[0]->name);
	}

	// ---------------------------------------------------------------
	// Validation tests
	// ---------------------------------------------------------------

	public function test_invalid_operator_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid SQL operator');

		$this->db()->table(self::TEST_TABLE)->where('name', 'INVALID', 'test')->get();
	}

	public function test_invalid_order_direction_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->db()->table(self::TEST_TABLE)->orderBy('name', 'SIDEWAYS');
	}

	public function test_empty_insert_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->db()->table(self::TEST_TABLE)->insert([]);
	}

	public function test_empty_update_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->db()->table(self::TEST_TABLE)->update([]);
	}
}