<?php declare(strict_types=1);

namespace Kreblu\Core\Content;

use Kreblu\Core\Database\Connection;

/**
 * Comment Manager
 *
 * CRUD for comments with threading (parent_id) and moderation
 * (pending, approved, spam, trash statuses).
 */
final class CommentManager
{
	public function __construct(
		private readonly Connection $db,
	) {}

	/**
	 * Create a new comment.
	 *
	 * @param array{
	 *     post_id: int,
	 *     body: string,
	 *     user_id?: int|null,
	 *     parent_id?: int|null,
	 *     author_name?: string|null,
	 *     author_email?: string|null,
	 *     author_url?: string|null,
	 *     author_ip?: string|null,
	 *     status?: string,
	 * } $data
	 * @return int The new comment's ID
	 */
	public function create(array $data): int
	{
		if (!isset($data['post_id']) || !isset($data['body']) || trim($data['body']) === '') {
			throw new \InvalidArgumentException('post_id and body are required.');
		}

		return $this->db->table('comments')->insert([
			'post_id'      => $data['post_id'],
			'body'         => trim($data['body']),
			'user_id'      => $data['user_id'] ?? null,
			'parent_id'    => $data['parent_id'] ?? null,
			'author_name'  => $data['author_name'] ?? null,
			'author_email' => $data['author_email'] ?? null,
			'author_url'   => $data['author_url'] ?? null,
			'author_ip'    => $data['author_ip'] ?? null,
			'status'       => $data['status'] ?? 'pending',
		]);
	}

	/**
	 * Get a comment by ID.
	 */
	public function findById(int $id): ?object
	{
		return $this->db->table('comments')
			->where('id', '=', $id)
			->first();
	}

	/**
	 * Get comments for a post.
	 *
	 * @param array{status?: string, parent_id?: int|null, limit?: int, offset?: int, order?: string} $args
	 * @return array<int, object>
	 */
	public function getForPost(int $postId, array $args = []): array
	{
		$query = $this->db->table('comments')
			->where('post_id', '=', $postId);

		if (isset($args['status'])) {
			$query->where('status', '=', $args['status']);
		}

		if (array_key_exists('parent_id', $args)) {
			if ($args['parent_id'] === null) {
				$query->whereNull('parent_id');
			} else {
				$query->where('parent_id', '=', $args['parent_id']);
			}
		}

		$order = strtoupper($args['order'] ?? 'ASC');
		if ($order !== 'ASC' && $order !== 'DESC') {
			$order = 'ASC';
		}
		$query->orderBy('created_at', $order);

		if (isset($args['limit'])) {
			$query->limit((int) $args['limit']);
		}

		if (isset($args['offset'])) {
			$query->offset((int) $args['offset']);
		}

		return $query->get();
	}

	/**
	 * Update a comment's moderation status.
	 */
	public function setStatus(int $id, string $status): bool
	{
		$valid = ['pending', 'approved', 'spam', 'trash'];
		if (!in_array($status, $valid, true)) {
			throw new \InvalidArgumentException("Invalid comment status: {$status}");
		}

		$affected = $this->db->table('comments')
			->where('id', '=', $id)
			->update(['status' => $status]);

		return $affected > 0;
	}

	/**
	 * Update a comment's body.
	 */
	public function updateBody(int $id, string $body): bool
	{
		$affected = $this->db->table('comments')
			->where('id', '=', $id)
			->update(['body' => trim($body)]);

		return $affected > 0;
	}

	/**
	 * Permanently delete a comment and its replies (cascade).
	 */
	public function delete(int $id): bool
	{
		$affected = $this->db->table('comments')
			->where('id', '=', $id)
			->delete();

		return $affected > 0;
	}

	/**
	 * Count comments for a post, optionally by status.
	 */
	public function countForPost(int $postId, ?string $status = null): int
	{
		$query = $this->db->table('comments')
			->where('post_id', '=', $postId);

		if ($status !== null) {
			$query->where('status', '=', $status);
		}

		return $query->count();
	}

	/**
	 * Count all comments by status.
	 */
	public function countByStatus(string $status): int
	{
		return $this->db->table('comments')
			->where('status', '=', $status)
			->count();
	}

	/**
	 * Get recent comments across all posts.
	 *
	 * @return array<int, object>
	 */
	public function getRecent(int $limit = 10, ?string $status = null): array
	{
		$query = $this->db->table('comments');

		if ($status !== null) {
			$query->where('status', '=', $status);
		}

		return $query
			->orderBy('created_at', 'DESC')
			->limit($limit)
			->get();
	}
}
