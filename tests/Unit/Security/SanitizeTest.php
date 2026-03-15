<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Security;

use Kreblu\Tests\TestCase;

/**
 * Tests for sanitization helper functions.
 */
final class SanitizeTest extends TestCase
{
    // -- os_sanitize_text --

    public function test_sanitize_text_strips_html(): void
    {
        $this->assertEquals('Hello world', os_sanitize_text('<b>Hello</b> <script>evil()</script>world'));
    }

    public function test_sanitize_text_trims_whitespace(): void
    {
        $this->assertEquals('Hello', os_sanitize_text('  Hello  '));
    }

    public function test_sanitize_text_empty_string(): void
    {
        $this->assertEquals('', os_sanitize_text(''));
    }

    // -- os_sanitize_html --

    public function test_sanitize_html_allows_safe_tags(): void
    {
        $input = '<p>Hello <strong>world</strong></p>';
        $this->assertEquals($input, os_sanitize_html($input));
    }

    public function test_sanitize_html_strips_script(): void
    {
        $input = '<p>Hello</p><script>alert("xss")</script>';
        $this->assertEquals('<p>Hello</p>alert("xss")', os_sanitize_html($input));
    }

    public function test_sanitize_html_strips_iframe(): void
    {
        $input = '<p>Content</p><iframe src="evil.com"></iframe>';
        $result = os_sanitize_html($input);
        $this->assertStringNotContainsString('iframe', $result);
    }

    public function test_sanitize_html_allows_links(): void
    {
        $input = '<a href="https://example.com">Link</a>';
        $this->assertEquals($input, os_sanitize_html($input));
    }

    public function test_sanitize_html_allows_images(): void
    {
        $input = '<img src="photo.jpg" alt="Photo">';
        $this->assertStringContainsString('img', os_sanitize_html($input));
    }

    // -- os_sanitize_url --

    public function test_sanitize_url_valid_https(): void
    {
        $this->assertEquals('https://example.com/path', os_sanitize_url('https://example.com/path'));
    }

    public function test_sanitize_url_valid_http(): void
    {
        $this->assertEquals('http://example.com', os_sanitize_url('http://example.com'));
    }

    public function test_sanitize_url_strips_javascript(): void
    {
        $this->assertEquals('', os_sanitize_url('javascript:alert(1)'));
    }

    public function test_sanitize_url_strips_data_uri(): void
    {
        $this->assertEquals('', os_sanitize_url('data:text/html,<script>alert(1)</script>'));
    }

    public function test_sanitize_url_trims_whitespace(): void
    {
        $this->assertEquals('https://example.com', os_sanitize_url('  https://example.com  '));
    }

    public function test_sanitize_url_empty_for_invalid(): void
    {
        $this->assertEquals('', os_sanitize_url('not a url'));
    }

    // -- os_sanitize_email --

    public function test_sanitize_email_valid(): void
    {
        $this->assertEquals('user@example.com', os_sanitize_email('user@example.com'));
    }

    public function test_sanitize_email_trims(): void
    {
        $this->assertEquals('user@example.com', os_sanitize_email('  user@example.com  '));
    }

    public function test_sanitize_email_invalid_returns_empty(): void
    {
        $this->assertEquals('', os_sanitize_email('not-an-email'));
    }

    public function test_sanitize_email_no_domain_returns_empty(): void
    {
        $this->assertEquals('', os_sanitize_email('user@'));
    }
}
