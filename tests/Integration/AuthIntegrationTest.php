<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Auth\AuthManager;
use Kreblu\Core\Auth\UserManager;
use Kreblu\Core\Database\Connection;
use Kreblu\Core\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Auth system (AuthManager + UserManager).
 *
 * Requires a running MySQL database with the core schema migrated.
 */
final class AuthIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private static ?AuthManager $auth = null;
	private static ?UserManager $users = null;

	public static function setUpBeforeClass(): void
	{
		$host   = $_ENV['KB_DB_HOST'] ?? 'db';
		$port   = (int) ($_ENV['KB_DB_PORT'] ?? 3306);
		$name   = $_ENV['KB_DB_NAME'] ?? 'kreblu_test';
		$user   = $_ENV['KB_DB_USER'] ?? 'kreblu';
		$pass   = $_ENV['KB_DB_PASS'] ?? 'kreblu_dev';
		$prefix = $_ENV['KB_DB_PREFIX'] ?? 'kb_test_';

		try {
			self::$db = new Connection(
				host: $host,
				port: $port,
				name: $name,
				user: $user,
				pass: $pass,
				prefix: $prefix,
			);

			// Run migrations to ensure tables exist
			$migrationsPath = dirname(__DIR__, 2) . '/kb-core/Database/migrations';
			$schema = new Schema(self::$db, $migrationsPath);
			$schema->ensureMigrationsTable();

			if (!empty($schema->getPendingMigrations())) {
				$schema->runPending();
			}

			self::$auth = new AuthManager(self::$db, bcryptCost: 4); // Low cost for fast tests
			self::$users = new UserManager(self::$db, self::$auth);
		} catch (\PDOException $e) {
			self::markTestSkipped('Database not available: ' . $e->getMessage());
		}
	}

	protected function setUp(): void
	{
		if (self::$db === null) {
			$this->markTestSkipped('Database not available.');
		}

		// Clear users and sessions before each test
		self::$db->execute('DELETE FROM ' . self::$db->tableName('sessions'));
		self::$db->execute('SET FOREIGN_KEY_CHECKS = 0');
		self::$db->execute('DELETE FROM ' . self::$db->tableName('users'));
		self::$db->execute('SET FOREIGN_KEY_CHECKS = 1');

		self::$auth->setCurrentUser(null);
	}

	private function db(): Connection { return self::$db; }
	private function auth(): AuthManager { return self::$auth; }
	private function users(): UserManager { return self::$users; }

	// ---------------------------------------------------------------
	// User creation
	// ---------------------------------------------------------------

	public function test_create_user(): void
	{
		$id = $this->users()->create([
			'email'    => 'test@example.com',
			'username' => 'testuser',
			'password' => 'SecurePass123',
		]);

		$this->assertGreaterThan(0, $id);
	}

	public function test_create_user_with_role(): void
	{
		$id = $this->users()->create([
			'email'    => 'admin@example.com',
			'username' => 'adminuser',
			'password' => 'AdminPass123',
			'role'     => 'admin',
		]);

		$user = $this->users()->findById($id);
		$this->assertEquals('admin', $user->role);
	}

	public function test_create_user_defaults_to_subscriber(): void
	{
		$id = $this->users()->create([
			'email'    => 'sub@example.com',
			'username' => 'subuser',
			'password' => 'SubPass1234',
		]);

		$user = $this->users()->findById($id);
		$this->assertEquals('subscriber', $user->role);
	}

	public function test_create_user_duplicate_email_throws(): void
	{
		$this->users()->create([
			'email'    => 'dupe@example.com',
			'username' => 'first',
			'password' => 'Password123',
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('email already exists');

		$this->users()->create([
			'email'    => 'dupe@example.com',
			'username' => 'second',
			'password' => 'Password123',
		]);
	}

	public function test_create_user_duplicate_username_throws(): void
	{
		$this->users()->create([
			'email'    => 'a@example.com',
			'username' => 'taken',
			'password' => 'Password123',
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('username already exists');

		$this->users()->create([
			'email'    => 'b@example.com',
			'username' => 'taken',
			'password' => 'Password123',
		]);
	}

	public function test_create_user_invalid_email_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->users()->create([
			'email'    => 'not-an-email',
			'username' => 'testuser',
			'password' => 'Password123',
		]);
	}

	public function test_create_user_short_password_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('at least 8 characters');

		$this->users()->create([
			'email'    => 'test@example.com',
			'username' => 'testuser',
			'password' => 'short',
		]);
	}

	public function test_create_user_invalid_role_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid role');

		$this->users()->create([
			'email'    => 'test@example.com',
			'username' => 'testuser',
			'password' => 'Password123',
			'role'     => 'superadmin',
		]);
	}

	public function test_create_user_short_username_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('at least 3 characters');

		$this->users()->create([
			'email'    => 'test@example.com',
			'username' => 'ab',
			'password' => 'Password123',
		]);
	}

	// ---------------------------------------------------------------
	// User finders
	// ---------------------------------------------------------------

	public function test_find_by_id(): void
	{
		$id = $this->users()->create([
			'email'    => 'find@example.com',
			'username' => 'findme',
			'password' => 'Password123',
		]);

		$user = $this->users()->findById($id);
		$this->assertNotNull($user);
		$this->assertEquals('find@example.com', $user->email);
	}

	public function test_find_by_email(): void
	{
		$this->users()->create([
			'email'    => 'byemail@example.com',
			'username' => 'emailuser',
			'password' => 'Password123',
		]);

		$user = $this->users()->findByEmail('byemail@example.com');
		$this->assertNotNull($user);
		$this->assertEquals('emailuser', $user->username);
	}

	public function test_find_by_email_case_insensitive(): void
	{
		$this->users()->create([
			'email'    => 'UPPER@example.com',
			'username' => 'upperuser',
			'password' => 'Password123',
		]);

		$user = $this->users()->findByEmail('upper@example.com');
		$this->assertNotNull($user);
	}

	public function test_find_nonexistent_returns_null(): void
	{
		$this->assertNull($this->users()->findById(99999));
		$this->assertNull($this->users()->findByEmail('nobody@example.com'));
		$this->assertNull($this->users()->findByUsername('nobody'));
	}

	// ---------------------------------------------------------------
	// User update
	// ---------------------------------------------------------------

	public function test_update_display_name(): void
	{
		$id = $this->users()->create([
			'email'    => 'update@example.com',
			'username' => 'updateme',
			'password' => 'Password123',
		]);

		$this->users()->update($id, ['display_name' => 'New Name']);

		$user = $this->users()->findById($id);
		$this->assertEquals('New Name', $user->display_name);
	}

	public function test_update_password(): void
	{
		$id = $this->users()->create([
			'email'    => 'pwchange@example.com',
			'username' => 'pwuser',
			'password' => 'OldPassword1',
		]);

		$this->users()->update($id, ['password' => 'NewPassword1']);

		// Old password should fail
		$this->assertNull($this->auth()->attempt('pwuser', 'OldPassword1'));

		// New password should work
		$this->assertNotNull($this->auth()->attempt('pwuser', 'NewPassword1'));
	}

	// ---------------------------------------------------------------
	// User deletion
	// ---------------------------------------------------------------

	public function test_delete_user(): void
	{
		$id = $this->users()->create([
			'email'    => 'delete@example.com',
			'username' => 'deleteme',
			'password' => 'Password123',
		]);

		$this->assertTrue($this->users()->delete($id));
		$this->assertNull($this->users()->findById($id));
	}

	// ---------------------------------------------------------------
	// User listing
	// ---------------------------------------------------------------

	public function test_list_users(): void
	{
		$this->users()->create(['email' => 'a@t.com', 'username' => 'aaa', 'password' => 'Password123', 'role' => 'admin']);
		$this->users()->create(['email' => 'b@t.com', 'username' => 'bbb', 'password' => 'Password123', 'role' => 'editor']);
		$this->users()->create(['email' => 'c@t.com', 'username' => 'ccc', 'password' => 'Password123', 'role' => 'admin']);

		$all = $this->users()->list();
		$this->assertCount(3, $all);

		$admins = $this->users()->list(['role' => 'admin']);
		$this->assertCount(2, $admins);
	}

	public function test_count_users(): void
	{
		$this->users()->create(['email' => 'x@t.com', 'username' => 'xxx', 'password' => 'Password123', 'role' => 'admin']);
		$this->users()->create(['email' => 'y@t.com', 'username' => 'yyy', 'password' => 'Password123', 'role' => 'subscriber']);

		$this->assertEquals(2, $this->users()->count());
		$this->assertEquals(1, $this->users()->count('admin'));
	}

	// ---------------------------------------------------------------
	// Authentication
	// ---------------------------------------------------------------

	public function test_login_with_email(): void
	{
		$this->users()->create([
			'email'    => 'login@example.com',
			'username' => 'loginuser',
			'password' => 'MyPassword1',
		]);

		$user = $this->auth()->attempt('login@example.com', 'MyPassword1');
		$this->assertNotNull($user);
		$this->assertEquals('loginuser', $user->username);
	}

	public function test_login_with_username(): void
	{
		$this->users()->create([
			'email'    => 'user@example.com',
			'username' => 'myusername',
			'password' => 'MyPassword1',
		]);

		$user = $this->auth()->attempt('myusername', 'MyPassword1');
		$this->assertNotNull($user);
	}

	public function test_login_wrong_password(): void
	{
		$this->users()->create([
			'email'    => 'wrong@example.com',
			'username' => 'wrongpw',
			'password' => 'CorrectPass1',
		]);

		$user = $this->auth()->attempt('wrong@example.com', 'WrongPassword');
		$this->assertNull($user);
	}

	public function test_login_nonexistent_user(): void
	{
		$user = $this->auth()->attempt('nobody@example.com', 'Password123');
		$this->assertNull($user);
	}

	// ---------------------------------------------------------------
	// Sessions
	// ---------------------------------------------------------------

	public function test_create_and_validate_session(): void
	{
		$id = $this->users()->create([
			'email'    => 'session@example.com',
			'username' => 'sessionuser',
			'password' => 'Password123',
		]);

		$token = $this->auth()->createSession($id, '127.0.0.1', 'PHPUnit');

		$this->assertNotEmpty($token);
		$this->assertEquals(128, strlen($token)); // 64 bytes = 128 hex chars

		// Validate the session
		$user = $this->auth()->validateSession($token);
		$this->assertNotNull($user);
		$this->assertEquals($id, $user->id);
	}

	public function test_invalid_session_token(): void
	{
		$user = $this->auth()->validateSession('invalid_token_here');
		$this->assertNull($user);
	}

	public function test_destroy_session(): void
	{
		$id = $this->users()->create([
			'email'    => 'destroy@example.com',
			'username' => 'destroyuser',
			'password' => 'Password123',
		]);

		$token = $this->auth()->createSession($id);

		// Session should be valid
		$this->assertNotNull($this->auth()->validateSession($token));

		// Destroy it
		$this->auth()->destroySession($token);

		// Should no longer be valid
		$this->assertNull($this->auth()->validateSession($token));
	}

	public function test_destroy_all_sessions(): void
	{
		$id = $this->users()->create([
			'email'    => 'allsess@example.com',
			'username' => 'allsessuser',
			'password' => 'Password123',
		]);

		$token1 = $this->auth()->createSession($id);
		$token2 = $this->auth()->createSession($id);

		$this->auth()->destroyAllSessions($id);

		$this->assertNull($this->auth()->validateSession($token1));
		$this->assertNull($this->auth()->validateSession($token2));
	}

	public function test_get_user_sessions(): void
	{
		$id = $this->users()->create([
			'email'    => 'multi@example.com',
			'username' => 'multiuser',
			'password' => 'Password123',
		]);

		$this->auth()->createSession($id, '10.0.0.1', 'Browser A');
		$this->auth()->createSession($id, '10.0.0.2', 'Browser B');

		$sessions = $this->auth()->getUserSessions($id);
		$this->assertCount(2, $sessions);
	}

	// ---------------------------------------------------------------
	// Current user state
	// ---------------------------------------------------------------

	public function test_not_logged_in_initially(): void
	{
		$this->assertFalse($this->auth()->isLoggedIn());
		$this->assertNull($this->auth()->currentUser());
		$this->assertEquals(0, $this->auth()->currentUserId());
	}

	public function test_logged_in_after_session_create(): void
	{
		$id = $this->users()->create([
			'email'    => 'current@example.com',
			'username' => 'currentuser',
			'password' => 'Password123',
			'role'     => 'editor',
		]);

		$this->auth()->createSession($id);

		$this->assertTrue($this->auth()->isLoggedIn());
		$this->assertEquals($id, $this->auth()->currentUserId());
		$this->assertTrue($this->auth()->currentUserCan('edit_posts'));
		$this->assertFalse($this->auth()->currentUserCan('manage_options'));
	}

	// ---------------------------------------------------------------
	// Password hashing
	// ---------------------------------------------------------------

	public function test_hash_password(): void
	{
		$hash = $this->auth()->hashPassword('TestPassword');

		$this->assertNotEquals('TestPassword', $hash);
		$this->assertTrue(str_starts_with($hash, '$2y$'));
	}

	public function test_verify_password(): void
	{
		$hash = $this->auth()->hashPassword('VerifyMe123');

		$this->assertTrue($this->auth()->verifyPassword('VerifyMe123', $hash));
		$this->assertFalse($this->auth()->verifyPassword('WrongPassword', $hash));
	}

	// ---------------------------------------------------------------
	// Session cleanup
	// ---------------------------------------------------------------

	public function test_clean_expired_sessions(): void
	{
		$id = $this->users()->create([
			'email'    => 'expire@example.com',
			'username' => 'expireuser',
			'password' => 'Password123',
		]);

		// Create a session then manually expire it
		$this->auth()->createSession($id);

		$this->db()->table('sessions')
			->where('user_id', '=', $id)
			->update(['expires_at' => '2020-01-01 00:00:00']);

		$cleaned = $this->auth()->cleanExpiredSessions();
		$this->assertGreaterThan(0, $cleaned);

		$sessions = $this->auth()->getUserSessions($id);
		$this->assertEmpty($sessions);
	}
}
