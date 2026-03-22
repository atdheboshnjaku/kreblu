<?php declare(strict_types=1);

namespace Kreblu\Core\Http;

/**
 * HTTP Response
 *
 * Builds and sends HTTP responses. Supports HTML, JSON, redirects,
 * file downloads, and custom headers. Collects output before sending
 * so that plugins can modify it via the after_render filter.
 */
final class Response
{
	private int $statusCode = 200;
	private string $body = '';
	private string $contentType = 'text/html; charset=UTF-8';

	/** @var array<string, string> Response headers */
	private array $headers = [];

	/** @var array<string, array{value: string, expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}> */
	private array $cookies = [];

	private bool $sent = false;

	// ---------------------------------------------------------------
	// Static factory methods
	// ---------------------------------------------------------------

	/**
	 * Create an HTML response.
	 */
	public static function html(string $body, int $status = 200): self
	{
		$response = new self();
		$response->body = $body;
		$response->statusCode = $status;
		$response->contentType = 'text/html; charset=UTF-8';
		return $response;
	}

	/**
	 * Create a JSON response.
	 *
	 * @param mixed $data Data to encode as JSON
	 */
	public static function json(mixed $data, int $status = 200): self
	{
		$response = new self();
		$response->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$response->statusCode = $status;
		$response->contentType = 'application/json; charset=UTF-8';
		return $response;
	}

	/**
	 * Create a redirect response.
	 */
	public static function redirect(string $url, int $status = 302): self
	{
		$response = new self();
		$response->statusCode = $status;
		$response->headers['Location'] = $url;
		return $response;
	}

	/**
	 * Create a 404 Not Found response.
	 */
	public static function notFound(string $body = ''): self
	{
		$response = new self();
		$response->statusCode = 404;
		$response->body = $body ?: '<!DOCTYPE html><html><head><title>404 Not Found</title></head>'
			. '<body><h1>404 Not Found</h1><p>The page you requested could not be found.</p></body></html>';
		return $response;
	}

	/**
	 * Create a 403 Forbidden response.
	 */
	public static function forbidden(string $body = ''): self
	{
		$response = new self();
		$response->statusCode = 403;
		$response->body = $body ?: '<!DOCTYPE html><html><head><title>403 Forbidden</title></head>'
			. '<body><h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p></body></html>';
		return $response;
	}

	/**
	 * Create a 500 Internal Server Error response.
	 */
	public static function serverError(string $body = ''): self
	{
		$response = new self();
		$response->statusCode = 500;
		$response->body = $body ?: '<!DOCTYPE html><html><head><title>500 Server Error</title></head>'
			. '<body><h1>500 Internal Server Error</h1><p>Something went wrong.</p></body></html>';
		return $response;
	}

	/**
	 * Create an empty response with just a status code.
	 */
	public static function empty(int $status = 204): self
	{
		$response = new self();
		$response->statusCode = $status;
		return $response;
	}

	// ---------------------------------------------------------------
	// Setters (chainable)
	// ---------------------------------------------------------------

	/**
	 * Set the response body.
	 */
	public function setBody(string $body): self
	{
		$this->body = $body;
		return $this;
	}

	/**
	 * Set the HTTP status code.
	 */
	public function setStatus(int $code): self
	{
		$this->statusCode = $code;
		return $this;
	}

	/**
	 * Set a response header.
	 */
	public function setHeader(string $name, string $value): self
	{
		$this->headers[$name] = $value;
		return $this;
	}

	/**
	 * Set the content type.
	 */
	public function setContentType(string $type): self
	{
		$this->contentType = $type;
		return $this;
	}

	/**
	 * Set a cookie to be sent with the response.
	 */
	public function setCookie(
		string $name,
		string $value,
		int $expires = 0,
		string $path = '/',
		string $domain = '',
		bool $secure = true,
		bool $httponly = true,
		string $samesite = 'Lax',
	): self {
		$this->cookies[$name] = [
			'value'    => $value,
			'expires'  => $expires,
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly'  => $httponly,
			'samesite' => $samesite,
		];
		return $this;
	}

	/**
	 * Delete a cookie by setting it to expire in the past.
	 */
	public function deleteCookie(string $name, string $path = '/', string $domain = ''): self
	{
		return $this->setCookie($name, '', time() - 3600, $path, $domain);
	}

	// ---------------------------------------------------------------
	// Getters
	// ---------------------------------------------------------------

	/**
	 * Get the response body.
	 */
	public function getBody(): string
	{
		return $this->body;
	}

	/**
	 * Get the HTTP status code.
	 */
	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	/**
	 * Get a response header value.
	 */
	public function getHeader(string $name): ?string
	{
		return $this->headers[$name] ?? null;
	}

	/**
	 * Get all response headers.
	 *
	 * @return array<string, string>
	 */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * Get the content type.
	 */
	public function getContentType(): string
	{
		return $this->contentType;
	}

	/**
	 * Check if this is an HTML response.
	 */
	public function isHtml(): bool
	{
		return str_contains($this->contentType, 'text/html');
	}

	/**
	 * Check if this is a JSON response.
	 */
	public function isJson(): bool
	{
		return str_contains($this->contentType, 'application/json');
	}

	/**
	 * Check if this is a redirect response.
	 */
	public function isRedirect(): bool
	{
		return $this->statusCode >= 300 && $this->statusCode < 400;
	}

	/**
	 * Check if the response has been sent.
	 */
	public function isSent(): bool
	{
		return $this->sent;
	}

	// ---------------------------------------------------------------
	// Send
	// ---------------------------------------------------------------

	/**
	 * Send the response to the client.
	 *
	 * Sets status code, sends headers, cookies, and outputs the body.
	 * Can only be called once.
	 */
	public function send(): void
	{
		if ($this->sent) {
			return;
		}

		$this->sent = true;

		// Status code
		http_response_code($this->statusCode);

		// Content-Type header
		if ($this->body !== '' || $this->contentType !== '') {
			header('Content-Type: ' . $this->contentType);
		}

		// Custom headers
		foreach ($this->headers as $name => $value) {
			header("{$name}: {$value}");
		}

		// Cookies
		foreach ($this->cookies as $name => $options) {
			setcookie($name, $options['value'], [
				'expires'  => $options['expires'],
				'path'     => $options['path'],
				'domain'   => $options['domain'],
				'secure'   => $options['secure'],
				'httponly'  => $options['httponly'],
				'samesite' => $options['samesite'],
			]);
		}

		// Body
		if ($this->body !== '') {
			echo $this->body;
		}
	}
}
