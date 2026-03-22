<?php declare(strict_types=1);

namespace Kreblu\Core\Content;

use Kreblu\Core\Database\Connection;

/**
 * Post Manager
 *
 * CRUD operations for posts, pages, and custom post types.
 * Handles slug generation, status transitions, revision tracking,
 * and JSON metadata storage.
 *
 * Every update to a published or draft post creates a revision entry
 * in kb_revisions, keeping a complete edit history.
 *
 * WordPress developers: this replaces wp_insert_post, wp_update_post,
 * wp_delete_post, WP_Query, and the post-related functions.
 */
final class PostManager
{
	public function __construct(
		private readonly Connection $db,
		private readonly int $maxRevisions = 25,
	) {}

	// ---------------------------------------------------------------
	// Create
	// ---------------------------------------------------------------

	/**
	 * Create a new post.
	 *
	 * @param array{
	 *     title: string,
	 *     body?: string,
	 *     body_raw?: string,
	 *     excerpt?: string,
	 *     author_id: int,
	 *     type?: string,
	 *     status?: string,
	 *     slug?: string,
	 *     parent_id?: int|null,
	 *     menu_order?: int,
	 *     comment_status?: string,
	 *     meta?: array<string, mixed>|null,
	 *     featured_image?: int|null,
	 *     password?: string|null,
	 *     published_at?: string|null,
	 * } $data
	 * @return int The new post's ID
	 */
	public function create(array $data): int
	{
		$this->validateRequired($data, ['title', 'author_id']);

		$slug = $data['slug'] ?? $this->generateUniqueSlug($data['title'], $data['type'] ?? 'post');
		$status = $data['status'] ?? 'draft';

		$insertData = [
			'title'          => $data['title'],
			'slug'           => $slug,
			'body'           => $data['body'] ?? null,
			'body_raw'       => $data['body_raw'] ?? null,
			'excerpt'        => $data['excerpt'] ?? null,
			'author_id'      => $data['author_id'],
			'type'           => $data['type'] ?? 'post',
			'status'         => $status,
			'parent_id'      => $data['parent_id'] ?? null,
			'menu_order'     => $data['menu_order'] ?? 0,
			'comment_status' => $data['comment_status'] ?? 'open',
			'meta'           => isset($data['meta']) ? json_encode($data['meta']) : null,
			'featured_image' => $data['featured_image'] ?? null,
			'password'       => $data['password'] ?? null,
			'published_at'   => $data['published_at'] ?? ($status === 'published' ? date('Y-m-d H:i:s') : null),
		];

		return $this->db->table('posts')->insert($insertData);
	}

	// ---------------------------------------------------------------
	// Read
	// ---------------------------------------------------------------

	/**
	 * Get a post by ID.
	 */
	public function findById(int $id): ?object
	{
		return $this->db->table('posts')
			->where('id', '=', $id)
			->first();
	}

	/**
	 * Get a post by slug and type.
	 */
	public function findBySlug(string $slug, string $type = 'post'): ?object
	{
		return $this->db->table('posts')
			->where('slug', '=', $slug)
			->where('type', '=', $type)
			->first();
	}

	/**
	 * Query posts with flexible filters.
	 *
	 * @param array{
	 *     type?: string,
	 *     status?: string|array<int, string>,
	 *     author_id?: int,
	 *     parent_id?: int|null,
	 *     search?: string,
	 *     term_id?: int,
	 *     limit?: int,
	 *     offset?: int,
	 *     orderby?: string,
	 *     order?: string,
	 * } $args
	 * @return array<int, object>
	 */
	public function query(array $args = []): array
	{
		$query = $this->db->table('posts');

		if (isset($args['type'])) {
			$query->where('type', '=', $args['type']);
		}

		if (isset($args['status'])) {
			if (is_array($args['status'])) {
				$query->whereIn('status', $args['status']);
			} else {
				$query->where('status', '=', $args['status']);
			}
		}

		if (isset($args['author_id'])) {
			$query->where('author_id', '=', $args['author_id']);
		}

		if (array_key_exists('parent_id', $args)) {
			if ($args['parent_id'] === null) {
				$query->whereNull('parent_id');
			} else {
				$query->where('parent_id', '=', $args['parent_id']);
			}
		}

		if (isset($args['search']) && $args['search'] !== '') {
			$search = '%' . $args['search'] . '%';
			$query->where('title', 'LIKE', $search);
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
	 * Count posts matching filters.
	 *
	 * @param array{type?: string, status?: string, author_id?: int} $args
	 */
	public function count(array $args = []): int
	{
		$query = $this->db->table('posts');

		if (isset($args['type'])) {
			$query->where('type', '=', $args['type']);
		}

		if (isset($args['status'])) {
			$query->where('status', '=', $args['status']);
		}

		if (isset($args['author_id'])) {
			$query->where('author_id', '=', $args['author_id']);
		}

		return $query->count();
	}

	// ---------------------------------------------------------------
	// Update
	// ---------------------------------------------------------------

	/**
	 * Update an existing post.
	 *
	 * Creates a revision of the current state before applying changes.
	 *
	 * @param array<string, mixed> $data Fields to update
	 * @return bool True if the update affected a row
	 */
	public function update(int $id, array $data): bool
	{
		$existing = $this->findById($id);
		if ($existing === null) {
			return false;
		}

		// Create a revision of the current state before updating
		$this->createRevision($existing);

		$allowed = [
			'title', 'slug', 'body', 'body_raw', 'excerpt', 'status',
			'parent_id', 'menu_order', 'comment_status', 'featured_image',
			'password', 'published_at',
		];

		$updateData = [];
		foreach ($allowed as $field) {
			if (array_key_exists($field, $data)) {
				$updateData[$field] = $data[$field];
			}
		}

		// Handle meta separately (JSON encode)
		if (array_key_exists('meta', $data)) {
			$updateData['meta'] = $data['meta'] !== null ? json_encode($data['meta']) : null;
		}

		// If slug is being changed, ensure uniqueness
		if (isset($updateData['slug'])) {
			$updateData['slug'] = $this->generateUniqueSlug(
				$updateData['slug'],
				$existing->type,
				$id,
			);
		}

		// If status is changing to published and no published_at set
		if (isset($updateData['status']) && $updateData['status'] === 'published') {
			if (!isset($updateData['published_at']) && $existing->published_at === null) {
				$updateData['published_at'] = date('Y-m-d H:i:s');
			}
		}

		if (empty($updateData)) {
			return false;
		}

		$affected = $this->db->table('posts')
			->where('id', '=', $id)
			->update($updateData);

		// Prune old revisions
		$this->pruneRevisions($id);

		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Delete
	// ---------------------------------------------------------------

	/**
	 * Move a post to trash or permanently delete it.
	 *
	 * @param bool $force If true, permanently deletes. If false, moves to trash.
	 */
	public function delete(int $id, bool $force = false): bool
	{
		if ($force) {
			$affected = $this->db->table('posts')
				->where('id', '=', $id)
				->delete();
			return $affected > 0;
		}

		// Soft delete — move to trash
		$affected = $this->db->table('posts')
			->where('id', '=', $id)
			->update(['status' => 'trash']);
		return $affected > 0;
	}

	/**
	 * Restore a trashed post to draft status.
	 */
	public function restore(int $id): bool
	{
		$affected = $this->db->table('posts')
			->where('id', '=', $id)
			->where('status', '=', 'trash')
			->update(['status' => 'draft']);
		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Revisions
	// ---------------------------------------------------------------

	/**
	 * Get revisions for a post, newest first.
	 *
	 * @return array<int, object>
	 */
	public function getRevisions(int $postId): array
	{
		return $this->db->table('revisions')
			->where('post_id', '=', $postId)
			->orderBy('created_at', 'DESC')
			->get();
	}

	/**
	 * Create a revision snapshot of a post's current state.
	 */
	private function createRevision(object $post): void
	{
		$this->db->table('revisions')->insert([
			'post_id'   => $post->id,
			'author_id' => $post->author_id,
			'title'     => $post->title,
			'body'      => $post->body,
			'body_raw'  => $post->body_raw,
			'meta'      => $post->meta,
		]);
	}

	/**
	 * Remove old revisions beyond the configured maximum.
	 */
	private function pruneRevisions(int $postId): void
	{
		$revisions = $this->db->table('revisions')
			->select('id')
			->where('post_id', '=', $postId)
			->orderBy('created_at', 'DESC')
			->get();

		if (count($revisions) <= $this->maxRevisions) {
			return;
		}

		$toDelete = array_slice($revisions, $this->maxRevisions);
		$ids = array_map(fn(object $r) => $r->id, $toDelete);

		$this->db->table('revisions')
			->whereIn('id', $ids)
			->delete();
	}

	// ---------------------------------------------------------------
	// Slug generation
	// ---------------------------------------------------------------

	/**
	 * Generate a unique slug for a post.
	 *
	 * If the slug already exists for the same type, appends -2, -3, etc.
	 *
	 * @param int|null $excludeId Post ID to exclude from uniqueness check (for updates)
	 */
	public function generateUniqueSlug(string $source, string $type = 'post', ?int $excludeId = null): string
	{
		$slug = $this->slugify($source);

		if ($slug === '') {
			$slug = 'post-' . time();
		}

		$baseSlug = $slug;
		$counter = 1;

		while (true) {
			$query = $this->db->table('posts')
				->where('slug', '=', $slug)
				->where('type', '=', $type);

			if ($excludeId !== null) {
				$query->where('id', '!=', $excludeId);
			}

			if (!$query->exists()) {
				return $slug;
			}

			$counter++;
			$slug = $baseSlug . '-' . $counter;
		}
	}

	/**
	 * Convert a string to a URL-safe slug.
	 */
	private function slugify(string $text): string
	{
		$text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '';
		return trim(preg_replace('/-+/', '-', $text) ?? '', '-');
	}

	// ---------------------------------------------------------------
	// Meta helpers
	// ---------------------------------------------------------------

	/**
	 * Get a specific meta value from a post's JSON meta column.
	 */
	public function getMeta(int $postId, string $key, mixed $default = null): mixed
	{
		$post = $this->findById($postId);
		if ($post === null || $post->meta === null) {
			return $default;
		}

		$meta = json_decode($post->meta, true);
		return $meta[$key] ?? $default;
	}

	/**
	 * Update a specific meta value in a post's JSON meta column.
	 * Merges with existing meta rather than replacing it.
	 */
	public function setMeta(int $postId, string $key, mixed $value): bool
	{
		$post = $this->findById($postId);
		if ($post === null) {
			return false;
		}

		$meta = $post->meta !== null ? json_decode($post->meta, true) : [];
		$meta[$key] = $value;

		$affected = $this->db->table('posts')
			->where('id', '=', $postId)
			->update(['meta' => json_encode($meta)]);

		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Validation
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string> $required
	 */
	private function validateRequired(array $data, array $required): void
	{
		foreach ($required as $field) {
			if (!isset($data[$field]) || $data[$field] === '') {
				throw new \InvalidArgumentException("Field '{$field}' is required.");
			}
		}
	}
}
