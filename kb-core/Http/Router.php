<?php declare(strict_types=1);

namespace Kreblu\Core\Http;

/**
 * HTTP Router
 *
 * Matches incoming requests to registered route handlers.
 * Supports named URL parameters, route groups with shared prefixes
 * and middleware, and all standard HTTP methods.
 *
 * Routes are matched in registration order — first match wins.
 *
 * Usage:
 *   $router->get('/posts', [PostController::class, 'index']);
 *   $router->get('/posts/{id}', [PostController::class, 'show']);
 *   $router->post('/posts', [PostController::class, 'store']);
 *
 *   $router->group('/api/v1', function (Router $router) {
 *       $router->get('/posts', [ApiPostController::class, 'index']);
 *   }, middleware: [ApiAuthMiddleware::class]);
 */
final class Router
{
	/**
	 * @var array<int, array{method: string, pattern: string, handler: callable|array<int, mixed>, middleware: array<int, string|callable>}>
	 */
	private array $routes = [];

	/**
	 * Current group prefix being applied.
	 */
	private string $groupPrefix = '';

	/**
	 * Current group middleware being applied.
	 *
	 * @var array<int, string|callable>
	 */
	private array $groupMiddleware = [];

	/**
	 * Named route parameters extracted from the last matched route.
	 *
	 * @var array<string, string>
	 */
	private array $currentParams = [];

	// ---------------------------------------------------------------
	// Route registration
	// ---------------------------------------------------------------

	/**
	 * Register a GET route.
	 *
	 * @param string $pattern URL pattern with optional {param} placeholders
	 * @param callable|array<int, mixed> $handler Controller callable or [ClassName, method] array
	 * @param array<int, string|callable> $middleware Middleware to run before the handler
	 */
	public function get(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('GET', $pattern, $handler, $middleware);
	}

	/**
	 * Register a POST route.
	 */
	public function post(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('POST', $pattern, $handler, $middleware);
	}

	/**
	 * Register a PUT route.
	 */
	public function put(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('PUT', $pattern, $handler, $middleware);
	}

	/**
	 * Register a DELETE route.
	 */
	public function delete(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('DELETE', $pattern, $handler, $middleware);
	}

	/**
	 * Register a PATCH route.
	 */
	public function patch(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('PATCH', $pattern, $handler, $middleware);
	}

	/**
	 * Register a route that responds to any HTTP method.
	 */
	public function any(string $pattern, callable|array $handler, array $middleware = []): self
	{
		return $this->addRoute('ANY', $pattern, $handler, $middleware);
	}

	/**
	 * Register a route for multiple specific HTTP methods.
	 *
	 * @param array<int, string> $methods HTTP methods (e.g., ['GET', 'POST'])
	 */
	public function match(array $methods, string $pattern, callable|array $handler, array $middleware = []): self
	{
		foreach ($methods as $method) {
			$this->addRoute(strtoupper($method), $pattern, $handler, $middleware);
		}
		return $this;
	}

	// ---------------------------------------------------------------
	// Route groups
	// ---------------------------------------------------------------

	/**
	 * Create a route group with a shared prefix and optional middleware.
	 *
	 * Routes registered inside the callback will have the prefix prepended
	 * and the middleware applied automatically.
	 *
	 * @param string $prefix URL prefix (e.g., '/api/v1')
	 * @param callable(Router): void $callback Function that registers routes
	 * @param array<int, string|callable> $middleware Middleware for all routes in the group
	 */
	public function group(string $prefix, callable $callback, array $middleware = []): self
	{
		$previousPrefix = $this->groupPrefix;
		$previousMiddleware = $this->groupMiddleware;

		$this->groupPrefix = $previousPrefix . $prefix;
		$this->groupMiddleware = array_merge($previousMiddleware, $middleware);

		$callback($this);

		$this->groupPrefix = $previousPrefix;
		$this->groupMiddleware = $previousMiddleware;

		return $this;
	}

	// ---------------------------------------------------------------
	// Dispatching
	// ---------------------------------------------------------------

	/**
	 * Dispatch a request to the matching route handler.
	 *
	 * Finds the first matching route, runs its middleware chain,
	 * then calls the handler. Returns the Response.
	 *
	 * @return Response The response from the handler or a 404/405 response
	 */
	public function dispatch(Request $request): Response
	{
		$method = $request->method();
		$path = $this->normalizePath($request->path());

		$methodMatch = false;

		foreach ($this->routes as $route) {
			$params = $this->matchRoute($route['pattern'], $path);

			if ($params === null) {
				continue;
			}

			// Pattern matches — check method
			if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
				$methodMatch = true;
				continue;
			}

			// Store matched parameters
			$this->currentParams = $params;

			// Run middleware chain then handler
			return $this->runMiddlewareChain($route['middleware'], $request, $route['handler'], $params);
		}

		// If the path matched but the method didn't, return 405
		if ($methodMatch) {
			$allowed = $this->getAllowedMethods($path);
			return Response::json(
				['error' => 'Method Not Allowed', 'allowed' => $allowed],
				405
			)->setHeader('Allow', implode(', ', $allowed));
		}

		// No match at all — 404
		return Response::notFound();
	}

	/**
	 * Get the parameters extracted from the last matched route.
	 *
	 * @return array<string, string>
	 */
	public function getParams(): array
	{
		return $this->currentParams;
	}

	/**
	 * Get a specific parameter from the last matched route.
	 */
	public function getParam(string $name, ?string $default = null): ?string
	{
		return $this->currentParams[$name] ?? $default;
	}

	// ---------------------------------------------------------------
	// Inspection
	// ---------------------------------------------------------------

	/**
	 * Get all registered routes.
	 *
	 * @return array<int, array{method: string, pattern: string, handler: callable|array<int, mixed>, middleware: array<int, string|callable>}>
	 */
	public function getRoutes(): array
	{
		return $this->routes;
	}

	/**
	 * Get the number of registered routes.
	 */
	public function routeCount(): int
	{
		return count($this->routes);
	}

	// ---------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------

	/**
	 * Add a route to the registry.
	 *
	 * @param array<int, string|callable> $middleware
	 */
	private function addRoute(string $method, string $pattern, callable|array $handler, array $middleware): self
	{
		$fullPattern = $this->normalizePath($this->groupPrefix . $pattern);
		$fullMiddleware = array_merge($this->groupMiddleware, $middleware);

		$this->routes[] = [
			'method'     => $method,
			'pattern'    => $fullPattern,
			'handler'    => $handler,
			'middleware'  => $fullMiddleware,
		];

		return $this;
	}

	/**
	 * Try to match a route pattern against a request path.
	 *
	 * Returns an array of named parameters on match, or null on no match.
	 *
	 * Patterns support:
	 *   /posts          — exact match
	 *   /posts/{id}     — matches /posts/123, captures id=123
	 *   /posts/{id}/comments — matches /posts/123/comments
	 *
	 * @return array<string, string>|null Matched parameters or null
	 */
	private function matchRoute(string $pattern, string $path): ?array
	{
		// Exact match (no parameters)
		if ($pattern === $path) {
			return [];
		}

		// Check if pattern contains parameters
		if (!str_contains($pattern, '{')) {
			return null;
		}

		// Convert pattern to regex
		// {param} becomes a named capture group matching one or more non-slash characters
		$regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
		$regex = '#^' . $regex . '$#';

		if (preg_match($regex, $path, $matches)) {
			// Extract only named captures (not numeric keys)
			$params = [];
			foreach ($matches as $key => $value) {
				if (is_string($key)) {
					$params[$key] = $value;
				}
			}
			return $params;
		}

		return null;
	}

	/**
	 * Run the middleware chain, then the route handler.
	 *
	 * Middleware can short-circuit by returning a Response directly.
	 * If middleware returns null, the next middleware (or handler) runs.
	 *
	 * @param array<int, string|callable> $middleware
	 * @param callable|array<int, mixed> $handler
	 * @param array<string, string> $params
	 */
	private function runMiddlewareChain(array $middleware, Request $request, callable|array $handler, array $params): Response
	{
		foreach ($middleware as $mw) {
			$result = $this->callMiddleware($mw, $request);

			if ($result instanceof Response) {
				return $result;
			}
		}

		return $this->callHandler($handler, $request, $params);
	}

	/**
	 * Call a single middleware.
	 *
	 * Middleware can be:
	 * - A callable that receives (Request) and returns Response|null
	 * - A class name string with a handle(Request): ?Response method
	 */
	private function callMiddleware(string|callable $middleware, Request $request): ?Response
	{
		if (is_callable($middleware)) {
			$result = $middleware($request);
			return $result instanceof Response ? $result : null;
		}

		// Class name — instantiate and call handle()
		if (is_string($middleware) && class_exists($middleware)) {
			$instance = new $middleware();
			if (method_exists($instance, 'handle')) {
				$result = $instance->handle($request);
				return $result instanceof Response ? $result : null;
			}
		}

		return null;
	}

	/**
	 * Call the route handler.
	 *
	 * Handler can be:
	 * - A callable (closure) that receives (Request, params)
	 * - An array [ClassName, methodName] that gets instantiated and called
	 *
	 * @param callable|array<int, mixed> $handler
	 * @param array<string, string> $params
	 */
	private function callHandler(callable|array $handler, Request $request, array $params): Response
	{
		if (is_callable($handler)) {
			$result = $handler($request, $params);
		} elseif (is_array($handler) && count($handler) === 2) {
			[$className, $method] = $handler;

			if (!class_exists($className)) {
				return Response::serverError("Controller class {$className} not found.");
			}

			$instance = new $className();

			if (!method_exists($instance, $method)) {
				return Response::serverError("Method {$method} not found on {$className}.");
			}

			$result = $instance->$method($request, $params);
		} else {
			return Response::serverError('Invalid route handler.');
		}

		// If handler returned a Response, use it directly
		if ($result instanceof Response) {
			return $result;
		}

		// If handler returned a string, wrap it in an HTML response
		if (is_string($result)) {
			return Response::html($result);
		}

		// If handler returned an array or object, wrap it in a JSON response
		if (is_array($result) || is_object($result)) {
			return Response::json($result);
		}

		return Response::empty();
	}

	/**
	 * Get all HTTP methods that are allowed for a given path.
	 *
	 * @return array<int, string>
	 */
	private function getAllowedMethods(string $path): array
	{
		$methods = [];

		foreach ($this->routes as $route) {
			if ($route['method'] === 'ANY') {
				return ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
			}

			$params = $this->matchRoute($route['pattern'], $path);
			if ($params !== null) {
				$methods[] = $route['method'];
			}
		}

		return array_unique($methods);
	}

	/**
	 * Normalize a URL path: ensure leading slash, remove trailing slash,
	 * collapse multiple slashes.
	 */
	private function normalizePath(string $path): string
	{
		$path = '/' . trim($path, '/');
		$path = preg_replace('#/+#', '/', $path) ?? $path;

		// Keep root path as /
		if ($path === '') {
			$path = '/';
		}

		return $path;
	}
}
