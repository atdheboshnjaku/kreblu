<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class CategoryController extends BaseController
{
	public function list(AdminLayout $layout): string
	{
		$layout->setTitle('Categories');
		$layout->setActivePage('categories');

		$db = $this->app->db();
		$taxonomy = new \Kreblu\Core\Content\TaxonomyManager($db);
		$e = fn(string $s): string => $this->e($s);

		if ($this->request->method() === 'POST') {
			$name = trim($this->request->input('name', ''));
			if ($name !== '') {
				$taxonomy->createTerm(['taxonomy' => 'category', 'name' => $name]);
				$layout->addNotice('success', 'Category created.');
			}
		}

		$terms = $db->table('terms')->where('taxonomy', '=', 'category')->orderBy('name', 'ASC')->get();

		$rows = '';
		foreach ($terms as $term) {
			$rows .= '<tr><td style="font-weight:700;">' . $e($term->name) . '</td><td style="color:var(--kb-text-hint);">' . $e($term->slug) . '</td><td>' . (int) $term->count . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="3"><div class="kb-empty"><h3>No categories</h3></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Categories</h2></div>
		<div class="kb-grid-equal">
			<div class="kb-card">
				<div class="kb-card-header"><h3>Add category</h3></div>
				<div class="kb-card-body">
					<form method="POST" action="/kb-admin/categories">
						<div class="kb-form-group"><label class="kb-label">Name</label><input type="text" name="name" class="kb-input" required></div>
						<button type="submit" class="kb-btn kb-btn-primary kb-btn-sm">Add category</button>
					</form>
				</div>
			</div>
			<div class="kb-card">
				<table class="kb-table"><thead><tr><th>Name</th><th>Slug</th><th>Count</th></tr></thead><tbody>{$rows}</tbody></table>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}
}
