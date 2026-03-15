<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit;

use Kreblu\Tests\TestCase;

/**
 * Tests for formatting helper functions.
 */
final class FormattingTest extends TestCase
{
    // -- os_slugify --

    public function test_slugify_basic(): void
    {
        $this->assertEquals('hello-world', os_slugify('Hello World'));
    }

    public function test_slugify_special_characters(): void
    {
        $this->assertEquals('hello-world', os_slugify('Hello & World!'));
    }

    public function test_slugify_multiple_spaces(): void
    {
        $this->assertEquals('hello-world', os_slugify('Hello    World'));
    }

    public function test_slugify_leading_trailing_hyphens(): void
    {
        $this->assertEquals('hello', os_slugify('---hello---'));
    }

    public function test_slugify_unicode(): void
    {
        $this->assertEquals('cafe', os_slugify('Café'));
    }

    public function test_slugify_numbers(): void
    {
        $this->assertEquals('post-123', os_slugify('Post 123'));
    }

    // -- os_excerpt --

    public function test_excerpt_from_html(): void
    {
        $html = '<p>This is a <strong>test</strong> paragraph with some content.</p>';
        $excerpt = os_excerpt($html, 5);
        $this->assertEquals('This is a test paragraph&hellip;', $excerpt);
    }

    public function test_excerpt_short_content_no_ellipsis(): void
    {
        $html = '<p>Short text.</p>';
        $excerpt = os_excerpt($html, 55);
        $this->assertEquals('Short text.', $excerpt);
    }

    // -- os_truncate --

    public function test_truncate_long_string(): void
    {
        $result = os_truncate('This is a longer string that needs truncating', 20);
        $this->assertEquals('This is a longer...', $result);
    }

    public function test_truncate_short_string_unchanged(): void
    {
        $result = os_truncate('Short', 20);
        $this->assertEquals('Short', $result);
    }

    // -- os_esc --

    public function test_esc_html_entities(): void
    {
        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', os_esc('<script>alert("xss")</script>'));
    }

    public function test_esc_ampersand(): void
    {
        $this->assertEquals('AT&amp;T', os_esc('AT&T'));
    }

    // -- os_format_bytes --

    public function test_format_bytes_kb(): void
    {
        $this->assertEquals('1 KB', os_format_bytes(1024));
    }

    public function test_format_bytes_mb(): void
    {
        $this->assertEquals('1.5 MB', os_format_bytes(1572864));
    }

    public function test_format_bytes_zero(): void
    {
        $this->assertEquals('0 B', os_format_bytes(0));
    }
}
