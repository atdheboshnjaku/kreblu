<?php declare(strict_types=1);

namespace Kreblu\Core\Security;

/**
 * Content Security Policy Builder
 *
 * Builds CSP headers to control which resources the browser is allowed to load.
 * Kreblu sets sensible defaults that kaps (augments) can extend via hooks.
 *
 * Usage:
 *   $csp = new CSP();
 *   $csp->addDirective('script-src', 'https://cdn.example.com');
 *   header('Content-Security-Policy: ' . $csp->build());
 */
final class CSP
{
	/**
	 * @var array<string, array<int, string>> Directive name => list of sources
	 */
	private array $directives = [];

	/**
	 * Create a CSP builder with Kreblu's default policy.
	 */
	public static function defaults(): self
	{
		$csp = new self();

		$csp->directives = [
			'default-src' => ["'self'"],
			'script-src'  => ["'self'"],
			'style-src'   => ["'self'", "'unsafe-inline'"],  // inline styles needed for editor
			'img-src'     => ["'self'", 'data:', 'https:'],  // allow external images and data URIs
			'font-src'    => ["'self'", 'data:'],
			'connect-src' => ["'self'"],                     // XHR/fetch destinations
			'media-src'   => ["'self'"],
			'object-src'  => ["'none'"],                     // block plugins (Flash, etc.)
			'frame-src'   => ["'self'"],                     // allow iframes from same origin
			'base-uri'    => ["'self'"],
			'form-action' => ["'self'"],
		];

		return $csp;
	}

	/**
	 * Add a source to a directive.
	 *
	 * If the directive doesn't exist yet, it's created.
	 * Duplicate sources are ignored.
	 *
	 * @param string $directive The CSP directive (e.g., 'script-src', 'img-src')
	 * @param string $source The source to allow (e.g., 'https://cdn.example.com', "'nonce-abc123'")
	 */
	public function addDirective(string $directive, string $source): self
	{
		if (!isset($this->directives[$directive])) {
			$this->directives[$directive] = [];
		}

		if (!in_array($source, $this->directives[$directive], true)) {
			$this->directives[$directive][] = $source;
		}

		return $this;
	}

	/**
	 * Remove a source from a directive.
	 */
	public function removeSource(string $directive, string $source): self
	{
		if (!isset($this->directives[$directive])) {
			return $this;
		}

		$this->directives[$directive] = array_values(
			array_filter(
				$this->directives[$directive],
				fn(string $s) => $s !== $source
			)
		);

		if (empty($this->directives[$directive])) {
			unset($this->directives[$directive]);
		}

		return $this;
	}

	/**
	 * Replace all sources for a directive.
	 *
	 * @param array<int, string> $sources
	 */
	public function setDirective(string $directive, array $sources): self
	{
		$this->directives[$directive] = $sources;
		return $this;
	}

	/**
	 * Remove an entire directive.
	 */
	public function removeDirective(string $directive): self
	{
		unset($this->directives[$directive]);
		return $this;
	}

	/**
	 * Check if a directive exists.
	 */
	public function hasDirective(string $directive): bool
	{
		return isset($this->directives[$directive]) && !empty($this->directives[$directive]);
	}

	/**
	 * Get the sources for a directive.
	 *
	 * @return array<int, string>
	 */
	public function getSources(string $directive): array
	{
		return $this->directives[$directive] ?? [];
	}

	/**
	 * Build the CSP header value string.
	 */
	public function build(): string
	{
		$parts = [];

		foreach ($this->directives as $directive => $sources) {
			if (!empty($sources)) {
				$parts[] = $directive . ' ' . implode(' ', $sources);
			}
		}

		return implode('; ', $parts);
	}

	/**
	 * Build and return as a full header string.
	 */
	public function toHeader(): string
	{
		return 'Content-Security-Policy: ' . $this->build();
	}
}
