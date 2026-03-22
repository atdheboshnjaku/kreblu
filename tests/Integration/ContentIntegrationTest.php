<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Auth\AuthManager;
use Kreblu\Core\Content\PostManager;
use Kreblu\Core\Content\TaxonomyManager;
use Kreblu\Core\Content\CommentManager;
use Kreblu\Core\Database\Connection;
use Kreblu\Core\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Content system.
 */
final class ContentIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private static ?PostManager $posts = null;
	private static ?TaxonomyManager $taxonomy = null;
	private static ?CommentManager $comments = null;
	private static int $testUserId = 0;

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

			self::$posts = new PostManager(self::$db);
			self::$taxonomy = new TaxonomyManager(self::$db);
			self::$comments = new CommentManager(self::$db);

			// Create a test user for author_id references
			$auth = new AuthManager(self::$db, bcryptCost: 4);
			self::$testUserId = self::$db->table('users')->insert([
				'email'         => 'content_test@example.com',
				'username'      => 'content_tester',
				'password_hash' => $auth->hashPassword('TestPass123'),
				'display_name'  => 'Content Tester',
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

		// Clear content tables before each test (order matters for foreign keys)
		self::$db->execute('DELETE FROM ' . self::$db->tableName('comments'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('term_relationships'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('revisions'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('posts'));
		self::$db->execute('DELETE FROM ' . self::$db->tableName('terms'));
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
	private function posts(): PostManager { return self::$posts; }
	private function taxonomy(): TaxonomyManager { return self::$taxonomy; }
	private function comments(): CommentManager { return self::$comments; }
	private function userId(): int { return self::$testUserId; }

	// ---------------------------------------------------------------
	// PostManager tests
	// ---------------------------------------------------------------

	public function test_create_post(): void
	{
		$id = $this->posts()->create([
			'title'     => 'My First Post',
			'body'      => '<p>Hello world</p>',
			'author_id' => $this->userId(),
		]);

		$this->assertGreaterThan(0, $id);
	}

	public function test_create_post_auto_generates_slug(): void
	{
		$id = $this->posts()->create([
			'title'     => 'Hello World Post',
			'author_id' => $this->userId(),
		]);

		$post = $this->posts()->findById($id);
		$this->assertEquals('hello-world-post', $post->slug);
	}

	public function test_create_post_unique_slug(): void
	{
		$this->posts()->create(['title' => 'Same Title', 'author_id' => $this->userId()]);
		$id2 = $this->posts()->create(['title' => 'Same Title', 'author_id' => $this->userId()]);

		$post2 = $this->posts()->findById($id2);
		$this->assertEquals('same-title-2', $post2->slug);
	}

	public function test_create_post_default_status_is_draft(): void
	{
		$id = $this->posts()->create(['title' => 'Draft Post', 'author_id' => $this->userId()]);
		$post = $this->posts()->findById($id);
		$this->assertEquals('draft', $post->status);
	}

	public function test_create_published_post_sets_published_at(): void
	{
		$id = $this->posts()->create([
			'title'     => 'Published Post',
			'author_id' => $this->userId(),
			'status'    => 'published',
		]);

		$post = $this->posts()->findById($id);
		$this->assertNotNull($post->published_at);
	}

	public function test_create_post_with_meta(): void
	{
		$id = $this->posts()->create([
			'title'     => 'Meta Post',
			'author_id' => $this->userId(),
			'meta'      => ['seo_title' => 'Custom Title', 'featured' => true],
		]);

		$post = $this->posts()->findById($id);
		$meta = json_decode($post->meta, true);
		$this->assertEquals('Custom Title', $meta['seo_title']);
		$this->assertTrue($meta['featured']);
	}

	public function test_create_page(): void
	{
		$id = $this->posts()->create([
			'title'     => 'About Us',
			'author_id' => $this->userId(),
			'type'      => 'page',
		]);

		$post = $this->posts()->findById($id);
		$this->assertEquals('page', $post->type);
	}

	public function test_find_by_slug(): void
	{
		$this->posts()->create([
			'title'     => 'Findable Post',
			'author_id' => $this->userId(),
		]);

		$post = $this->posts()->findBySlug('findable-post');
		$this->assertNotNull($post);
		$this->assertEquals('Findable Post', $post->title);
	}

	public function test_query_by_status(): void
	{
		$this->posts()->create(['title' => 'Draft', 'author_id' => $this->userId(), 'status' => 'draft']);
		$this->posts()->create(['title' => 'Published', 'author_id' => $this->userId(), 'status' => 'published']);
		$this->posts()->create(['title' => 'Also Published', 'author_id' => $this->userId(), 'status' => 'published']);

		$published = $this->posts()->query(['status' => 'published']);
		$this->assertCount(2, $published);
	}

	public function test_query_by_type(): void
	{
		$this->posts()->create(['title' => 'Post', 'author_id' => $this->userId(), 'type' => 'post']);
		$this->posts()->create(['title' => 'Page', 'author_id' => $this->userId(), 'type' => 'page']);

		$pages = $this->posts()->query(['type' => 'page']);
		$this->assertCount(1, $pages);
	}

	public function test_query_with_limit_and_offset(): void
	{
		for ($i = 1; $i <= 5; $i++) {
			$this->posts()->create(['title' => "Post {$i}", 'author_id' => $this->userId()]);
		}

		$page = $this->posts()->query(['limit' => 2, 'offset' => 2, 'orderby' => 'id', 'order' => 'ASC']);
		$this->assertCount(2, $page);
		$this->assertEquals('Post 3', $page[0]->title);
	}

	public function test_query_search(): void
	{
		$this->posts()->create(['title' => 'PHP Tutorial', 'author_id' => $this->userId()]);
		$this->posts()->create(['title' => 'JavaScript Guide', 'author_id' => $this->userId()]);

		$results = $this->posts()->query(['search' => 'PHP']);
		$this->assertCount(1, $results);
		$this->assertEquals('PHP Tutorial', $results[0]->title);
	}

	public function test_count_posts(): void
	{
		$this->posts()->create(['title' => 'A', 'author_id' => $this->userId(), 'status' => 'published']);
		$this->posts()->create(['title' => 'B', 'author_id' => $this->userId(), 'status' => 'draft']);

		$this->assertEquals(2, $this->posts()->count());
		$this->assertEquals(1, $this->posts()->count(['status' => 'published']));
	}

	public function test_update_post(): void
	{
		$id = $this->posts()->create(['title' => 'Original', 'author_id' => $this->userId()]);

		$this->posts()->update($id, ['title' => 'Updated']);

		$post = $this->posts()->findById($id);
		$this->assertEquals('Updated', $post->title);
	}

	public function test_update_creates_revision(): void
	{
		$id = $this->posts()->create(['title' => 'Before Edit', 'body' => 'Original body', 'author_id' => $this->userId()]);

		$this->posts()->update($id, ['title' => 'After Edit']);

		$revisions = $this->posts()->getRevisions($id);
		$this->assertCount(1, $revisions);
		$this->assertEquals('Before Edit', $revisions[0]->title);
		$this->assertEquals('Original body', $revisions[0]->body);
	}

	public function test_soft_delete(): void
	{
		$id = $this->posts()->create(['title' => 'Trashable', 'author_id' => $this->userId()]);

		$this->posts()->delete($id);

		$post = $this->posts()->findById($id);
		$this->assertEquals('trash', $post->status);
	}

	public function test_hard_delete(): void
	{
		$id = $this->posts()->create(['title' => 'Gone Forever', 'author_id' => $this->userId()]);

		$this->posts()->delete($id, force: true);

		$this->assertNull($this->posts()->findById($id));
	}

	public function test_restore_trashed_post(): void
	{
		$id = $this->posts()->create(['title' => 'Restorable', 'author_id' => $this->userId()]);
		$this->posts()->delete($id);

		$this->posts()->restore($id);

		$post = $this->posts()->findById($id);
		$this->assertEquals('draft', $post->status);
	}

	public function test_get_and_set_meta(): void
	{
		$id = $this->posts()->create(['title' => 'Meta Test', 'author_id' => $this->userId()]);

		$this->posts()->setMeta($id, 'seo_title', 'Custom SEO');
		$this->posts()->setMeta($id, 'featured', true);

		$this->assertEquals('Custom SEO', $this->posts()->getMeta($id, 'seo_title'));
		$this->assertTrue($this->posts()->getMeta($id, 'featured'));
		$this->assertEquals('fallback', $this->posts()->getMeta($id, 'missing', 'fallback'));
	}

	// ---------------------------------------------------------------
	// TaxonomyManager tests
	// ---------------------------------------------------------------

	public function test_create_category(): void
	{
		$id = $this->taxonomy()->createTerm([
			'taxonomy' => 'category',
			'name'     => 'Technology',
		]);

		$this->assertGreaterThan(0, $id);

		$term = $this->taxonomy()->findTermById($id);
		$this->assertEquals('Technology', $term->name);
		$this->assertEquals('technology', $term->slug);
	}

	public function test_create_tag(): void
	{
		$id = $this->taxonomy()->createTerm([
			'taxonomy' => 'tag',
			'name'     => 'PHP 8.5',
		]);

		$term = $this->taxonomy()->findTermById($id);
		$this->assertEquals('php-8-5', $term->slug);
	}

	public function test_find_term_by_slug(): void
	{
		$this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'News']);

		$term = $this->taxonomy()->findTermBySlug('news', 'category');
		$this->assertNotNull($term);
		$this->assertEquals('News', $term->name);
	}

	public function test_get_terms_for_taxonomy(): void
	{
		$this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Alpha']);
		$this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Beta']);
		$this->taxonomy()->createTerm(['taxonomy' => 'tag', 'name' => 'Gamma']);

		$categories = $this->taxonomy()->getTerms('category');
		$this->assertCount(2, $categories);

		$tags = $this->taxonomy()->getTerms('tag');
		$this->assertCount(1, $tags);
	}

	public function test_assign_and_get_post_terms(): void
	{
		$postId = $this->posts()->create(['title' => 'Tagged Post', 'author_id' => $this->userId()]);
		$catId = $this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Tech']);
		$tagId = $this->taxonomy()->createTerm(['taxonomy' => 'tag', 'name' => 'PHP']);

		$this->taxonomy()->assignTerm($postId, $catId);
		$this->taxonomy()->assignTerm($postId, $tagId);

		$allTerms = $this->taxonomy()->getPostTerms($postId);
		$this->assertCount(2, $allTerms);

		$categories = $this->taxonomy()->getPostTerms($postId, 'category');
		$this->assertCount(1, $categories);
		$this->assertEquals('Tech', $categories[0]->name);
	}

	public function test_assign_term_updates_count(): void
	{
		$postId = $this->posts()->create(['title' => 'Counted', 'author_id' => $this->userId()]);
		$termId = $this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Counted Cat']);

		$this->taxonomy()->assignTerm($postId, $termId);

		$term = $this->taxonomy()->findTermById($termId);
		$this->assertEquals(1, $term->count);
	}

	public function test_remove_term_updates_count(): void
	{
		$postId = $this->posts()->create(['title' => 'Uncount', 'author_id' => $this->userId()]);
		$termId = $this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Will Remove']);

		$this->taxonomy()->assignTerm($postId, $termId);
		$this->taxonomy()->removeTerm($postId, $termId);

		$term = $this->taxonomy()->findTermById($termId);
		$this->assertEquals(0, $term->count);
	}

	public function test_set_post_terms_replaces(): void
	{
		$postId = $this->posts()->create(['title' => 'Replace Test', 'author_id' => $this->userId()]);
		$cat1 = $this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'Old Cat']);
		$cat2 = $this->taxonomy()->createTerm(['taxonomy' => 'category', 'name' => 'New Cat']);

		$this->taxonomy()->assignTerm($postId, $cat1);
		$this->taxonomy()->setPostTerms($postId, 'category', [$cat2]);

		$terms = $this->taxonomy()->getPostTerms($postId, 'category');
		$this->assertCount(1, $terms);
		$this->assertEquals('New Cat', $terms[0]->name);
	}

	public function test_delete_term(): void
	{
		$id = $this->taxonomy()->createTerm(['taxonomy' => 'tag', 'name' => 'Deletable']);
		$this->assertTrue($this->taxonomy()->deleteTerm($id));
		$this->assertNull($this->taxonomy()->findTermById($id));
	}

	// ---------------------------------------------------------------
	// CommentManager tests
	// ---------------------------------------------------------------

	public function test_create_comment(): void
	{
		$postId = $this->posts()->create(['title' => 'Commented Post', 'author_id' => $this->userId()]);

		$commentId = $this->comments()->create([
			'post_id'      => $postId,
			'body'         => 'Great post!',
			'author_name'  => 'Visitor',
			'author_email' => 'visitor@example.com',
		]);

		$this->assertGreaterThan(0, $commentId);
	}

	public function test_get_comments_for_post(): void
	{
		$postId = $this->posts()->create(['title' => 'Multi Comment', 'author_id' => $this->userId()]);

		$this->comments()->create(['post_id' => $postId, 'body' => 'First', 'status' => 'approved']);
		$this->comments()->create(['post_id' => $postId, 'body' => 'Second', 'status' => 'approved']);
		$this->comments()->create(['post_id' => $postId, 'body' => 'Pending', 'status' => 'pending']);

		$all = $this->comments()->getForPost($postId);
		$this->assertCount(3, $all);

		$approved = $this->comments()->getForPost($postId, ['status' => 'approved']);
		$this->assertCount(2, $approved);
	}

	public function test_comment_threading(): void
	{
		$postId = $this->posts()->create(['title' => 'Threaded', 'author_id' => $this->userId()]);

		$parentId = $this->comments()->create(['post_id' => $postId, 'body' => 'Parent comment']);
		$childId = $this->comments()->create(['post_id' => $postId, 'body' => 'Reply', 'parent_id' => $parentId]);

		$child = $this->comments()->findById($childId);
		$this->assertEquals($parentId, $child->parent_id);

		// Get only top-level comments
		$topLevel = $this->comments()->getForPost($postId, ['parent_id' => null]);
		$this->assertCount(1, $topLevel);
	}

	public function test_moderate_comment(): void
	{
		$postId = $this->posts()->create(['title' => 'Moderated', 'author_id' => $this->userId()]);
		$commentId = $this->comments()->create(['post_id' => $postId, 'body' => 'Spam content']);

		$this->comments()->setStatus($commentId, 'spam');

		$comment = $this->comments()->findById($commentId);
		$this->assertEquals('spam', $comment->status);
	}

	public function test_invalid_status_throws(): void
	{
		$postId = $this->posts()->create(['title' => 'Invalid', 'author_id' => $this->userId()]);
		$commentId = $this->comments()->create(['post_id' => $postId, 'body' => 'Test']);

		$this->expectException(\InvalidArgumentException::class);
		$this->comments()->setStatus($commentId, 'nonexistent');
	}

	public function test_count_comments(): void
	{
		$postId = $this->posts()->create(['title' => 'Count Test', 'author_id' => $this->userId()]);

		$this->comments()->create(['post_id' => $postId, 'body' => 'A', 'status' => 'approved']);
		$this->comments()->create(['post_id' => $postId, 'body' => 'B', 'status' => 'approved']);
		$this->comments()->create(['post_id' => $postId, 'body' => 'C', 'status' => 'pending']);

		$this->assertEquals(3, $this->comments()->countForPost($postId));
		$this->assertEquals(2, $this->comments()->countForPost($postId, 'approved'));
	}

	public function test_delete_comment(): void
	{
		$postId = $this->posts()->create(['title' => 'Delete Comment', 'author_id' => $this->userId()]);
		$commentId = $this->comments()->create(['post_id' => $postId, 'body' => 'Bye']);

		$this->assertTrue($this->comments()->delete($commentId));
		$this->assertNull($this->comments()->findById($commentId));
	}

	public function test_recent_comments(): void
	{
		$postId = $this->posts()->create(['title' => 'Recent', 'author_id' => $this->userId()]);

		$this->comments()->create(['post_id' => $postId, 'body' => 'First', 'status' => 'approved']);
		$this->comments()->create(['post_id' => $postId, 'body' => 'Second', 'status' => 'approved']);

		$recent = $this->comments()->getRecent(5, 'approved');
		$this->assertCount(2, $recent);

		// Both created in the same second, so just verify we get both back
		$bodies = array_map(fn(object $c) => $c->body, $recent);
		$this->assertContains('First', $bodies);
		$this->assertContains('Second', $bodies);
	}
}
