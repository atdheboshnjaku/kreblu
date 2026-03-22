<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Http;

use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;
use Kreblu\Core\Http\Router;
use Kreblu\Tests\TestCase;

/**
 * Tests for the HTTP Router.
 */
final class RouterTest extends TestCase
{
	private Router $router;

	protected function setUp(): void
	{
		parent::setUp();
		$this->router = new Router();
	}

	// ---------------------------------------------------------------
	// Basic routing
	// ---------------------------------------------------------------

	public function test_get_route_matches(): void
	{
		$this->router->get('/hello', function () {
			return Response::html('Hello World');
		});

		$request = Request::create(method: 'GET', uri: '/hello');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('Hello World', $response->getBody());
	}

	public function test_post_route_matches(): void
	{
		$this->router->post('/submit', function () {
			return Response::json(['status' => 'ok']);
		});

		$request = Request::create(method: 'POST', uri: '/submit');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertTrue($response->isJson());
	}

	public function test_put_route_matches(): void
	{
		$this->router->put('/update', function () {
			return Response::json(['updated' => true]);
		});

		$request = Request::create(method: 'PUT', uri: '/update');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function test_delete_route_matches(): void
	{
		$this->router->delete('/remove', function () {
			return Response::empty(204);
		});

		$request = Request::create(method: 'DELETE', uri: '/remove');
		$response = $this->router->dispatch($request);

		$this->assertEquals(204, $response->getStatusCode());
	}

	public function test_patch_route_matches(): void
	{
		$this->router->patch('/patch', function () {
			return Response::json(['patched' => true]);
		});

		$request = Request::create(method: 'PATCH', uri: '/patch');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function test_any_route_matches_all_methods(): void
	{
		$this->router->any('/anything', function () {
			return Response::html('works');
		});

		foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
			$request = Request::create(method: $method, uri: '/anything');
			$response = $this->router->dispatch($request);
			$this->assertEquals(200, $response->getStatusCode(), "ANY should match {$method}");
		}
	}

	public function test_match_multiple_methods(): void
	{
		$this->router->match(['GET', 'POST'], '/form', function () {
			return Response::html('form');
		});

		$get = Request::create(method: 'GET', uri: '/form');
		$post = Request::create(method: 'POST', uri: '/form');
		$put = Request::create(method: 'PUT', uri: '/form');

		$this->assertEquals(200, $this->router->dispatch($get)->getStatusCode());
		$this->assertEquals(200, $this->router->dispatch($post)->getStatusCode());
		$this->assertEquals(405, $this->router->dispatch($put)->getStatusCode());
	}

	public function test_root_route(): void
	{
		$this->router->get('/', function () {
			return Response::html('Home');
		});

		$request = Request::create(method: 'GET', uri: '/');
		$response = $this->router->dispatch($request);

		$this->assertEquals('Home', $response->getBody());
	}

	// ---------------------------------------------------------------
	// Named parameters
	// ---------------------------------------------------------------

	public function test_single_parameter(): void
	{
		$this->router->get('/posts/{id}', function (Request $req, array $params) {
			return Response::json(['id' => $params['id']]);
		});

		$request = Request::create(method: 'GET', uri: '/posts/42');
		$response = $this->router->dispatch($request);

		$this->assertEquals('{"id":"42"}', $response->getBody());
	}

	public function test_multiple_parameters(): void
	{
		$this->router->get('/posts/{post_id}/comments/{comment_id}', function (Request $req, array $params) {
			return Response::json($params);
		});

		$request = Request::create(method: 'GET', uri: '/posts/5/comments/99');
		$response = $this->router->dispatch($request);

		$body = json_decode($response->getBody(), true);
		$this->assertEquals('5', $body['post_id']);
		$this->assertEquals('99', $body['comment_id']);
	}

	public function test_parameter_with_slug(): void
	{
		$this->router->get('/blog/{slug}', function (Request $req, array $params) {
			return Response::html('Post: ' . $params['slug']);
		});

		$request = Request::create(method: 'GET', uri: '/blog/my-awesome-post');
		$response = $this->router->dispatch($request);

		$this->assertEquals('Post: my-awesome-post', $response->getBody());
	}

	public function test_get_params_after_dispatch(): void
	{
		$this->router->get('/users/{id}', function () {
			return Response::html('ok');
		});

		$request = Request::create(method: 'GET', uri: '/users/7');
		$this->router->dispatch($request);

		$this->assertEquals(['id' => '7'], $this->router->getParams());
		$this->assertEquals('7', $this->router->getParam('id'));
		$this->assertNull($this->router->getParam('missing'));
		$this->assertEquals('fallback', $this->router->getParam('missing', 'fallback'));
	}

	public function test_parameter_does_not_match_slash(): void
	{
		$this->router->get('/files/{name}', function () {
			return Response::html('ok');
		});

		// /files/path/to/file should NOT match because {name} doesn't match slashes
		$request = Request::create(method: 'GET', uri: '/files/path/to/file');
		$response = $this->router->dispatch($request);

		$this->assertEquals(404, $response->getStatusCode());
	}

	// ---------------------------------------------------------------
	// Route groups
	// ---------------------------------------------------------------

	public function test_group_prefix(): void
	{
		$this->router->group('/api/v1', function (Router $router) {
			$router->get('/posts', function () {
				return Response::json(['posts' => []]);
			});

			$router->get('/users', function () {
				return Response::json(['users' => []]);
			});
		});

		$request = Request::create(method: 'GET', uri: '/api/v1/posts');
		$response = $this->router->dispatch($request);
		$this->assertEquals(200, $response->getStatusCode());

		$request = Request::create(method: 'GET', uri: '/api/v1/users');
		$response = $this->router->dispatch($request);
		$this->assertEquals(200, $response->getStatusCode());

		// Without prefix should 404
		$request = Request::create(method: 'GET', uri: '/posts');
		$response = $this->router->dispatch($request);
		$this->assertEquals(404, $response->getStatusCode());
	}

	public function test_nested_groups(): void
	{
		$this->router->group('/api', function (Router $router) {
			$router->group('/v1', function (Router $router) {
				$router->get('/posts', function () {
					return Response::json(['version' => 1]);
				});
			});
		});

		$request = Request::create(method: 'GET', uri: '/api/v1/posts');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertStringContainsString('"version":1', $response->getBody());
	}

	public function test_group_with_parameters(): void
	{
		$this->router->group('/api/v1', function (Router $router) {
			$router->get('/posts/{id}', function (Request $req, array $params) {
				return Response::json(['id' => $params['id']]);
			});
		});

		$request = Request::create(method: 'GET', uri: '/api/v1/posts/55');
		$response = $this->router->dispatch($request);

		$this->assertStringContainsString('"id":"55"', $response->getBody());
	}

	// ---------------------------------------------------------------
	// Middleware
	// ---------------------------------------------------------------

	public function test_middleware_runs_before_handler(): void
	{
		$order = [];

		$middleware = function (Request $req) use (&$order): ?Response {
			$order[] = 'middleware';
			return null; // Continue to handler
		};

		$this->router->get('/test', function () use (&$order) {
			$order[] = 'handler';
			return Response::html('ok');
		}, middleware: [$middleware]);

		$request = Request::create(method: 'GET', uri: '/test');
		$this->router->dispatch($request);

		$this->assertEquals(['middleware', 'handler'], $order);
	}

	public function test_middleware_can_short_circuit(): void
	{
		$handlerCalled = false;

		$authMiddleware = function (Request $req): Response {
			return Response::json(['error' => 'Unauthorized'], 401);
		};

		$this->router->get('/protected', function () use (&$handlerCalled) {
			$handlerCalled = true;
			return Response::html('secret');
		}, middleware: [$authMiddleware]);

		$request = Request::create(method: 'GET', uri: '/protected');
		$response = $this->router->dispatch($request);

		$this->assertEquals(401, $response->getStatusCode());
		$this->assertFalse($handlerCalled);
	}

	public function test_multiple_middleware_run_in_order(): void
	{
		$order = [];

		$mw1 = function () use (&$order): ?Response {
			$order[] = 'first';
			return null;
		};

		$mw2 = function () use (&$order): ?Response {
			$order[] = 'second';
			return null;
		};

		$this->router->get('/test', function () use (&$order) {
			$order[] = 'handler';
			return Response::html('ok');
		}, middleware: [$mw1, $mw2]);

		$request = Request::create(method: 'GET', uri: '/test');
		$this->router->dispatch($request);

		$this->assertEquals(['first', 'second', 'handler'], $order);
	}

	public function test_group_middleware_applied_to_all_routes(): void
	{
		$middlewareCalled = 0;

		$mw = function () use (&$middlewareCalled): ?Response {
			$middlewareCalled++;
			return null;
		};

		$this->router->group('/admin', function (Router $router) {
			$router->get('/dashboard', function () {
				return Response::html('dashboard');
			});

			$router->get('/settings', function () {
				return Response::html('settings');
			});
		}, middleware: [$mw]);

		$this->router->dispatch(Request::create(method: 'GET', uri: '/admin/dashboard'));
		$this->router->dispatch(Request::create(method: 'GET', uri: '/admin/settings'));

		$this->assertEquals(2, $middlewareCalled);
	}

	public function test_group_middleware_combined_with_route_middleware(): void
	{
		$order = [];

		$groupMw = function () use (&$order): ?Response {
			$order[] = 'group';
			return null;
		};

		$routeMw = function () use (&$order): ?Response {
			$order[] = 'route';
			return null;
		};

		$this->router->group('/admin', function (Router $router) use ($routeMw) {
			$router->get('/special', function () {
				return Response::html('ok');
			}, middleware: [$routeMw]);
		}, middleware: [$groupMw]);

		$request = Request::create(method: 'GET', uri: '/admin/special');
		$this->router->dispatch($request);

		$this->assertEquals(['group', 'route'], $order);
	}

	// ---------------------------------------------------------------
	// 404 and 405
	// ---------------------------------------------------------------

	public function test_404_for_no_match(): void
	{
		$this->router->get('/exists', function () {
			return Response::html('here');
		});

		$request = Request::create(method: 'GET', uri: '/does-not-exist');
		$response = $this->router->dispatch($request);

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function test_405_for_wrong_method(): void
	{
		$this->router->get('/only-get', function () {
			return Response::html('get only');
		});

		$request = Request::create(method: 'POST', uri: '/only-get');
		$response = $this->router->dispatch($request);

		$this->assertEquals(405, $response->getStatusCode());
		$this->assertEquals('GET', $response->getHeader('Allow'));
	}

	public function test_405_lists_all_allowed_methods(): void
	{
		$this->router->get('/resource', function () {
			return Response::html('get');
		});

		$this->router->post('/resource', function () {
			return Response::html('post');
		});

		$request = Request::create(method: 'DELETE', uri: '/resource');
		$response = $this->router->dispatch($request);

		$this->assertEquals(405, $response->getStatusCode());

		$allowed = $response->getHeader('Allow');
		$this->assertStringContainsString('GET', $allowed);
		$this->assertStringContainsString('POST', $allowed);
	}

	// ---------------------------------------------------------------
	// Handler return type coercion
	// ---------------------------------------------------------------

	public function test_string_return_becomes_html(): void
	{
		$this->router->get('/text', function () {
			return 'plain text';
		});

		$request = Request::create(method: 'GET', uri: '/text');
		$response = $this->router->dispatch($request);

		$this->assertEquals('plain text', $response->getBody());
		$this->assertTrue($response->isHtml());
	}

	public function test_array_return_becomes_json(): void
	{
		$this->router->get('/data', function () {
			return ['key' => 'value'];
		});

		$request = Request::create(method: 'GET', uri: '/data');
		$response = $this->router->dispatch($request);

		$this->assertTrue($response->isJson());
		$this->assertStringContainsString('"key":"value"', $response->getBody());
	}

	// ---------------------------------------------------------------
	// Path normalization
	// ---------------------------------------------------------------

	public function test_trailing_slash_normalized(): void
	{
		$this->router->get('/blog', function () {
			return Response::html('blog');
		});

		// Both with and without trailing slash should match
		$request = Request::create(method: 'GET', uri: '/blog/');
		$response = $this->router->dispatch($request);

		// After normalization, /blog/ becomes /blog
		$this->assertEquals(200, $response->getStatusCode());
	}

	public function test_double_slashes_in_path_normalized(): void
	{
		$this->router->get('/blog/posts', function () {
			return Response::html('posts');
		});

		$request = Request::create(method: 'GET', uri: '/blog///posts');
		$response = $this->router->dispatch($request);

		$this->assertEquals(200, $response->getStatusCode());
	}

	// ---------------------------------------------------------------
	// Inspection
	// ---------------------------------------------------------------

	public function test_route_count(): void
	{
		$this->assertEquals(0, $this->router->routeCount());

		$this->router->get('/a', function () { return ''; });
		$this->router->post('/b', function () { return ''; });

		$this->assertEquals(2, $this->router->routeCount());
	}

	public function test_get_routes(): void
	{
		$this->router->get('/test', function () { return ''; });

		$routes = $this->router->getRoutes();
		$this->assertCount(1, $routes);
		$this->assertEquals('GET', $routes[0]['method']);
		$this->assertEquals('/test', $routes[0]['pattern']);
	}

	// ---------------------------------------------------------------
	// First match wins
	// ---------------------------------------------------------------

	public function test_first_match_wins(): void
	{
		$this->router->get('/posts/{id}', function (Request $req, array $params) {
			return Response::html('by id: ' . $params['id']);
		});

		$this->router->get('/posts/latest', function () {
			return Response::html('latest');
		});

		// /posts/latest matches the first route (id=latest) because it was registered first
		$request = Request::create(method: 'GET', uri: '/posts/latest');
		$response = $this->router->dispatch($request);

		$this->assertEquals('by id: latest', $response->getBody());
	}

	public function test_exact_before_parameter_when_registered_first(): void
	{
		// Register exact route FIRST
		$this->router->get('/posts/latest', function () {
			return Response::html('latest');
		});

		$this->router->get('/posts/{id}', function (Request $req, array $params) {
			return Response::html('by id: ' . $params['id']);
		});

		$request = Request::create(method: 'GET', uri: '/posts/latest');
		$response = $this->router->dispatch($request);

		$this->assertEquals('latest', $response->getBody());
	}

	// ---------------------------------------------------------------
	// Request passed to handler
	// ---------------------------------------------------------------

	public function test_request_passed_to_handler(): void
	{
		$this->router->get('/echo', function (Request $req) {
			return Response::json(['method' => $req->method(), 'path' => $req->path()]);
		});

		$request = Request::create(method: 'GET', uri: '/echo');
		$response = $this->router->dispatch($request);

		$body = json_decode($response->getBody(), true);
		$this->assertEquals('GET', $body['method']);
		$this->assertEquals('/echo', $body['path']);
	}
}
