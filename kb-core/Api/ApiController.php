<?php declare(strict_types=1);

namespace Kreblu\Core\Api;

use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;

/**
 * Base API Controller
 *
 * Provides shared helpers for all API endpoint handlers.
 * All API responses are JSON with consistent structure.
 *
 * Success: {"data": {...}, "meta": {...}}
 * Error:   {"error": "message", "code": "ERROR_CODE", "details": {...}}
 */
abstract class ApiController
{
	/**
	 * Return a success response with data.
	 *
	 * @param mixed $data The response payload
	 * @param int $status HTTP status code
	 */
	protected function success(mixed $data, int $status = 200): Response
	{
		return Response::json(['data' => $data], $status);
	}

	/**
	 * Return a paginated success response.
	 *
	 * @param array<int, mixed> $items The page of items
	 * @param int $total Total number of items across all pages
	 * @param int $page Current page number
	 * @param int $perPage Items per page
	 */
	protected function paginated(array $items, int $total, int $page, int $perPage): Response
	{
		return Response::json([
			'data' => $items,
			'meta' => [
				'total'        => $total,
				'page'         => $page,
				'per_page'     => $perPage,
				'total_pages'  => (int) ceil($total / max($perPage, 1)),
			],
		]);
	}

	/**
	 * Return a created response (201) with the new resource.
	 */
	protected function created(mixed $data): Response
	{
		return Response::json(['data' => $data], 201);
	}

	/**
	 * Return a no-content response (204).
	 */
	protected function noContent(): Response
	{
		return Response::empty(204);
	}

	/**
	 * Return an error response.
	 *
	 * @param string $message Human-readable error message
	 * @param int $status HTTP status code
	 * @param string $code Machine-readable error code
	 * @param array<string, mixed> $details Additional error details
	 */
	protected function error(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $details = []): Response
	{
		$body = [
			'error' => $message,
			'code'  => $code,
		];

		if (!empty($details)) {
			$body['details'] = $details;
		}

		return Response::json($body, $status);
	}

	/**
	 * Return a 401 Unauthorized error.
	 */
	protected function unauthorized(string $message = 'Authentication required.'): Response
	{
		return $this->error($message, 401, 'UNAUTHORIZED');
	}

	/**
	 * Return a 403 Forbidden error.
	 */
	protected function forbidden(string $message = 'Insufficient permissions.'): Response
	{
		return $this->error($message, 403, 'FORBIDDEN');
	}

	/**
	 * Return a 404 Not Found error.
	 */
	protected function notFound(string $message = 'Resource not found.'): Response
	{
		return $this->error($message, 404, 'NOT_FOUND');
	}

	/**
	 * Return a 422 Validation error with field details.
	 *
	 * @param array<string, string> $errors Field => error message pairs
	 */
	protected function validationError(array $errors): Response
	{
		return $this->error('Validation failed.', 422, 'VALIDATION_ERROR', ['fields' => $errors]);
	}

	/**
	 * Extract pagination parameters from a request.
	 *
	 * @return array{page: int, per_page: int, offset: int}
	 */
	protected function paginationParams(Request $request): array
	{
		$page = max(1, (int) ($request->query('page', '1')));
		$perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));
		$offset = ($page - 1) * $perPage;

		return [
			'page'     => $page,
			'per_page' => $perPage,
			'offset'   => $offset,
		];
	}

	/**
	 * Validate that required fields are present in request input.
	 *
	 * @param array<int, string> $required List of required field names
	 * @param array<string, mixed> $input The input data to check
	 * @return array<string, string> Field => error message for missing fields (empty if all present)
	 */
	protected function validateRequired(array $required, array $input): array
	{
		$errors = [];

		foreach ($required as $field) {
			if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
				$errors[$field] = "The {$field} field is required.";
			}
		}

		return $errors;
	}
}
