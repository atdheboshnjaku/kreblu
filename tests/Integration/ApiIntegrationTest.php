<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Api\ApiAuth;
use Kreblu\Core\Api\Endpoints\PostsEndpoint;
use Kreblu\Core\Api\Endpoints\TermsEndpoint;
use Kreblu\Core\Api\Endpoints\OptionsEndpoint;
use Kreblu\Core\Auth\AuthManager;
use Kreblu\Core\Content\PostManager;
use Kreblu\Core\Content\TaxonomyManager;
use Kreblu\Core\Database\Connection;
use Kreblu\Core\Database\Schema;
use Kreblu\Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the REST API endpoints.
 *
 * Tests endpoint controllers directly, no HTTP server needed.
 * Each test builds a mock Request and calls the endpoint method.
 */
final class ApiIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private static int $testUserId = 0;

	private PostsEndpoint $postsApi;
	private TermsEndpoint $termsApi;
	private OptionsEndpoint $optionsApi;

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
				host: $host, port: $port, name: $name,
				user: $user, pass: $pass, prefix: $prefix,
			);

			$migrationsPath = dirname(__DIR__, 2) . '/kb-core/Database/migrations';
			$schema = new Schema(self::$db, $migrationsPath);
			$schema->ensureMigrationsTable();
			if (!empty($schema->getPendingMigrations())) {
				$schema->runPending();
			}

			$auth = new AuthManager(self::$db, bcryptCost: 4);
			self::$testUserId = self::$db->table('users')->insert([
				'email'         => 'api_test@example.com',
				'username'      => 'api_tester',
				'password_hash' => $auth->hashPassword('TestPass123'),
				'display_name'  => 'API Tester',
				'role'          => 'admin',
			]);
		} catch (\PDOException $e) {
			self::markTestSkipped('Database not available: ' . $e->getMessage());
		}
	}

	protected function setUp(): void
	{
		if (self::$db === null) {
			$this->markTestSkipped('Database not available.');
		}

		// Clear content tables
		self::$db->execute('DELETE FROM ' . self::$db->tableName('comments'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('term_relationships'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('revisions'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('posts'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('terms'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('options'));

		$this->postsApi = new PostsEndpoint(new PostManager(self::$db));
		$this->termsApi = new TermsEndpoint(new TaxonomyManager(self::$db));
		$this->optionsApi = new OptionsEndpoint(self::$db);
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$db !== null && self::$db->isConnected()) {
			self::$db->execute('SET FOREIGN_KEY_CHECKS = 0');
			self::$db->execute('DELETE FROM ' . self::$db->tableName('users') . ' WHERE id = ?', [self::$testUserId]);
			self::$db->execute('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	private function db(): Connection { return self::$db; }
	private function userId(): int { return self::$testUserId; }

	/**
	 * Create a mock Request with given parameters.
	 */
	private function makeRequest(
		string $method = 'GET',
		string $path = '/',
		array $query = [],
		array $body = [],
		?string $jsonBody = null,
	): Request {
		$server = [
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => $path . ($query ? '?' . http_build_query($query) : ''),
			'SERVER_NAME'    => 'localhost',
			'SERVER_PORT'    => '8888',
			'HTTP_HOST'      => 'localhost:8888',
		];

		if ($jsonBody !== null) {
			$server['CONTENT_TYPE'] = 'application/json';
			$server['HTTP_CONTENT_TYPE'] = 'application/json';
		}

		$get = $query;
		$post = $body;

		return new Request($get, $post, $server, [], [], $jsonBody);
	}

	/**
	 * Decode a Response body to an array.
	 */
	private function decodeResponse(\Kreblu\Core\Http\Response $response): array
	{
		return json_decode($response->getBody(), true) ?? [];
	}

	// ---------------------------------------------------------------
	// Posts API
	// ---------------------------------------------------------------

	public function test_create_post_via_api(): void
	{
		$request = $this->makeRequest('POST', '/api/v1/posts', jsonBody: json_encode([
			'title'     => 'API Post',
			'body'      => '<p>Created via API</p>',
			'author_id' => $this->userId(),
			'status'    => 'published',
		]));

		$response = $this->postsApi->store($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(201, $response->getStatusCode());
		$this->assertEquals('API Post', $data['data']['title']);
		$this->assertEquals('published', $data['data']['status']);
		$this->assertNotNull($data['data']['id']);
	}

	public function test_create_post_validation_error(): void
	{
		$request = $this->makeRequest('POST', '/api/v1/posts', jsonBody: json_encode([
			'body' => 'no title',
		]));

		$response = $this->postsApi->store($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(422, $response->getStatusCode());
		$this->assertEquals('VALIDATION_ERROR', $data['code']);
	}

	public function test_list_posts_via_api(): void
	{
		// Create some posts
		$posts = new PostManager(self::$db);
		$posts->create(['title' => 'Post A', 'author_id' => $this->userId(), 'status' => 'published']);
		$posts->create(['title' => 'Post B', 'author_id' => $this->userId(), 'status' => 'published']);
		$posts->create(['title' => 'Post C', 'author_id' => $this->userId(), 'status' => 'draft']);

		$request = $this->makeRequest('GET', '/api/v1/posts', ['status' => 'published']);
		$response = $this->postsApi->index($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertCount(2, $data['data']);
		$this->assertEquals(2, $data['meta']['total']);
	}

	public function test_list_posts_pagination(): void
	{
		$posts = new PostManager(self::$db);
		for ($i = 1; $i <= 5; $i++) {
			$posts->create(['title' => "Post {$i}", 'author_id' => $this->userId()]);
		}

		$request = $this->makeRequest('GET', '/api/v1/posts', ['page' => '2', 'per_page' => '2']);
		$response = $this->postsApi->index($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertCount(2, $data['data']);
		$this->assertEquals(2, $data['meta']['page']);
		$this->assertEquals(5, $data['meta']['total']);
		$this->assertEquals(3, $data['meta']['total_pages']);
	}

	public function test_get_single_post(): void
	{
		$posts = new PostManager(self::$db);
		$id = $posts->create(['title' => 'Single Post', 'author_id' => $this->userId()]);

		$request = $this->makeRequest('GET', "/api/v1/posts/{$id}");
		$response = $this->postsApi->show($request, ['id' => (string) $id]);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('Single Post', $data['data']['title']);
	}

	public function test_get_nonexistent_post(): void
	{
		$request = $this->makeRequest('GET', '/api/v1/posts/99999');
		$response = $this->postsApi->show($request, ['id' => '99999']);

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function test_update_post_via_api(): void
	{
		$posts = new PostManager(self::$db);
		$id = $posts->create(['title' => 'Original', 'author_id' => $this->userId()]);

		$request = $this->makeRequest('PUT', "/api/v1/posts/{$id}", jsonBody: json_encode([
			'title'  => 'Updated',
			'status' => 'published',
		]));

		$response = $this->postsApi->update($request, ['id' => (string) $id]);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('Updated', $data['data']['title']);
		$this->assertEquals('published', $data['data']['status']);
	}

	public function test_delete_post_via_api(): void
	{
		$posts = new PostManager(self::$db);
		$id = $posts->create(['title' => 'Deletable', 'author_id' => $this->userId()]);

		$request = $this->makeRequest('DELETE', "/api/v1/posts/{$id}");
		$response = $this->postsApi->destroy($request, ['id' => (string) $id]);

		$this->assertEquals(204, $response->getStatusCode());

		// Post should be trashed, not hard deleted
		$post = $posts->findById($id);
		$this->assertEquals('trash', $post->status);
	}

	public function test_force_delete_post(): void
	{
		$posts = new PostManager(self::$db);
		$id = $posts->create(['title' => 'Force Delete', 'author_id' => $this->userId()]);

		$request = $this->makeRequest('DELETE', "/api/v1/posts/{$id}", ['force' => 'true']);
		$response = $this->postsApi->destroy($request, ['id' => (string) $id]);

		$this->assertEquals(204, $response->getStatusCode());

		$post = $posts->findById($id);
		$this->assertNull($post);
	}

	// ---------------------------------------------------------------
	// Terms API
	// ---------------------------------------------------------------

	public function test_create_term_via_api(): void
	{
		$request = $this->makeRequest('POST', '/api/v1/terms', jsonBody: json_encode([
			'taxonomy' => 'category',
			'name'     => 'Technology',
		]));

		$response = $this->termsApi->store($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(201, $response->getStatusCode());
		$this->assertEquals('Technology', $data['data']['name']);
		$this->assertEquals('technology', $data['data']['slug']);
	}

	public function test_list_terms_requires_taxonomy(): void
	{
		$request = $this->makeRequest('GET', '/api/v1/terms');
		$response = $this->termsApi->index($request);

		$this->assertEquals(422, $response->getStatusCode());
	}

	public function test_list_terms_by_taxonomy(): void
	{
		$taxonomy = new TaxonomyManager(self::$db);
		$taxonomy->createTerm(['taxonomy' => 'category', 'name' => 'News']);
		$taxonomy->createTerm(['taxonomy' => 'category', 'name' => 'Sports']);
		$taxonomy->createTerm(['taxonomy' => 'tag', 'name' => 'PHP']);

		$request = $this->makeRequest('GET', '/api/v1/terms', ['taxonomy' => 'category']);
		$response = $this->termsApi->index($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertCount(2, $data['data']);
	}

	public function test_delete_term_via_api(): void
	{
		$taxonomy = new TaxonomyManager(self::$db);
		$id = $taxonomy->createTerm(['taxonomy' => 'tag', 'name' => 'Deletable']);

		$request = $this->makeRequest('DELETE', "/api/v1/terms/{$id}");
		$response = $this->termsApi->destroy($request, ['id' => (string) $id]);

		$this->assertEquals(204, $response->getStatusCode());
	}

	// ---------------------------------------------------------------
	// Options API
	// ---------------------------------------------------------------

	public function test_set_and_get_option(): void
	{
		$request = $this->makeRequest('PUT', '/api/v1/options/site_name', jsonBody: json_encode([
			'value' => 'My Kreblu Site',
		]));

		$response = $this->optionsApi->update($request, ['key' => 'site_name']);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('My Kreblu Site', $data['data']['value']);

		// Read it back
		$getRequest = $this->makeRequest('GET', '/api/v1/options/site_name');
		$getResponse = $this->optionsApi->show($getRequest, ['key' => 'site_name']);
		$getData = $this->decodeResponse($getResponse);

		$this->assertEquals('My Kreblu Site', $getData['data']['value']);
	}

	public function test_set_option_json_value(): void
	{
		$request = $this->makeRequest('PUT', '/api/v1/options/theme_config', jsonBody: json_encode([
			'value' => ['primary_color' => '#ff0000', 'sidebar' => true],
		]));

		$response = $this->optionsApi->update($request, ['key' => 'theme_config']);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('#ff0000', $data['data']['value']['primary_color']);
	}

	public function test_list_options(): void
	{
		self::$db->table('options')->insert(['option_key' => 'opt_a', 'option_value' => 'val_a', 'autoload' => 1]);
		self::$db->table('options')->insert(['option_key' => 'opt_b', 'option_value' => 'val_b', 'autoload' => 0]);

		$request = $this->makeRequest('GET', '/api/v1/options');
		$response = $this->optionsApi->index($request);
		$data = $this->decodeResponse($response);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertCount(2, $data['data']);
	}

	public function test_delete_option(): void
	{
		self::$db->table('options')->insert(['option_key' => 'deletable', 'option_value' => 'value', 'autoload' => 1]);

		$request = $this->makeRequest('DELETE', '/api/v1/options/deletable');
		$response = $this->optionsApi->destroy($request, ['key' => 'deletable']);

		$this->assertEquals(204, $response->getStatusCode());

		// Verify it's gone
		$getResponse = $this->optionsApi->show(
			$this->makeRequest('GET', '/api/v1/options/deletable'),
			['key' => 'deletable'],
		);
		$this->assertEquals(404, $getResponse->getStatusCode());
	}

	public function test_get_nonexistent_option(): void
	{
		$request = $this->makeRequest('GET', '/api/v1/options/nonexistent');
		$response = $this->optionsApi->show($request, ['key' => 'nonexistent']);

		$this->assertEquals(404, $response->getStatusCode());
	}

	// ---------------------------------------------------------------
	// ApiAuth
	// ---------------------------------------------------------------

	public function test_api_auth_scope_levels(): void
	{
		$this->assertTrue(ApiAuth::scopeAllows('admin', 'read'));
		$this->assertTrue(ApiAuth::scopeAllows('admin', 'write'));
		$this->assertTrue(ApiAuth::scopeAllows('admin', 'admin'));
		$this->assertTrue(ApiAuth::scopeAllows('write', 'read'));
		$this->assertTrue(ApiAuth::scopeAllows('write', 'write'));
		$this->assertFalse(ApiAuth::scopeAllows('write', 'admin'));
		$this->assertTrue(ApiAuth::scopeAllows('read', 'read'));
		$this->assertFalse(ApiAuth::scopeAllows('read', 'write'));
	}
}
