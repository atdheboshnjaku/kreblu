<?php declare(strict_types=1);

namespace Kreblu\Core\Template;

/**
 * Template Engine
 *
 * Blade-inspired template compiler for Kreblu. Compiles template files
 * with clean syntax into cached PHP files for fast execution.
 *
 * Syntax:
 *   {{ $var }}              — escaped output (htmlspecialchars)
 *   {!! $var !!}            — raw/unescaped output (for pre-sanitized HTML)
 *   @if($condition) ... @elseif($other) ... @else ... @endif
 *   @foreach($items as $item) ... @endforeach
 *   @for($i = 0; $i < 10; $i++) ... @endfor
 *   @while($condition) ... @endwhile
 *   @extends('layouts.main')
 *   @section('content') ... @endsection
 *   @yield('content')
 *   @yield('sidebar', 'Default content')
 *   @include('partials.header')
 *   @include('partials.card', ['title' => $post->title])
 *   {{-- This is a comment --}}
 *
 * Templates use dot notation for subdirectories: 'layouts.main' = layouts/main.php
 */
final class TemplateEngine
{
	/** @var array<string, string> Cached section content during rendering */
	private array $sections = [];

	/** @var array<int, string> Stack of section names being captured */
	private array $sectionStack = [];

	/** @var string|null The parent layout template to extend */
	private ?string $parentTemplate = null;

	/** @var array<string, mixed> Data available to all templates */
	private array $sharedData = [];

	/** @var array<string, mixed> Current render data (for passing to includes) */
	private array $currentData = [];

	public function __construct(
		private readonly string $templatesPath,
		private readonly string $cachePath,
		private readonly bool $cacheEnabled = true,
	) {
		if (!is_dir($this->cachePath)) {
			@mkdir($this->cachePath, 0755, true);
		}
	}

	// ---------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------

	/**
	 * Render a template with data.
	 *
	 * @param string $template Template name in dot notation (e.g., 'layouts.main', 'single')
	 * @param array<string, mixed> $data Variables available in the template
	 * @return string The rendered HTML
	 */
	public function render(string $template, array $data = []): string
	{
		// Reset state for this render cycle
		$this->sections = [];
		$this->sectionStack = [];
		$this->parentTemplate = null;

		return $this->renderTemplate($template, array_merge($this->sharedData, $data));
	}

	/**
	 * Share data with all templates.
	 *
	 * @param string $key Variable name
	 * @param mixed $value Variable value
	 */
	public function share(string $key, mixed $value): void
	{
		$this->sharedData[$key] = $value;
	}

	/**
	 * Share multiple values at once.
	 *
	 * @param array<string, mixed> $data
	 */
	public function shareMany(array $data): void
	{
		$this->sharedData = array_merge($this->sharedData, $data);
	}

	/**
	 * Check if a template file exists.
	 */
	public function exists(string $template): bool
	{
		$path = $this->resolveTemplatePath($template);
		return file_exists($path);
	}

	// ---------------------------------------------------------------
	// Compilation
	// ---------------------------------------------------------------

	/**
	 * Compile a template file to PHP and cache it.
	 *
	 * @return string Path to the compiled PHP file
	 */
	public function compile(string $template): string
	{
		$sourcePath = $this->resolveTemplatePath($template);

		if (!file_exists($sourcePath)) {
			throw new \RuntimeException("Template not found: {$template} (looked in {$sourcePath})");
		}

		$cachePath = $this->getCachePath($template);

		// Check if cached version is still valid
		if ($this->cacheEnabled && file_exists($cachePath)) {
			$cacheTime = filemtime($cachePath);
			$sourceTime = filemtime($sourcePath);

			if ($cacheTime !== false && $sourceTime !== false && $cacheTime >= $sourceTime) {
				return $cachePath;
			}
		}

		// Read and compile
		$source = file_get_contents($sourcePath);
		if ($source === false) {
			throw new \RuntimeException("Failed to read template: {$sourcePath}");
		}

		$compiled = $this->compileString($source);

		// Write compiled file
		$dir = dirname($cachePath);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		file_put_contents($cachePath, $compiled, LOCK_EX);

		return $cachePath;
	}

	/**
	 * Compile a template string to PHP code.
	 *
	 * This is the core compiler. It processes directives in a specific order
	 * to avoid conflicts (e.g., {{ }} inside @if blocks).
	 */
	public function compileString(string $source): string
	{
		$compiled = $source;

		// Order matters: comments first, then directives, then output tags
		$compiled = $this->compileComments($compiled);
		$compiled = $this->compileExtends($compiled);
		$compiled = $this->compileSections($compiled);
		$compiled = $this->compileYields($compiled);
		$compiled = $this->compileIncludes($compiled);
		$compiled = $this->compileConditionals($compiled);
		$compiled = $this->compileLoops($compiled);
		$compiled = $this->compileRawOutput($compiled);
		$compiled = $this->compileEscapedOutput($compiled);

		return $compiled;
	}

	/**
	 * Clear all compiled template cache files.
	 */
	public function clearCache(): int
	{
		$files = glob($this->cachePath . '/*.php');
		if ($files === false) {
			return 0;
		}

		$count = 0;
		foreach ($files as $file) {
			if (@unlink($file)) {
				$count++;
			}
		}

		return $count;
	}

	// ---------------------------------------------------------------
	// Internal rendering
	// ---------------------------------------------------------------

	/**
	 * Render a template (compiles if needed, then executes).
	 *
	 * @param array<string, mixed> $data
	 */
	private function renderTemplate(string $template, array $data): string
	{
		$compiledPath = $this->compile($template);

		$this->currentData = $data;

		// Execute the compiled template
		$output = $this->executeTemplate($compiledPath, $data);

		// If the template extended a parent layout, render the parent
		if ($this->parentTemplate !== null) {
			$parent = $this->parentTemplate;
			$this->parentTemplate = null;

			$output = $this->renderTemplate($parent, $data);
		}

		return $output;
	}

	/**
	 * Execute a compiled template file and capture output.
	 *
	 * @param array<string, mixed> $__data
	 */
	private function executeTemplate(string $__path, array $__data): string
	{
		// Make the engine available inside templates for section/yield/include
		$__engine = $this;

		// Extract data as variables
		extract($__data, EXTR_SKIP);

		ob_start();

		try {
			include $__path;
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}

		return ob_get_clean() ?: '';
	}

	// ---------------------------------------------------------------
	// Section methods (called from compiled templates)
	// ---------------------------------------------------------------

	/**
	 * Start capturing a section.
	 * Called from compiled @section directives.
	 */
	public function startSection(string $name): void
	{
		$this->sectionStack[] = $name;
		ob_start();
	}

	/**
	 * End the current section and store its content.
	 * Called from compiled @endsection directives.
	 */
	public function endSection(): void
	{
		if (empty($this->sectionStack)) {
			throw new \RuntimeException('@endsection without matching @section');
		}

		$name = array_pop($this->sectionStack);
		$this->sections[$name] = ob_get_clean() ?: '';
	}

	/**
	 * Output a section's content, or a default if the section wasn't defined.
	 * Called from compiled @yield directives.
	 */
	public function yieldSection(string $name, string $default = ''): string
	{
		return $this->sections[$name] ?? $default;
	}

	/**
	 * Set the parent layout to extend.
	 * Called from compiled @extends directives.
	 */
	public function extendLayout(string $template): void
	{
		$this->parentTemplate = $template;
	}

	/**
	 * Render an included template.
	 * Called from compiled @include directives.
	 *
	 * @param array<string, mixed> $data
	 */
	public function includeTemplate(string $template, array $data = []): string
	{
		$compiledPath = $this->compile($template);
		return $this->executeTemplate($compiledPath, array_merge($this->sharedData, $this->currentData, $data));
	}

	// ---------------------------------------------------------------
	// Compiler methods
	// ---------------------------------------------------------------

	/**
	 * Remove comments: {{-- ... --}}
	 */
	private function compileComments(string $source): string
	{
		return preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
	}

	/**
	 * Compile @extends('template.name')
	 */
	private function compileExtends(string $source): string
	{
		$pattern = '/@extends\s*\(\s*[\'"](.+?)[\'"]\s*\)/';

		return preg_replace_callback($pattern, function (array $matches): string {
			return "<?php \$__engine->extendLayout('{$matches[1]}'); ?>";
		}, $source) ?? $source;
	}

	/**
	 * Compile @section('name') ... @endsection
	 */
	private function compileSections(string $source): string
	{
		// @section('name')
		$source = preg_replace_callback(
			'/@section\s*\(\s*[\'"](.+?)[\'"]\s*\)/',
			fn(array $m) => "<?php \$__engine->startSection('{$m[1]}'); ?>",
			$source,
		) ?? $source;

		// @endsection
		$source = str_replace('@endsection', '<?php $__engine->endSection(); ?>', $source);

		return $source;
	}

	/**
	 * Compile @yield('name') and @yield('name', 'default')
	 */
	private function compileYields(string $source): string
	{
		// @yield('name', 'default')
		$source = preg_replace_callback(
			'/@yield\s*\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.+?)[\'"]\s*\)/',
			fn(array $m) => "<?= \$__engine->yieldSection('{$m[1]}', '{$m[2]}') ?>",
			$source,
		) ?? $source;

		// @yield('name')
		$source = preg_replace_callback(
			'/@yield\s*\(\s*[\'"](.+?)[\'"]\s*\)/',
			fn(array $m) => "<?= \$__engine->yieldSection('{$m[1]}') ?>",
			$source,
		) ?? $source;

		return $source;
	}

	/**
	 * Compile @include('template') and @include('template', ['key' => 'val'])
	 */
	private function compileIncludes(string $source): string
	{
		// @include('template', [...data...])
		$source = preg_replace_callback(
			'/@include\s*\(\s*[\'"](.+?)[\'"]\s*,\s*(\[.+?\])\s*\)/',
			fn(array $m) => "<?= \$__engine->includeTemplate('{$m[1]}', {$m[2]}) ?>",
			$source,
		) ?? $source;

		// @include('template')
		$source = preg_replace_callback(
			'/@include\s*\(\s*[\'"](.+?)[\'"]\s*\)/',
			fn(array $m) => "<?= \$__engine->includeTemplate('{$m[1]}') ?>",
			$source,
		) ?? $source;

		return $source;
	}

	/**
	 * Compile @if, @elseif, @else, @endif
	 */
	private function compileConditionals(string $source): string
	{
		$source = preg_replace('/@if\s*\((.+?)\)/', '<?php if($1): ?>', $source) ?? $source;
		$source = preg_replace('/@elseif\s*\((.+?)\)/', '<?php elseif($1): ?>', $source) ?? $source;
		$source = str_replace('@else', '<?php else: ?>', $source);
		$source = str_replace('@endif', '<?php endif; ?>', $source);

		// @isset / @endisset
		$source = preg_replace('/@isset\s*\((.+?)\)/', '<?php if(isset($1)): ?>', $source) ?? $source;
		$source = str_replace('@endisset', '<?php endif; ?>', $source);

		// @empty / @endempty
		$source = preg_replace('/@empty\s*\((.+?)\)/', '<?php if(empty($1)): ?>', $source) ?? $source;
		$source = str_replace('@endempty', '<?php endif; ?>', $source);

		return $source;
	}

	/**
	 * Compile @foreach, @endforeach, @for, @endfor, @while, @endwhile
	 */
	private function compileLoops(string $source): string
	{
		$source = preg_replace('/@foreach\s*\((.+?)\)/', '<?php foreach($1): ?>', $source) ?? $source;
		$source = str_replace('@endforeach', '<?php endforeach; ?>', $source);

		$source = preg_replace('/@for\s*\((.+?)\)/', '<?php for($1): ?>', $source) ?? $source;
		$source = str_replace('@endfor', '<?php endfor; ?>', $source);

		$source = preg_replace('/@while\s*\((.+?)\)/', '<?php while($1): ?>', $source) ?? $source;
		$source = str_replace('@endwhile', '<?php endwhile; ?>', $source);

		return $source;
	}

	/**
	 * Compile raw/unescaped output: {!! $expression !!}
	 * Must be compiled BEFORE escaped output to avoid conflicts.
	 */
	private function compileRawOutput(string $source): string
	{
		return preg_replace('/\{!!\s*(.+?)\s*!!\}/', '<?= $1 ?>', $source) ?? $source;
	}

	/**
	 * Compile escaped output: {{ $expression }}
	 */
	private function compileEscapedOutput(string $source): string
	{
		return preg_replace(
			'/\{\{\s*(.+?)\s*\}\}/',
			'<?= htmlspecialchars((string)($1), ENT_QUOTES, \'UTF-8\') ?>',
			$source,
		) ?? $source;
	}

	// ---------------------------------------------------------------
	// Path resolution
	// ---------------------------------------------------------------

	/**
	 * Resolve a dot-notation template name to a file path.
	 * 'layouts.main' => /path/to/templates/layouts/main.php
	 */
	private function resolveTemplatePath(string $template): string
	{
		$relativePath = str_replace('.', '/', $template) . '.php';
		return $this->templatesPath . '/' . $relativePath;
	}

	/**
	 * Get the cache file path for a template.
	 */
	private function getCachePath(string $template): string
	{
		$hash = md5($template);
		return $this->cachePath . '/' . $hash . '.php';
	}
}
