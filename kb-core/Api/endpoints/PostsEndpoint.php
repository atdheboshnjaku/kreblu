<?php declare(strict_types=1);

namespace Kreblu\Core\Api\Endpoints;

use Kreblu\Core\Api\ApiController;
use Kreblu\Core\Content\PostManager;
use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;

/**
 * Posts API Endpoint
 *
 * GET    /api/v1/posts          — List posts
 * GET    /api/v1/posts/{id}     — Get single post
 * POST   /api/v1/posts          — Create post
 * PUT    /api/v1/posts/{id}     — Update post
 * DELETE /api/v1/posts/{id}     — Delete post
 */
final class PostsEndpoint extends ApiController
{
	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * List posts with filters and pagination.
	 */
	public function index(Request $request): Response
	{
		$pagination = $this->paginationParams($request);

		$args = [
			'limit'  => $pagination['per_page'],
			'offset' => $pagination['offset'],
		];

		if ($request->query('type') !== null) {
			$args['type'] = $request->query('type');
		}

		if ($request->query('status') !== null) {
			$args['status'] = $request->query('status');
		}

		if ($request->query('author_id') !== null) {
			$args['author_id'] = (int) $request->query('author_id');
		}

		if ($request->query('search') !== null) {
			$args['search'] = $request->query('search');
		}

		$orderBy = $request->query('orderby', 'created_at');
		$order = $request->query('order', 'DESC');
		$args['orderby'] = $orderBy;
		$args['order'] = $order;

		$items = $this->posts->query($args);
		$total = $this->posts->count(array_intersect_key($args, array_flip(['type', 'status', 'author_id'])));

		return $this->paginated(
			array_map([$this, 'formatPost'], $items),
			$total,
			$pagination['page'],
			$pagination['per_page'],
		);
	}

	/**
	 * Get a single post by ID.
	 *
	 * @param array<string, string> $params Route parameters
	 */
	public function show(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$post = $this->posts->findById($id);

		if ($post === null) {
			return $this->notFound('Post not found.');
		}

		return $this->success($this->formatPost($post));
	}

	/**
	 * Create a new post.
	 */
	public function store(Request $request): Response
	{
		$input = $request->isJson() ? ($request->json() ?? []) : $request->all();

		$errors = $this->validateRequired(['title'], $input);
		if (!empty($errors)) {
			return $this->validationError($errors);
		}

		if (!isset($input['author_id'])) {
			return $this->validationError(['author_id' => 'The author_id field is required.']);
		}

		try {
			$id = $this->posts->create([
				'title'          => $input['title'],
				'body'           => $input['body'] ?? null,
				'body_raw'       => $input['body_raw'] ?? null,
				'excerpt'        => $input['excerpt'] ?? null,
				'author_id'      => (int) $input['author_id'],
				'type'           => $input['type'] ?? 'post',
				'status'         => $input['status'] ?? 'draft',
				'slug'           => $input['slug'] ?? null,
				'meta'           => $input['meta'] ?? null,
				'featured_image' => isset($input['featured_image']) ? (int) $input['featured_image'] : null,
				'published_at'   => $input['published_at'] ?? null,
			]);

			$post = $this->posts->findById($id);
			return $this->created($this->formatPost($post));
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
		}
	}

	/**
	 * Update an existing post.
	 *
	 * @param array<string, string> $params Route parameters
	 */
	public function update(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$post = $this->posts->findById($id);

		if ($post === null) {
			return $this->notFound('Post not found.');
		}

		$input = $request->isJson() ? ($request->json() ?? []) : $request->all();

		try {
			$this->posts->update($id, $input);
			$updated = $this->posts->findById($id);
			return $this->success($this->formatPost($updated));
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
		}
	}

	/**
	 * Delete a post.
	 *
	 * @param array<string, string> $params Route parameters
	 */
	public function destroy(Request $request, array $params): Response
	{
		$id = (int) ($params['id'] ?? 0);
		$post = $this->posts->findById($id);

		if ($post === null) {
			return $this->notFound('Post not found.');
		}

		$force = $request->query('force', 'false') === 'true';
		$this->posts->delete($id, $force);

		return $this->noContent();
	}

	/**
	 * Format a post object for API output.
	 */
	private function formatPost(?object $post): ?array
	{
		if ($post === null) {
			return null;
		}

		return [
			'id'             => (int) $post->id,
			'type'           => $post->type,
			'status'         => $post->status,
			'title'          => $post->title,
			'slug'           => $post->slug,
			'body'           => $post->body,
			'excerpt'        => $post->excerpt,
			'author_id'      => (int) $post->author_id,
			'parent_id'      => $post->parent_id !== null ? (int) $post->parent_id : null,
			'featured_image' => $post->featured_image !== null ? (int) $post->featured_image : null,
			'comment_status' => $post->comment_status,
			'meta'           => $post->meta !== null ? json_decode($post->meta, true) : null,
			'published_at'   => $post->published_at,
			'created_at'     => $post->created_at,
			'updated_at'     => $post->updated_at,
		];
	}
}
