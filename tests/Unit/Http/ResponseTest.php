<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Http;

use Kreblu\Core\Http\Response;
use Kreblu\Tests\TestCase;

/**
 * Tests for the HTTP Response class.
 *
 * Note: We can't test actual header sending in PHPUnit (headers already sent),
 * so we test the Response object's state before send() is called.
 */
final class ResponseTest extends TestCase
{
	// ---------------------------------------------------------------
	// Factory methods
	// ---------------------------------------------------------------

	public function test_html_response(): void
	{
		$response = Response::html('<h1>Hello</h1>', 200);

		$this->assertEquals('<h1>Hello</h1>', $response->getBody());
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertTrue($response->isHtml());
		$this->assertFalse($response->isJson());
	}

	public function test_json_response(): void
	{
		$response = Response::json(['name' => 'Kreblu', 'version' => 1]);

		$this->assertEquals('{"name":"Kreblu","version":1}', $response->getBody());
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertTrue($response->isJson());
		$this->assertFalse($response->isHtml());
	}

	public function test_json_with_status(): void
	{
		$response = Response::json(['error' => 'Not found'], 404);
		$this->assertEquals(404, $response->getStatusCode());
	}

	public function test_json_unicode_not_escaped(): void
	{
		$response = Response::json(['city' => 'Zürich']);
		$this->assertStringContainsString('Zürich', $response->getBody());
	}

	public function test_redirect_response(): void
	{
		$response = Response::redirect('/dashboard', 302);

		$this->assertEquals(302, $response->getStatusCode());
		$this->assertEquals('/dashboard', $response->getHeader('Location'));
		$this->assertTrue($response->isRedirect());
	}

	public function test_redirect_301(): void
	{
		$response = Response::redirect('/new-url', 301);

		$this->assertEquals(301, $response->getStatusCode());
		$this->assertTrue($response->isRedirect());
	}

	public function test_not_found_response(): void
	{
		$response = Response::notFound();

		$this->assertEquals(404, $response->getStatusCode());
		$this->assertStringContainsString('404', $response->getBody());
	}

	public function test_not_found_custom_body(): void
	{
		$response = Response::notFound('<h1>Custom 404</h1>');

		$this->assertEquals(404, $response->getStatusCode());
		$this->assertEquals('<h1>Custom 404</h1>', $response->getBody());
	}

	public function test_forbidden_response(): void
	{
		$response = Response::forbidden();

		$this->assertEquals(403, $response->getStatusCode());
		$this->assertStringContainsString('403', $response->getBody());
	}

	public function test_server_error_response(): void
	{
		$response = Response::serverError();

		$this->assertEquals(500, $response->getStatusCode());
		$this->assertStringContainsString('500', $response->getBody());
	}

	public function test_empty_response(): void
	{
		$response = Response::empty(204);

		$this->assertEquals(204, $response->getStatusCode());
		$this->assertEquals('', $response->getBody());
	}

	// ---------------------------------------------------------------
	// Setters
	// ---------------------------------------------------------------

	public function test_set_body(): void
	{
		$response = Response::html('original');
		$response->setBody('modified');

		$this->assertEquals('modified', $response->getBody());
	}

	public function test_set_status(): void
	{
		$response = Response::html('test');
		$response->setStatus(201);

		$this->assertEquals(201, $response->getStatusCode());
	}

	public function test_set_header(): void
	{
		$response = Response::html('test');
		$response->setHeader('X-Custom', 'value');

		$this->assertEquals('value', $response->getHeader('X-Custom'));
	}

	public function test_set_content_type(): void
	{
		$response = Response::html('test');
		$response->setContentType('text/plain');

		$this->assertEquals('text/plain', $response->getContentType());
	}

	public function test_setters_are_chainable(): void
	{
		$response = Response::html('')
			->setBody('chained')
			->setStatus(201)
			->setHeader('X-Test', 'yes')
			->setContentType('text/plain');

		$this->assertEquals('chained', $response->getBody());
		$this->assertEquals(201, $response->getStatusCode());
		$this->assertEquals('yes', $response->getHeader('X-Test'));
		$this->assertEquals('text/plain', $response->getContentType());
	}

	// ---------------------------------------------------------------
	// Headers
	// ---------------------------------------------------------------

	public function test_get_all_headers(): void
	{
		$response = Response::html('test');
		$response->setHeader('X-One', '1');
		$response->setHeader('X-Two', '2');

		$headers = $response->getHeaders();
		$this->assertEquals('1', $headers['X-One']);
		$this->assertEquals('2', $headers['X-Two']);
	}

	public function test_get_header_returns_null_when_missing(): void
	{
		$response = Response::html('test');
		$this->assertNull($response->getHeader('X-Missing'));
	}

	// ---------------------------------------------------------------
	// Type checking
	// ---------------------------------------------------------------

	public function test_is_html(): void
	{
		$this->assertTrue(Response::html('test')->isHtml());
		$this->assertFalse(Response::json([])->isHtml());
	}

	public function test_is_json(): void
	{
		$this->assertTrue(Response::json([])->isJson());
		$this->assertFalse(Response::html('test')->isJson());
	}

	public function test_is_redirect(): void
	{
		$this->assertTrue(Response::redirect('/test')->isRedirect());
		$this->assertTrue(Response::redirect('/test', 301)->isRedirect());
		$this->assertFalse(Response::html('test')->isRedirect());
		$this->assertFalse(Response::json([])->isRedirect());
	}

	// ---------------------------------------------------------------
	// Cookies
	// ---------------------------------------------------------------

	public function test_set_cookie_is_chainable(): void
	{
		$response = Response::html('test');
		$result = $response->setCookie('session', 'abc123', expires: time() + 3600);

		$this->assertSame($response, $result);
	}

	public function test_delete_cookie_is_chainable(): void
	{
		$response = Response::html('test');
		$result = $response->deleteCookie('session');

		$this->assertSame($response, $result);
	}

	// ---------------------------------------------------------------
	// Send state
	// ---------------------------------------------------------------

	public function test_not_sent_initially(): void
	{
		$response = Response::html('test');
		$this->assertFalse($response->isSent());
	}
}
