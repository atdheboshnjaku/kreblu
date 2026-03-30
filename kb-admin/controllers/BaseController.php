<?php declare(strict_types=1);

namespace Kreblu\Admin;

use Kreblu\Core\App;
use Kreblu\Core\Http\Request;

abstract class BaseController
{
	public function __construct(
		protected readonly App $app,
		protected readonly Request $request,
	) {}

	protected function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
	}

	protected function selected(string $current, string $value): string
	{
		return $current === $value ? 'selected' : '';
	}

	protected function checked(string $value): string
	{
		return $value === '1' ? ' checked' : '';
	}

	protected function slugify(string $text): string
	{
		$text = strtolower(trim($text));
		$text = preg_replace('/[^a-z0-9\s-]/', '', $text);
		$text = preg_replace('/[\s-]+/', '-', $text);
		return trim($text, '-');
	}
}
