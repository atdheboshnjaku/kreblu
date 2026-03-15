<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit;

use Kreblu\Tests\TestCase;

/**
 * Tests for formatting helper functions.
 */
final class FormattingTest extends TestCase
{
    // -- kb_slugify --

    public function test_slugify_basic(): void
    {
        $this->assertEquals('hello-world', kb_slugify('Hello World'));
    }

    public function test_slugify_special_characters(): void
    {
        $this->assertEquals('hello-world', kb_slugify('Hello & World!'));
    }

    public function test_slugify_multiple_spaces(): void
    {
        $this->assertEquals('hello-world', kb_slugify('Hello    World'));
    }

    public function test_slugify_leading_trailing_hyphens(): void
    {
        $this->assertEquals('hello', kb_slugify('---hello---'));
    }

    public function test_slugify_unicode(): void
    {
        $this->assertEquals('cafe', kb_slugify('Café'));
    }

    public function test_slugify_numbers(): void
    {
        $this->assertEquals('post-123', kb_slugify('Post 123'));
    }

    // -- kb_excerpt --

    public function test_excerpt_from_html(): void
    {
        $html = '<p>This is a <strong>test</strong> paragraph with some content.</p>';
        $excerpt = kb_excerpt($html, 5);
        $this->assertEquals('This is a test paragraph&hellip;', $excerpt);
    }

    public function test_excerpt_short_content_no_ellipsis(): void
    {
        $html = '<p>Short text.</p>';
        $excerpt = kb_excerpt($html, 55);
        $this->assertEquals('Short text.', $excerpt);
    }

    // -- kb_truncate --

    public function test_truncate_long_string(): void
    {
        $result = kb_truncate('This is a longer string that needs truncating', 20);
        $this->assertEquals('This is a longer...', $result);
    }

    public function test_truncate_short_string_unchanged(): void
    {
        $result = kb_truncate('Short', 20);
        $this->assertEquals('Short', $result);
    }

    // -- kb_esc --

    public function test_esc_html_entities(): void
    {
        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', kb_esc('<script>alert("xss")</script>'));
    }

    public function test_esc_ampersand(): void
    {
        $this->assertEquals('AT&amp;T', kb_esc('AT&T'));
    }

    // -- kb_format_bytes --

    public function test_format_bytes_kb(): void
    {
        $this->assertEquals('1 KB', kb_format_bytes(1024));
    }

    public function test_format_bytes_mb(): void
    {
        $this->assertEquals('1.5 MB', kb_format_bytes(1572864));
    }

    public function test_format_bytes_zero(): void
    {
        $this->assertEquals('0 B', kb_format_bytes(0));
    }
}
