<?php declare(strict_types=1);

namespace Kreblu\Core\Content;

use Kreblu\Core\Database\Connection;

/**
 * Taxonomy Manager
 *
 * CRUD for terms (categories, tags, custom taxonomies) and their
 * relationships to posts. Terms are stored in kb_terms and relationships
 * in kb_term_relationships.
 */
final class TaxonomyManager
{
	public function __construct(
		private readonly Connection $db,
	) {}

	// ---------------------------------------------------------------
	// Term CRUD
	// ---------------------------------------------------------------

	/**
	 * Create a new term.
	 *
	 * @param array{taxonomy: string, name: string, slug?: string, description?: string, parent_id?: int|null, meta?: array<string, mixed>|null} $data
	 * @return int The new term's ID
	 */
	public function createTerm(array $data): int
	{
		if (!isset($data['taxonomy']) || !isset($data['name']) || trim($data['name']) === '') {
			throw new \InvalidArgumentException('Taxonomy and name are required.');
		}

		$slug = $data['slug'] ?? $this->generateUniqueSlug($data['name'], $data['taxonomy']);

		return $this->db->table('terms')->insert([
			'taxonomy'    => $data['taxonomy'],
			'name'        => trim($data['name']),
			'slug'        => $slug,
			'description' => $data['description'] ?? null,
			'parent_id'   => $data['parent_id'] ?? null,
			'meta'        => isset($data['meta']) ? json_encode($data['meta']) : null,
		]);
	}

	/**
	 * Update a term.
	 *
	 * @param array<string, mixed> $data Fields to update
	 */
	public function updateTerm(int $id, array $data): bool
	{
		$allowed = ['name', 'slug', 'description', 'parent_id', 'sort_order'];
		$updateData = [];

		foreach ($allowed as $field) {
			if (array_key_exists($field, $data)) {
				$updateData[$field] = $data[$field];
			}
		}

		if (array_key_exists('meta', $data)) {
			$updateData['meta'] = $data['meta'] !== null ? json_encode($data['meta']) : null;
		}

		if (empty($updateData)) {
			return false;
		}

		$affected = $this->db->table('terms')
			->where('id', '=', $id)
			->update($updateData);

		return $affected > 0;
	}

	/**
	 * Delete a term and all its relationships.
	 */
	public function deleteTerm(int $id): bool
	{
		// Relationships are deleted by CASCADE foreign key
		$affected = $this->db->table('terms')
			->where('id', '=', $id)
			->delete();

		return $affected > 0;
	}

	/**
	 * Find a term by ID.
	 */
	public function findTermById(int $id): ?object
	{
		return $this->db->table('terms')
			->where('id', '=', $id)
			->first();
	}

	/**
	 * Find a term by slug and taxonomy.
	 */
	public function findTermBySlug(string $slug, string $taxonomy): ?object
	{
		return $this->db->table('terms')
			->where('slug', '=', $slug)
			->where('taxonomy', '=', $taxonomy)
			->first();
	}

	/**
	 * Get all terms for a taxonomy.
	 *
	 * @param array{parent_id?: int|null, orderby?: string, order?: string, hide_empty?: bool} $args
	 * @return array<int, object>
	 */
	public function getTerms(string $taxonomy, array $args = []): array
	{
		$query = $this->db->table('terms')
			->where('taxonomy', '=', $taxonomy);

		if (array_key_exists('parent_id', $args)) {
			if ($args['parent_id'] === null) {
				$query->whereNull('parent_id');
			} else {
				$query->where('parent_id', '=', $args['parent_id']);
			}
		}

		if (isset($args['hide_empty']) && $args['hide_empty'] === true) {
			$query->where('count', '>', 0);
		}

		$orderBy = $args['orderby'] ?? 'name';
		$order = strtoupper($args['order'] ?? 'ASC');
		if ($order !== 'ASC' && $order !== 'DESC') {
			$order = 'ASC';
		}
		$query->orderBy($orderBy, $order);

		return $query->get();
	}

	/**
	 * Count terms in a taxonomy.
	 */
	public function countTerms(string $taxonomy): int
	{
		return $this->db->table('terms')
			->where('taxonomy', '=', $taxonomy)
			->count();
	}

	// ---------------------------------------------------------------
	// Term-Post Relationships
	// ---------------------------------------------------------------

	/**
	 * Assign a term to a post.
	 */
	public function assignTerm(int $postId, int $termId): void
	{
		// Check if already assigned
		$exists = $this->db->table('term_relationships')
			->where('post_id', '=', $postId)
			->where('term_id', '=', $termId)
			->exists();

		if ($exists) {
			return;
		}

		$this->db->table('term_relationships')->insert([
			'post_id' => $postId,
			'term_id' => $termId,
		]);

		$this->updateTermCount($termId);
	}

	/**
	 * Remove a term from a post.
	 */
	public function removeTerm(int $postId, int $termId): void
	{
		$this->db->table('term_relationships')
			->where('post_id', '=', $postId)
			->where('term_id', '=', $termId)
			->delete();

		$this->updateTermCount($termId);
	}

	/**
	 * Set the terms for a post in a specific taxonomy.
	 * Replaces all existing term assignments for that taxonomy.
	 *
	 * @param array<int, int> $termIds
	 */
	public function setPostTerms(int $postId, string $taxonomy, array $termIds): void
	{
		// Get current terms for this taxonomy
		$currentTerms = $this->getPostTerms($postId, $taxonomy);
		$currentIds = array_map(fn(object $t) => (int) $t->id, $currentTerms);

		// Remove terms that are no longer assigned
		$toRemove = array_diff($currentIds, $termIds);
		foreach ($toRemove as $termId) {
			$this->removeTerm($postId, $termId);
		}

		// Add new terms
		$toAdd = array_diff($termIds, $currentIds);
		foreach ($toAdd as $termId) {
			$this->assignTerm($postId, $termId);
		}
	}

	/**
	 * Get all terms assigned to a post, optionally filtered by taxonomy.
	 *
	 * @return array<int, object>
	 */
	public function getPostTerms(int $postId, ?string $taxonomy = null): array
	{
		$prefix = $this->db->prefix();

		$sql = "SELECT t.* FROM {$prefix}terms t "
			. "INNER JOIN {$prefix}term_relationships tr ON t.id = tr.term_id "
			. "WHERE tr.post_id = ?";
		$params = [$postId];

		if ($taxonomy !== null) {
			$sql .= " AND t.taxonomy = ?";
			$params[] = $taxonomy;
		}

		$sql .= " ORDER BY tr.sort_order ASC, t.name ASC";

		return $this->db->raw($sql, $params);
	}

	/**
	 * Get all post IDs that have a specific term assigned.
	 *
	 * @return array<int, int>
	 */
	public function getPostsByTerm(int $termId): array
	{
		$results = $this->db->table('term_relationships')
			->select('post_id')
			->where('term_id', '=', $termId)
			->get();

		return array_map(fn(object $r) => (int) $r->post_id, $results);
	}

	// ---------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------

	/**
	 * Update the cached post count for a term.
	 */
	private function updateTermCount(int $termId): void
	{
		$count = $this->db->table('term_relationships')
			->where('term_id', '=', $termId)
			->count();

		$this->db->table('terms')
			->where('id', '=', $termId)
			->update(['count' => $count]);
	}

	/**
	 * Generate a unique slug for a term within its taxonomy.
	 */
	private function generateUniqueSlug(string $name, string $taxonomy): string
	{
		$slug = $this->slugify($name);

		if ($slug === '') {
			$slug = 'term-' . time();
		}

		$baseSlug = $slug;
		$counter = 1;

		while ($this->findTermBySlug($slug, $taxonomy) !== null) {
			$counter++;
			$slug = $baseSlug . '-' . $counter;
		}

		return $slug;
	}

	private function slugify(string $text): string
	{
		$text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '';
		return trim(preg_replace('/-+/', '-', $text) ?? '', '-');
	}
}
