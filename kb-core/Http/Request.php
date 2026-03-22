<?php declare(strict_types=1);

namespace Kreblu\Core\Http;

/**
 * HTTP Request
 *
 * Wraps PHP superglobals ($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE)
 * into a clean, immutable, testable object. Once created, the request
 * object is the single source of truth for the current request.
 *
 * In production, create via Request::fromGlobals().
 * In tests, create via the constructor with custom data.
 */
final class Request
{
	/**
	 * @param array<string, mixed> $query    GET parameters
	 * @param array<string, mixed> $body     POST parameters
	 * @param array<string, mixed> $server   SERVER variables
	 * @param array<string, mixed> $files    Uploaded files
	 * @param array<string, mixed> $cookies  Cookies
	 * @param string|null          $rawBody  Raw request body (for JSON APIs)
	 */
	public function __construct(
		private readonly array   $query = [],
		private readonly array   $body = [],
		private readonly array   $server = [],
		private readonly array   $files = [],
		private readonly array   $cookies = [],
		private readonly ?string $rawBody = null,
	) {}

	/**
	 * Create a Request from PHP superglobals.
	 */
	public static function fromGlobals(): self
	{
		return new self(
			query:   $_GET,
			body:    $_POST,
			server:  $_SERVER,
			files:   $_FILES,
			cookies: $_COOKIE,
			rawBody: file_get_contents('php://input') ?: null,
		);
	}

	/**
	 * Create a test request with specific parameters.
	 *
	 * @param array<string, mixed> $options Override defaults
	 */
	public static function create(
		string $method = 'GET',
		string $uri = '/',
		array $query = [],
		array $body = [],
		array $server = [],
		array $files = [],
		array $cookies = [],
		?string $rawBody = null,
	): self {
		$defaultServer = [
			'REQUEST_METHOD' => strtoupper($method),
			'REQUEST_URI'    => $uri,
			'SERVER_NAME'    => 'localhost',
			'SERVER_PORT'    => 80,
			'HTTP_HOST'      => 'localhost',
			'QUERY_STRING'   => http_build_query($query),
		];

		return new self(
			query:   $query,
			body:    $body,
			server:  array_merge($defaultServer, $server),
			files:   $files,
			cookies: $cookies,
			rawBody: $rawBody,
		);
	}

	// ---------------------------------------------------------------
	// Method
	// ---------------------------------------------------------------

	/**
	 * Get the HTTP method (GET, POST, PUT, DELETE, PATCH, etc.)
	 */
	public function method(): string
	{
		return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
	}

	public function isGet(): bool
	{
		return $this->method() === 'GET';
	}

	public function isPost(): bool
	{
		return $this->method() === 'POST';
	}

	public function isPut(): bool
	{
		return $this->method() === 'PUT';
	}

	public function isDelete(): bool
	{
		return $this->method() === 'DELETE';
	}

	public function isPatch(): bool
	{
		return $this->method() === 'PATCH';
	}

	// ---------------------------------------------------------------
	// URL and Path
	// ---------------------------------------------------------------

	/**
	 * Get the request path without query string.
	 * Example: /blog/my-post
	 */
	public function path(): string
	{
		$uri = (string) ($this->server['REQUEST_URI'] ?? '/');
		$path = parse_url($uri, PHP_URL_PATH);
		return $path ?: '/';
	}

	/**
	 * Get the full request URI including query string.
	 * Example: /blog/my-post?page=2
	 */
	public function uri(): string
	{
		return (string) ($this->server['REQUEST_URI'] ?? '/');
	}

	/**
	 * Get the query string.
	 * Example: page=2&sort=date
	 */
	public function queryString(): string
	{
		return (string) ($this->server['QUERY_STRING'] ?? '');
	}

	/**
	 * Get the host name.
	 */
	public function host(): string
	{
		return (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
	}

	/**
	 * Get the scheme (http or https).
	 */
	public function scheme(): string
	{
		$https = $this->server['HTTPS'] ?? '';
		if ($https === 'on' || $https === '1') {
			return 'https';
		}

		// Check for proxy headers
		$forwardedProto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';
		if (strtolower((string) $forwardedProto) === 'https') {
			return 'https';
		}

		return 'http';
	}

	/**
	 * Check if the request was made over HTTPS.
	 */
	public function isSecure(): bool
	{
		return $this->scheme() === 'https';
	}

	/**
	 * Get the full URL of the request.
	 */
	public function fullUrl(): string
	{
		return $this->scheme() . '://' . $this->host() . $this->uri();
	}

	// ---------------------------------------------------------------
	// Input (GET and POST parameters)
	// ---------------------------------------------------------------

	/**
	 * Get a query (GET) parameter.
	 */
	public function query(string $key, mixed $default = null): mixed
	{
		return $this->query[$key] ?? $default;
	}

	/**
	 * Get all query parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function allQuery(): array
	{
		return $this->query;
	}

	/**
	 * Get a body (POST) parameter.
	 */
	public function input(string $key, mixed $default = null): mixed
	{
		// Check POST body first, then fall back to query
		return $this->body[$key] ?? $this->query[$key] ?? $default;
	}

	/**
	 * Get all input parameters (body merged with query, body takes precedence).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		return array_merge($this->query, $this->body);
	}

	/**
	 * Check if an input parameter exists (in body or query).
	 */
	public function has(string $key): bool
	{
		return isset($this->body[$key]) || isset($this->query[$key]);
	}

	/**
	 * Get only the specified keys from input.
	 *
	 * @param array<int, string> $keys
	 * @return array<string, mixed>
	 */
	public function only(array $keys): array
	{
		$all = $this->all();
		return array_intersect_key($all, array_flip($keys));
	}

	// ---------------------------------------------------------------
	// JSON Body
	// ---------------------------------------------------------------

	/**
	 * Get the raw request body.
	 */
	public function rawBody(): ?string
	{
		return $this->rawBody;
	}

	/**
	 * Parse the request body as JSON.
	 *
	 * @return array<string, mixed>|null Returns null if body is not valid JSON
	 */
	public function json(): ?array
	{
		if ($this->rawBody === null || $this->rawBody === '') {
			return null;
		}

		$decoded = json_decode($this->rawBody, true);

		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Get a value from the JSON body.
	 */
	public function jsonValue(string $key, mixed $default = null): mixed
	{
		$json = $this->json();
		return $json[$key] ?? $default;
	}

	/**
	 * Check if the request has a JSON content type.
	 */
	public function isJson(): bool
	{
		$contentType = $this->header('Content-Type', '');
		return str_contains($contentType, 'application/json');
	}

	// ---------------------------------------------------------------
	// Headers
	// ---------------------------------------------------------------

	/**
	 * Get a request header value.
	 *
	 * Header names are case-insensitive. Looks for HTTP_* server vars.
	 */
	public function header(string $name, mixed $default = null): mixed
	{
		// Convert header name to SERVER key format
		// Content-Type -> HTTP_CONTENT_TYPE (except Content-Type and Content-Length)
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

		// Special cases that don't have HTTP_ prefix
		if ($name === 'Content-Type' || $name === 'content-type') {
			return $this->server['CONTENT_TYPE'] ?? $this->server[$key] ?? $default;
		}
		if ($name === 'Content-Length' || $name === 'content-length') {
			return $this->server['CONTENT_LENGTH'] ?? $this->server[$key] ?? $default;
		}

		return $this->server[$key] ?? $default;
	}

	/**
	 * Get the Authorization header value.
	 */
	public function bearerToken(): ?string
	{
		$auth = $this->header('Authorization', '');

		if (str_starts_with((string) $auth, 'Bearer ')) {
			return substr((string) $auth, 7);
		}

		return null;
	}

	// ---------------------------------------------------------------
	// Cookies
	// ---------------------------------------------------------------

	/**
	 * Get a cookie value.
	 */
	public function cookie(string $name, mixed $default = null): mixed
	{
		return $this->cookies[$name] ?? $default;
	}

	// ---------------------------------------------------------------
	// Files
	// ---------------------------------------------------------------

	/**
	 * Get an uploaded file.
	 *
	 * @return array<string, mixed>|null The file array or null
	 */
	public function file(string $name): ?array
	{
		return $this->files[$name] ?? null;
	}

	/**
	 * Check if a file was uploaded for the given key.
	 */
	public function hasFile(string $name): bool
	{
		$file = $this->files[$name] ?? null;

		if ($file === null) {
			return false;
		}

		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		return $error !== UPLOAD_ERR_NO_FILE;
	}

	// ---------------------------------------------------------------
	// Server info
	// ---------------------------------------------------------------

	/**
	 * Get the client IP address.
	 *
	 * Checks proxy headers first, falls back to REMOTE_ADDR.
	 */
	public function ip(): string
	{
		// Check for proxy headers (in order of trust)
		$headers = [
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
		];

		foreach ($headers as $header) {
			$value = $this->server[$header] ?? null;
			if ($value !== null) {
				// X-Forwarded-For can contain multiple IPs, take the first
				$ips = explode(',', (string) $value);
				$ip = trim($ips[0]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}

		return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
	}

	/**
	 * Get the user agent string.
	 */
	public function userAgent(): string
	{
		return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
	}

	/**
	 * Get the referer URL.
	 */
	public function referer(): ?string
	{
		$referer = $this->server['HTTP_REFERER'] ?? null;
		return $referer !== null ? (string) $referer : null;
	}

	/**
	 * Check if the request is an AJAX/XHR request.
	 */
	public function isAjax(): bool
	{
		return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
	}

	/**
	 * Check if the request expects a JSON response.
	 */
	public function expectsJson(): bool
	{
		if ($this->isAjax()) {
			return true;
		}

		$accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
		return str_contains($accept, 'application/json');
	}

	/**
	 * Get the raw server variable array.
	 *
	 * @return array<string, mixed>
	 */
	public function serverAll(): array
	{
		return $this->server;
	}
}
