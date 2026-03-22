<?php declare(strict_types=1);

namespace Kreblu\Core\Api\Endpoints;

use Kreblu\Core\Api\ApiController;
use Kreblu\Core\Content\TaxonomyManager;
use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;

/**
 * Terms API Endpoint
 *
 * GET    /api/v1/terms              — List terms (requires ?taxonomy=)
 * GET    /api/v1/terms/{id}         — Get single term
 * POST   /api/v1/terms              — Create term
 * PUT    /api/v1/terms/{id}         — Update term
 * DELETE /api/v1/terms/{id}         — Delete term
 */
final class TermsEndpoint extends ApiController
{
	public function __construct(
		private readonly TaxonomyManager $taxonomy,
	) {}

	public function index(Request $request): Response
	{
		$taxonomyName = $request->query('taxonomy');

		if ($taxonomyName === null || $taxonomyName === '') {
			return $this->validationError(['taxonomy' => 'The taxonomy query parameter is required.']);
		}

		$args = [];

		if ($request->query('parent_id') !== null) {
			$parentId = $request->query('parent_id');
			$args['parent_id'] = $parentId === 'null' ? null : (int) $parentId;
		}

		if ($request->query('hide_empty') === 'true') {
			$args['hide_empty'] = true;
		}

		$args['orderby'] = $request->query('orderby', 'name');
		$args['order'] = $request->query('order', 'ASC');

		$terms = $this->taxonomy->getTerms($taxonomyName, $args);

		return $this->success(array_map([$this, 'formatTerm'], $terms));
	}

	public function show(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$term = $this->taxonomy->findTermById($id);

		if ($term === null) {
			return $this->notFound('Term not found.');
		}

		return $this->success($this->formatTerm($term));
	}

	public function store(Request $request): Response
	{
		$input = $request->isJson() ? ($request->json() ?? []) : $request->all();

		$errors = $this->validateRequired(['taxonomy', 'name'], $input);
		if (!empty($errors)) {
			return $this->validationError($errors);
		}

		try {
			$id = $this->taxonomy->createTerm([
				'taxonomy'    => $input['taxonomy'],
				'name'        => $input['name'],
				'slug'        => $input['slug'] ?? null,
				'description' => $input['description'] ?? null,
				'parent_id'   => isset($input['parent_id']) ? (int) $input['parent_id'] : null,
				'meta'        => $input['meta'] ?? null,
			]);

			$term = $this->taxonomy->findTermById($id);
			return $this->created($this->formatTerm($term));
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
		}
	}

	public function update(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$term = $this->taxonomy->findTermById($id);

		if ($term === null) {
			return $this->notFound('Term not found.');
		}

		$input = $request->isJson() ? ($request->json() ?? []) : $request->all();

		$this->taxonomy->updateTerm($id, $input);
		$updated = $this->taxonomy->findTermById($id);

		return $this->success($this->formatTerm($updated));
	}

	public function destroy(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$term = $this->taxonomy->findTermById($id);

		if ($term === null) {
			return $this->notFound('Term not found.');
		}

		$this->taxonomy->deleteTerm($id);
		return $this->noContent();
	}

	private function formatTerm(?object $term): ?array
	{
		if ($term === null) {
			return null;
		}

		return [
			'id'          => (int) $term->id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent_id'   => $term->parent_id !== null ? (int) $term->parent_id : null,
			'count'       => (int) $term->count,
			'meta'        => $term->meta !== null ? json_decode($term->meta, true) : null,
		];
	}
}
