<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Http;

use Kreblu\Core\Http\Request;
use Kreblu\Tests\TestCase;

/**
 * Tests for the HTTP Request class.
 */
final class RequestTest extends TestCase
{
	// ---------------------------------------------------------------
	// Method
	// ---------------------------------------------------------------

	public function test_method_from_create(): void
	{
		$request = Request::create(method: 'POST', uri: '/test');
		$this->assertEquals('POST', $request->method());
	}

	public function test_method_uppercased(): void
	{
		$request = Request::create(method: 'put');
		$this->assertEquals('PUT', $request->method());
	}

	public function test_is_get(): void
	{
		$request = Request::create(method: 'GET');
		$this->assertTrue($request->isGet());
		$this->assertFalse($request->isPost());
	}

	public function test_is_post(): void
	{
		$request = Request::create(method: 'POST');
		$this->assertTrue($request->isPost());
		$this->assertFalse($request->isGet());
	}

	public function test_is_put(): void
	{
		$request = Request::create(method: 'PUT');
		$this->assertTrue($request->isPut());
	}

	public function test_is_delete(): void
	{
		$request = Request::create(method: 'DELETE');
		$this->assertTrue($request->isDelete());
	}

	public function test_is_patch(): void
	{
		$request = Request::create(method: 'PATCH');
		$this->assertTrue($request->isPatch());
	}

	// ---------------------------------------------------------------
	// URL and Path
	// ---------------------------------------------------------------

	public function test_path(): void
	{
		$request = Request::create(uri: '/blog/my-post');
		$this->assertEquals('/blog/my-post', $request->path());
	}

	public function test_path_strips_query_string(): void
	{
		$request = Request::create(uri: '/blog/my-post?page=2&sort=date');
		$this->assertEquals('/blog/my-post', $request->path());
	}

	public function test_path_defaults_to_slash(): void
	{
		$request = new Request();
		$this->assertEquals('/', $request->path());
	}

	public function test_uri_includes_query_string(): void
	{
		$request = Request::create(uri: '/test?foo=bar');
		$this->assertEquals('/test?foo=bar', $request->uri());
	}

	public function test_host(): void
	{
		$request = Request::create(server: ['HTTP_HOST' => 'example.com']);
		$this->assertEquals('example.com', $request->host());
	}

	public function test_scheme_http_default(): void
	{
		$request = Request::create();
		$this->assertEquals('http', $request->scheme());
		$this->assertFalse($request->isSecure());
	}

	public function test_scheme_https(): void
	{
		$request = Request::create(server: ['HTTPS' => 'on']);
		$this->assertEquals('https', $request->scheme());
		$this->assertTrue($request->isSecure());
	}

	public function test_scheme_https_via_proxy(): void
	{
		$request = Request::create(server: ['HTTP_X_FORWARDED_PROTO' => 'https']);
		$this->assertEquals('https', $request->scheme());
	}

	public function test_full_url(): void
	{
		$request = Request::create(
			uri: '/blog?page=2',
			server: ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']
		);
		$this->assertEquals('https://example.com/blog?page=2', $request->fullUrl());
	}

	// ---------------------------------------------------------------
	// Input
	// ---------------------------------------------------------------

	public function test_query_parameter(): void
	{
		$request = Request::create(query: ['page' => '2', 'sort' => 'date']);
		$this->assertEquals('2', $request->query('page'));
		$this->assertEquals('date', $request->query('sort'));
		$this->assertNull($request->query('missing'));
		$this->assertEquals('default', $request->query('missing', 'default'));
	}

	public function test_all_query(): void
	{
		$request = Request::create(query: ['a' => '1', 'b' => '2']);
		$this->assertEquals(['a' => '1', 'b' => '2'], $request->allQuery());
	}

	public function test_body_input(): void
	{
		$request = Request::create(method: 'POST', body: ['name' => 'John', 'email' => 'john@test.com']);
		$this->assertEquals('John', $request->input('name'));
		$this->assertEquals('john@test.com', $request->input('email'));
	}

	public function test_input_body_overrides_query(): void
	{
		$request = Request::create(
			query: ['name' => 'from_query'],
			body: ['name' => 'from_body'],
		);
		$this->assertEquals('from_body', $request->input('name'));
	}

	public function test_input_falls_back_to_query(): void
	{
		$request = Request::create(query: ['page' => '3']);
		$this->assertEquals('3', $request->input('page'));
	}

	public function test_has(): void
	{
		$request = Request::create(query: ['exists' => 'yes']);
		$this->assertTrue($request->has('exists'));
		$this->assertFalse($request->has('missing'));
	}

	public function test_all_merges_query_and_body(): void
	{
		$request = Request::create(
			query: ['q' => 'search'],
			body: ['name' => 'test'],
		);
		$all = $request->all();
		$this->assertEquals('search', $all['q']);
		$this->assertEquals('test', $all['name']);
	}

	public function test_only(): void
	{
		$request = Request::create(body: ['name' => 'John', 'email' => 'j@t.com', 'password' => 'secret']);
		$only = $request->only(['name', 'email']);
		$this->assertEquals(['name' => 'John', 'email' => 'j@t.com'], $only);
		$this->assertArrayNotHasKey('password', $only);
	}

	// ---------------------------------------------------------------
	// JSON
	// ---------------------------------------------------------------

	public function test_json_body(): void
	{
		$request = Request::create(
			rawBody: '{"title":"Test","status":"draft"}',
			server: ['CONTENT_TYPE' => 'application/json'],
		);

		$this->assertTrue($request->isJson());
		$json = $request->json();
		$this->assertNotNull($json);
		$this->assertEquals('Test', $json['title']);
	}

	public function test_json_value(): void
	{
		$request = Request::create(rawBody: '{"count":42}');
		$this->assertEquals(42, $request->jsonValue('count'));
		$this->assertNull($request->jsonValue('missing'));
		$this->assertEquals('fallback', $request->jsonValue('missing', 'fallback'));
	}

	public function test_json_returns_null_for_invalid(): void
	{
		$request = Request::create(rawBody: 'not json');
		$this->assertNull($request->json());
	}

	public function test_json_returns_null_for_empty(): void
	{
		$request = Request::create(rawBody: null);
		$this->assertNull($request->json());
	}

	// ---------------------------------------------------------------
	// Headers
	// ---------------------------------------------------------------

	public function test_header(): void
	{
		$request = Request::create(server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
		$this->assertEquals('en-US', $request->header('Accept-Language'));
	}

	public function test_header_default(): void
	{
		$request = Request::create();
		$this->assertEquals('none', $request->header('X-Custom', 'none'));
	}

	public function test_content_type_header(): void
	{
		$request = Request::create(server: ['CONTENT_TYPE' => 'application/json']);
		$this->assertEquals('application/json', $request->header('Content-Type'));
	}

	public function test_bearer_token(): void
	{
		$request = Request::create(server: ['HTTP_AUTHORIZATION' => 'Bearer abc123token']);
		$this->assertEquals('abc123token', $request->bearerToken());
	}

	public function test_bearer_token_null_when_missing(): void
	{
		$request = Request::create();
		$this->assertNull($request->bearerToken());
	}

	public function test_bearer_token_null_when_not_bearer(): void
	{
		$request = Request::create(server: ['HTTP_AUTHORIZATION' => 'Basic abc123']);
		$this->assertNull($request->bearerToken());
	}

	// ---------------------------------------------------------------
	// Cookies
	// ---------------------------------------------------------------

	public function test_cookie(): void
	{
		$request = Request::create(cookies: ['session_id' => 'abc123']);
		$this->assertEquals('abc123', $request->cookie('session_id'));
		$this->assertNull($request->cookie('missing'));
	}

	// ---------------------------------------------------------------
	// Files
	// ---------------------------------------------------------------

	public function test_has_file(): void
	{
		$request = Request::create(files: [
			'avatar' => ['name' => 'photo.jpg', 'error' => UPLOAD_ERR_OK],
		]);
		$this->assertTrue($request->hasFile('avatar'));
		$this->assertFalse($request->hasFile('missing'));
	}

	public function test_has_file_false_when_no_file_uploaded(): void
	{
		$request = Request::create(files: [
			'avatar' => ['name' => '', 'error' => UPLOAD_ERR_NO_FILE],
		]);
		$this->assertFalse($request->hasFile('avatar'));
	}

	public function test_file_returns_array(): void
	{
		$fileData = ['name' => 'doc.pdf', 'tmp_name' => '/tmp/php123', 'error' => UPLOAD_ERR_OK, 'size' => 1024];
		$request = Request::create(files: ['document' => $fileData]);

		$this->assertEquals($fileData, $request->file('document'));
	}

	// ---------------------------------------------------------------
	// Server info
	// ---------------------------------------------------------------

	public function test_ip_from_remote_addr(): void
	{
		$request = Request::create(server: ['REMOTE_ADDR' => '192.168.1.100']);
		$this->assertEquals('192.168.1.100', $request->ip());
	}

	public function test_ip_from_x_forwarded_for(): void
	{
		$request = Request::create(server: [
			'HTTP_X_FORWARDED_FOR' => '203.0.113.50, 70.41.3.18',
			'REMOTE_ADDR' => '127.0.0.1',
		]);
		$this->assertEquals('203.0.113.50', $request->ip());
	}

	public function test_user_agent(): void
	{
		$request = Request::create(server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 Test']);
		$this->assertEquals('Mozilla/5.0 Test', $request->userAgent());
	}

	public function test_referer(): void
	{
		$request = Request::create(server: ['HTTP_REFERER' => 'https://google.com']);
		$this->assertEquals('https://google.com', $request->referer());
	}

	public function test_referer_null_when_missing(): void
	{
		$request = Request::create();
		$this->assertNull($request->referer());
	}

	public function test_is_ajax(): void
	{
		$request = Request::create(server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
		$this->assertTrue($request->isAjax());
	}

	public function test_expects_json_from_accept_header(): void
	{
		$request = Request::create(server: ['HTTP_ACCEPT' => 'application/json']);
		$this->assertTrue($request->expectsJson());
	}

	public function test_expects_json_from_ajax(): void
	{
		$request = Request::create(server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
		$this->assertTrue($request->expectsJson());
	}
}
