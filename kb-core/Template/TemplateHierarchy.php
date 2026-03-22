<?php declare(strict_types=1);

namespace Kreblu\Core\Template;

/**
 * Template Hierarchy
 *
 * Determines which template file to use for a given request context.
 * Follows a fallback chain from most specific to most general,
 * similar to WordPress's template hierarchy.
 *
 * Example for a single post with slug "hello-world" of type "post":
 *   1. single-post-hello-world.php
 *   2. single-post.php
 *   3. single.php
 *   4. index.php
 *
 * The first template that exists in the active theme is used.
 */
final class TemplateHierarchy
{
	public function __construct(
		private readonly string $themePath,
	) {}

	/**
	 * Resolve the template for a single post/page/custom type.
	 *
	 * @return string|null The template name (dot notation) or null if none found
	 */
	public function resolveSingle(string $type, string $slug): ?string
	{
		$candidates = [
			"single-{$type}-{$slug}",
			"single-{$type}",
			'single',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for a page.
	 */
	public function resolvePage(string $slug, ?int $id = null): ?string
	{
		$candidates = [
			"page-{$slug}",
		];

		if ($id !== null) {
			$candidates[] = "page-{$id}";
		}

		$candidates[] = 'page';
		$candidates[] = 'index';

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for an archive (category, tag, custom taxonomy, post type archive).
	 */
	public function resolveArchive(string $type, ?string $slug = null): ?string
	{
		$candidates = [];

		if ($slug !== null) {
			$candidates[] = "archive-{$type}-{$slug}";
		}

		$candidates[] = "archive-{$type}";
		$candidates[] = 'archive';
		$candidates[] = 'index';

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for a category archive.
	 */
	public function resolveCategory(string $slug): ?string
	{
		$candidates = [
			"category-{$slug}",
			'category',
			'archive',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for a tag archive.
	 */
	public function resolveTag(string $slug): ?string
	{
		$candidates = [
			"tag-{$slug}",
			'tag',
			'archive',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for an author archive.
	 */
	public function resolveAuthor(string $username): ?string
	{
		$candidates = [
			"author-{$username}",
			'author',
			'archive',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for search results.
	 */
	public function resolveSearch(): ?string
	{
		$candidates = [
			'search',
			'archive',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the template for the home/front page.
	 */
	public function resolveHome(): ?string
	{
		$candidates = [
			'front-page',
			'home',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Resolve the 404 template.
	 */
	public function resolve404(): ?string
	{
		$candidates = [
			'404',
			'index',
		];

		return $this->findFirst($candidates);
	}

	/**
	 * Find the first template that exists from a list of candidates.
	 *
	 * @param array<int, string> $candidates Template names (without .php extension)
	 * @return string|null The first existing template name, or null
	 */
	private function findFirst(array $candidates): ?string
	{
		foreach ($candidates as $name) {
			$path = $this->themePath . '/' . $name . '.php';

			if (file_exists($path)) {
				return $name;
			}
		}

		return null;
	}
}
