<?php declare(strict_types=1);

namespace Kreblu\Core\Api\Endpoints;

use Kreblu\Core\Api\ApiController;
use Kreblu\Core\Database\Connection;
use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;

/**
 * Options API Endpoint
 *
 * GET    /api/v1/options            — List all options
 * GET    /api/v1/options/{key}      — Get single option
 * PUT    /api/v1/options/{key}      — Set option value
 * DELETE /api/v1/options/{key}      — Delete option
 */
final class OptionsEndpoint extends ApiController
{
	public function __construct(
		private readonly Connection $db,
	) {}

	public function index(Request $request): Response
	{
		$options = $this->db->table('options')
			->orderBy('option_key', 'ASC')
			->get();

		$formatted = [];
		foreach ($options as $opt) {
			$formatted[] = [
				'key'      => $opt->option_key,
				'value'    => $this->maybeUnserialize($opt->option_value),
				'autoload' => (bool) $opt->autoload,
			];
		}

		return $this->success($formatted);
	}

	public function show(Request $request, array $params): Response
	{
		$key = $params['key'] ?? '';

		$option = $this->db->table('options')
			->where('option_key', '=', $key)
			->first();

		if ($option === null) {
			return $this->notFound("Option '{$key}' not found.");
		}

		return $this->success([
			'key'      => $option->option_key,
			'value'    => $this->maybeUnserialize($option->option_value),
			'autoload' => (bool) $option->autoload,
		]);
	}

	public function update(Request $request, array $params): Response
	{
		$key = $params['key'] ?? '';
		$input = $request->isJson() ? ($request->json() ?? []) : $request->all();

		if (!array_key_exists('value', $input)) {
			return $this->validationError(['value' => 'The value field is required.']);
		}

		$value = is_array($input['value']) ? json_encode($input['value']) : (string) $input['value'];
		$autoload = (bool) ($input['autoload'] ?? true);

		$existing = $this->db->table('options')
			->where('option_key', '=', $key)
			->first();

		if ($existing !== null) {
			$this->db->table('options')
				->where('option_key', '=', $key)
				->update([
					'option_value' => $value,
					'autoload'     => $autoload ? 1 : 0,
				]);
		} else {
			$this->db->table('options')->insert([
				'option_key'   => $key,
				'option_value' => $value,
				'autoload'     => $autoload ? 1 : 0,
			]);
		}

		return $this->success([
			'key'      => $key,
			'value'    => $this->maybeUnserialize($value),
			'autoload' => $autoload,
		]);
	}

	public function destroy(Request $request, array $params): Response
	{
		$key = $params['key'] ?? '';

		$existing = $this->db->table('options')
			->where('option_key', '=', $key)
			->first();

		if ($existing === null) {
			return $this->notFound("Option '{$key}' not found.");
		}

		$this->db->table('options')
			->where('option_key', '=', $key)
			->delete();

		return $this->noContent();
	}

	private function maybeUnserialize(string $value): mixed
	{
		$decoded = json_decode($value, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
	}
}
