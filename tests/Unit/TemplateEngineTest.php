<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit;

use Kreblu\Core\Template\TemplateEngine;
use Kreblu\Core\Template\TemplateHierarchy;
use Kreblu\Tests\TestCase;

/**
 * Tests for the Template Engine and Template Hierarchy.
 */
final class TemplateEngineTest extends TestCase
{
	private string $templatesDir;
	private string $cacheDir;
	private TemplateEngine $engine;

	protected function setUp(): void
	{
		parent::setUp();

		$this->templatesDir = sys_get_temp_dir() . '/kreblu_templates_' . uniqid();
		$this->cacheDir = sys_get_temp_dir() . '/kreblu_template_cache_' . uniqid();

		mkdir($this->templatesDir, 0755, true);
		mkdir($this->templatesDir . '/layouts', 0755, true);
		mkdir($this->templatesDir . '/partials', 0755, true);
		mkdir($this->cacheDir, 0755, true);

		$this->engine = new TemplateEngine($this->templatesDir, $this->cacheDir, cacheEnabled: false);
	}

	protected function tearDown(): void
	{
		$this->recursiveDelete($this->templatesDir);
		$this->recursiveDelete($this->cacheDir);
		parent::tearDown();
	}

	private function createTemplate(string $name, string $content): void
	{
		$path = $this->templatesDir . '/' . str_replace('.', '/', $name) . '.php';
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($path, $content);
	}

	private function recursiveDelete(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir) ?: [];
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
		}
		@rmdir($dir);
	}

	// ---------------------------------------------------------------
	// Escaped output
	// ---------------------------------------------------------------

	public function test_escaped_output(): void
	{
		$this->createTemplate('test', '<h1>{{ $title }}</h1>');
		$result = $this->engine->render('test', ['title' => 'Hello World']);

		$this->assertEquals('<h1>Hello World</h1>', $result);
	}

	public function test_escaped_output_prevents_xss(): void
	{
		$this->createTemplate('test', '<p>{{ $input }}</p>');
		$result = $this->engine->render('test', ['input' => '<script>alert("xss")</script>']);

		$this->assertStringNotContainsString('<script>', $result);
		$this->assertStringContainsString('&lt;script&gt;', $result);
	}

	public function test_escaped_output_handles_quotes(): void
	{
		$this->createTemplate('test', '<p>{{ $text }}</p>');
		$result = $this->engine->render('test', ['text' => 'He said "hello" & \'goodbye\'']);

		$this->assertStringContainsString('&quot;hello&quot;', $result);
		$this->assertStringContainsString('&#039;goodbye&#039;', $result);
		$this->assertStringContainsString('&amp;', $result);
	}

	// ---------------------------------------------------------------
	// Raw output
	// ---------------------------------------------------------------

	public function test_raw_output(): void
	{
		$this->createTemplate('test', '<div>{!! $html !!}</div>');
		$result = $this->engine->render('test', ['html' => '<strong>Bold</strong>']);

		$this->assertEquals('<div><strong>Bold</strong></div>', $result);
	}

	public function test_raw_output_does_not_escape(): void
	{
		$this->createTemplate('test', '{!! $content !!}');
		$result = $this->engine->render('test', ['content' => '<p>Paragraph</p>']);

		$this->assertEquals('<p>Paragraph</p>', $result);
	}

	// ---------------------------------------------------------------
	// Comments
	// ---------------------------------------------------------------

	public function test_comments_removed(): void
	{
		$this->createTemplate('test', 'Hello {{-- this is a comment --}} World');
		$result = $this->engine->render('test');

		$this->assertEquals('Hello  World', $result);
	}

	public function test_multiline_comment_removed(): void
	{
		$this->createTemplate('test', "Before\n{{-- \nmultiline\ncomment\n--}}\nAfter");
		$result = $this->engine->render('test');

		$this->assertStringContainsString('Before', $result);
		$this->assertStringContainsString('After', $result);
		$this->assertStringNotContainsString('multiline', $result);
	}

	// ---------------------------------------------------------------
	// Conditionals
	// ---------------------------------------------------------------

	public function test_if_true(): void
	{
		$this->createTemplate('test', '@if($show)Visible@endif');
		$result = $this->engine->render('test', ['show' => true]);

		$this->assertEquals('Visible', $result);
	}

	public function test_if_false(): void
	{
		$this->createTemplate('test', '@if($show)Visible@endif');
		$result = $this->engine->render('test', ['show' => false]);

		$this->assertEquals('', $result);
	}

	public function test_if_else(): void
	{
		$this->createTemplate('test', '@if($logged)Welcome@elseLogin@endif');

		$result = $this->engine->render('test', ['logged' => true]);
		$this->assertEquals('Welcome', $result);

		$result = $this->engine->render('test', ['logged' => false]);
		$this->assertEquals('Login', $result);
	}

	public function test_if_elseif_else(): void
	{
		$this->createTemplate('test', '@if($role === "admin")Admin@elseif($role === "editor")Editor@elseUser@endif');

		$this->assertEquals('Admin', $this->engine->render('test', ['role' => 'admin']));
		$this->assertEquals('Editor', $this->engine->render('test', ['role' => 'editor']));
		$this->assertEquals('User', $this->engine->render('test', ['role' => 'subscriber']));
	}

	public function test_isset(): void
	{
		$this->createTemplate('test', '@isset($name)Hello {{ $name }}@endisset');

		$this->assertEquals('Hello John', $this->engine->render('test', ['name' => 'John']));
		$this->assertEquals('', $this->engine->render('test', []));
	}

	// ---------------------------------------------------------------
	// Loops
	// ---------------------------------------------------------------

	public function test_foreach(): void
	{
		$this->createTemplate('test', '@foreach($items as $item){{ $item }} @endforeach');
		$result = $this->engine->render('test', ['items' => ['a', 'b', 'c']]);

		$this->assertEquals('a b c ', $result);
	}

	public function test_foreach_with_key(): void
	{
		$this->createTemplate('test', '@foreach($data as $key => $val){{ $key }}={{ $val }} @endforeach');
		$result = $this->engine->render('test', ['data' => ['x' => '1', 'y' => '2']]);

		$this->assertEquals('x=1 y=2 ', $result);
	}

	public function test_empty_foreach(): void
	{
		$this->createTemplate('test', 'Before @foreach($items as $item){{ $item }}@endforeach After');
		$result = $this->engine->render('test', ['items' => []]);

		$this->assertEquals('Before  After', $result);
	}

	public function test_for_loop(): void
	{
		$this->createTemplate('test', '@for($i = 0; $i < 3; $i++){{ $i }}@endfor');
		$result = $this->engine->render('test');

		$this->assertEquals('012', $result);
	}

	// ---------------------------------------------------------------
	// Layout inheritance
	// ---------------------------------------------------------------

	public function test_extends_and_yield(): void
	{
		$this->createTemplate('layouts.main', '<html><body>@yield(\'content\')</body></html>');
		$this->createTemplate('page', '@extends(\'layouts.main\')@section(\'content\')Page Content@endsection');

		$result = $this->engine->render('page');
		$this->assertEquals('<html><body>Page Content</body></html>', $result);
	}

	public function test_yield_with_default(): void
	{
		$this->createTemplate('layouts.main', '<header>@yield(\'header\', \'Default Header\')</header><main>@yield(\'content\')</main>');
		$this->createTemplate('page', '@extends(\'layouts.main\')@section(\'content\')Body@endsection');

		$result = $this->engine->render('page');

		$this->assertStringContainsString('Default Header', $result);
		$this->assertStringContainsString('Body', $result);
	}

	public function test_multiple_sections(): void
	{
		$this->createTemplate('layouts.main', '<title>@yield(\'title\')</title><main>@yield(\'content\')</main>');
		$this->createTemplate('page', '@extends(\'layouts.main\')@section(\'title\')My Page@endsection@section(\'content\')Content here@endsection');

		$result = $this->engine->render('page');

		$this->assertStringContainsString('<title>My Page</title>', $result);
		$this->assertStringContainsString('<main>Content here</main>', $result);
	}

	// ---------------------------------------------------------------
	// Includes
	// ---------------------------------------------------------------

	public function test_include(): void
	{
		$this->createTemplate('partials.header', '<header>{{ $title }}</header>');
		$this->createTemplate('page', '@include(\'partials.header\')Content');

		$result = $this->engine->render('page', ['title' => 'My Site']);

		$this->assertEquals('<header>My Site</header>Content', $result);
	}

	public function test_include_with_data(): void
	{
		$this->createTemplate('partials.card', '<div>{{ $card_title }}</div>');
		$this->createTemplate('page', '@include(\'partials.card\', [\'card_title\' => \'Hello\'])');

		$result = $this->engine->render('page');

		$this->assertEquals('<div>Hello</div>', $result);
	}

	// ---------------------------------------------------------------
	// Shared data
	// ---------------------------------------------------------------

	public function test_shared_data(): void
	{
		$this->createTemplate('test', '{{ $site_name }}');

		$this->engine->share('site_name', 'Kreblu');
		$result = $this->engine->render('test');

		$this->assertEquals('Kreblu', $result);
	}

	public function test_local_data_overrides_shared(): void
	{
		$this->createTemplate('test', '{{ $name }}');

		$this->engine->share('name', 'Shared');
		$result = $this->engine->render('test', ['name' => 'Local']);

		$this->assertEquals('Local', $result);
	}

	// ---------------------------------------------------------------
	// Template exists
	// ---------------------------------------------------------------

	public function test_exists_true(): void
	{
		$this->createTemplate('test', 'content');
		$this->assertTrue($this->engine->exists('test'));
	}

	public function test_exists_false(): void
	{
		$this->assertFalse($this->engine->exists('nonexistent'));
	}

	// ---------------------------------------------------------------
	// Template not found
	// ---------------------------------------------------------------

	public function test_missing_template_throws(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Template not found');

		$this->engine->render('missing');
	}

	// ---------------------------------------------------------------
	// Cache
	// ---------------------------------------------------------------

	public function test_clear_cache(): void
	{
		$cachedEngine = new TemplateEngine($this->templatesDir, $this->cacheDir, cacheEnabled: true);

		$this->createTemplate('cached', 'Hello');
		$cachedEngine->render('cached');

		// Cache file should exist
		$files = glob($this->cacheDir . '/*.php');
		$this->assertNotEmpty($files);

		$cleared = $cachedEngine->clearCache();
		$this->assertGreaterThan(0, $cleared);

		$files = glob($this->cacheDir . '/*.php');
		$this->assertEmpty($files);
	}

	// ---------------------------------------------------------------
	// Compile string directly
	// ---------------------------------------------------------------

	public function test_compile_string(): void
	{
		$result = $this->engine->compileString('{{ $name }}');
		$this->assertStringContainsString('htmlspecialchars', $result);
	}

	public function test_compile_string_raw(): void
	{
		$result = $this->engine->compileString('{!! $html !!}');
		$this->assertStringNotContainsString('htmlspecialchars', $result);
		$this->assertStringContainsString('<?= $html ?>', $result);
	}

	// ---------------------------------------------------------------
	// Template Hierarchy
	// ---------------------------------------------------------------

	public function test_hierarchy_single_post(): void
	{
		$hierarchy = new TemplateHierarchy($this->templatesDir);

		// Only index.php exists
		$this->createTemplate('index', '');
		$this->assertEquals('index', $hierarchy->resolveSingle('post', 'hello'));

		// single.php exists — more specific
		$this->createTemplate('single', '');
		$this->assertEquals('single', $hierarchy->resolveSingle('post', 'hello'));

		// single-post.php exists — even more specific
		$this->createTemplate('single-post', '');
		$this->assertEquals('single-post', $hierarchy->resolveSingle('post', 'hello'));

		// single-post-hello.php exists — most specific
		$this->createTemplate('single-post-hello', '');
		$this->assertEquals('single-post-hello', $hierarchy->resolveSingle('post', 'hello'));
	}

	public function test_hierarchy_page(): void
	{
		$hierarchy = new TemplateHierarchy($this->templatesDir);

		$this->createTemplate('index', '');
		$this->assertEquals('index', $hierarchy->resolvePage('about'));

		$this->createTemplate('page', '');
		$this->assertEquals('page', $hierarchy->resolvePage('about'));

		$this->createTemplate('page-about', '');
		$this->assertEquals('page-about', $hierarchy->resolvePage('about'));
	}

	public function test_hierarchy_home(): void
	{
		$hierarchy = new TemplateHierarchy($this->templatesDir);

		$this->createTemplate('index', '');
		$this->assertEquals('index', $hierarchy->resolveHome());

		$this->createTemplate('home', '');
		$this->assertEquals('home', $hierarchy->resolveHome());

		$this->createTemplate('front-page', '');
		$this->assertEquals('front-page', $hierarchy->resolveHome());
	}

	public function test_hierarchy_404(): void
	{
		$hierarchy = new TemplateHierarchy($this->templatesDir);

		$this->createTemplate('index', '');
		$this->assertEquals('index', $hierarchy->resolve404());

		$this->createTemplate('404', '');
		$this->assertEquals('404', $hierarchy->resolve404());
	}

	public function test_hierarchy_category(): void
	{
		$hierarchy = new TemplateHierarchy($this->templatesDir);

		$this->createTemplate('index', '');
		$this->assertEquals('index', $hierarchy->resolveCategory('news'));

		$this->createTemplate('archive', '');
		$this->assertEquals('archive', $hierarchy->resolveCategory('news'));

		$this->createTemplate('category', '');
		$this->assertEquals('category', $hierarchy->resolveCategory('news'));

		$this->createTemplate('category-news', '');
		$this->assertEquals('category-news', $hierarchy->resolveCategory('news'));
	}

	public function test_hierarchy_returns_null_when_no_templates(): void
	{
		// Empty templates directory
		$emptyDir = sys_get_temp_dir() . '/kreblu_empty_' . uniqid();
		mkdir($emptyDir, 0755, true);

		$hierarchy = new TemplateHierarchy($emptyDir);
		$this->assertNull($hierarchy->resolveSingle('post', 'test'));
		$this->assertNull($hierarchy->resolveHome());

		@rmdir($emptyDir);
	}

	// ---------------------------------------------------------------
	// Mixed content
	// ---------------------------------------------------------------

	public function test_full_page_render(): void
	{
		$this->createTemplate('layouts.main', '<!DOCTYPE html>
<html>
<head><title>@yield(\'title\', \'Kreblu\')</title></head>
<body>
@yield(\'content\')
@include(\'partials.footer\')
</body>
</html>');

		$this->createTemplate('partials.footer', '<footer>{{ $site_name }}</footer>');

		$this->createTemplate('home', '@extends(\'layouts.main\')
@section(\'title\')Home@endsection
@section(\'content\')
@if($posts)
@foreach($posts as $post)
<h2>{{ $post }}</h2>
@endforeach
@else
<p>No posts yet.</p>
@endif
@endsection');

		$this->engine->share('site_name', 'My Kreblu Site');

		$result = $this->engine->render('home', [
			'posts' => ['First Post', 'Second Post'],
		]);

		$this->assertStringContainsString('<title>Home</title>', $result);
		$this->assertStringContainsString('<h2>First Post</h2>', $result);
		$this->assertStringContainsString('<h2>Second Post</h2>', $result);
		$this->assertStringContainsString('<footer>My Kreblu Site</footer>', $result);
	}
}
