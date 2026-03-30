<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class TemplateController extends BaseController
{
	public function index(AdminLayout $layout): string
	{
		$layout->setTitle('Templates');
		$layout->setActivePage('templates');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);

		if ($this->request->method() === 'POST' && $this->request->input('_action') === 'activate') {
			$slug = $this->request->input('template_slug', '');
			$templatesDir = KREBLU_ROOT . '/kb-content/templates/' . $slug;
			if ($slug !== '' && is_dir($templatesDir)) {
				$existing = $db->table('options')->where('option_key', '=', 'active_template')->first();
				if ($existing) { $db->table('options')->where('option_key', '=', 'active_template')->update(['option_value' => $slug]); }
				else { $db->table('options')->insert(['option_key' => 'active_template', 'option_value' => $slug, 'autoload' => 1]); }
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
				$layout->addNotice('success', 'Template activated.');
			} else { $layout->addNotice('error', 'Template not found.'); }
		}

		$activeSlug = 'developer-default';
		$opt = $db->table('options')->where('option_key', '=', 'active_template')->first();
		if ($opt) { $activeSlug = $opt->option_value; }

		$templatesPath = KREBLU_ROOT . '/kb-content/templates';
		$templates = [];
		if (is_dir($templatesPath)) {
			foreach (new \DirectoryIterator($templatesPath) as $dir) {
				if ($dir->isDot() || !$dir->isDir()) continue;
				$slug = $dir->getFilename();
				$manifest = $this->loadManifest($templatesPath . '/' . $slug);
				$manifest['slug'] = $slug;
				$manifest['is_active'] = ($slug === $activeSlug);
				$manifest['path'] = $templatesPath . '/' . $slug;
				$phpFiles = glob($templatesPath . '/' . $slug . '/*.php');
				$manifest['file_count'] = $phpFiles ? count($phpFiles) : 0;
				$manifest['has_screenshot'] = file_exists($templatesPath . '/' . $slug . '/screenshot.png') || file_exists($templatesPath . '/' . $slug . '/screenshot.jpg');
				$templates[] = $manifest;
			}
		}

		usort($templates, function ($a, $b) {
			if ($a['is_active'] && !$b['is_active']) return -1;
			if (!$a['is_active'] && $b['is_active']) return 1;
			return strcmp($a['name'], $b['name']);
		});

		$activeTemplate = null;
		foreach ($templates as $t) { if ($t['is_active']) { $activeTemplate = $t; break; } }

		$activeHtml = $activeTemplate ? $this->renderDetail($activeTemplate, $e, true) : '';

		$gridHtml = '';
		foreach ($templates as $t) {
			$isActive = $t['is_active'];
			$borderStyle = $isActive ? 'border-color:var(--kb-rust);' : '';
			$badge = $isActive ? '<span class="kb-badge kb-badge-published" style="position:absolute;top:10px;right:10px;">Active</span>' : '';
			$screenshotHtml = '<div style="height:140px;background:var(--kb-bg-secondary);display:flex;align-items:center;justify-content:center;color:var(--kb-text-hint);font-size:12px;border-radius:var(--kb-radius) var(--kb-radius) 0 0;">No preview</div>';
			if ($t['has_screenshot']) {
				$ext = file_exists($t['path'] . '/screenshot.png') ? 'png' : 'jpg';
				$screenshotHtml = '<div style="height:140px;overflow:hidden;border-radius:var(--kb-radius) var(--kb-radius) 0 0;"><img src="/kb-content/templates/' . $e($t['slug']) . '/screenshot.' . $ext . '" style="width:100%;height:100%;object-fit:cover;" alt="' . $e($t['name']) . '"></div>';
			}
			$actionBtn = !$isActive ? '<form method="POST" action="/kb-admin/templates" style="display:inline;"><input type="hidden" name="_action" value="activate"><input type="hidden" name="template_slug" value="' . $e($t['slug']) . '"><button type="submit" class="kb-btn kb-btn-primary kb-btn-sm" onclick="return confirm(\'Activate ' . $e($t['name']) . '?\')">Activate</button></form>' : '';

			$gridHtml .= '<div class="kb-template-card" style="border:2px solid var(--kb-border);border-radius:var(--kb-radius);overflow:hidden;position:relative;background:var(--kb-card);transition:border-color 0.15s;' . $borderStyle . '">' . $badge . $screenshotHtml . '<div style="padding:12px 14px;"><div style="font-weight:700;font-size:14px;margin-bottom:2px;">' . $e($t['name']) . '</div><div style="font-size:12px;color:var(--kb-text-hint);margin-bottom:8px;">v' . $e($t['version']) . ' by ' . $e($t['author']) . '</div><div style="display:flex;gap:6px;align-items:center;">' . $actionBtn . '<button type="button" class="kb-btn kb-btn-outline kb-btn-sm" onclick="kbTemplateDetail(\'' . $e($t['slug']) . '\')">Details</button></div></div></div>';
		}

		if ($gridHtml === '') { $gridHtml = '<div class="kb-empty"><h3>No templates found</h3><p>Place template folders in kb-content/templates/</p></div>'; }

		$content = <<<HTML
		<div class="kb-content-header"><h2>Templates</h2></div>
		<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;">
			<div><div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));gap:14px;">{$gridHtml}</div></div>
			<div><div class="kb-card" id="kb-template-detail"><div class="kb-card-header"><h3>Active template</h3></div><div class="kb-card-body">{$activeHtml}</div></div></div>
		</div>
		<script>function kbTemplateDetail(slug){const detail=document.getElementById('kb-template-detail');if(detail)detail.scrollIntoView({behavior:'smooth'});}</script>
HTML;

		return $layout->render($content);
	}

	private function renderDetail(array $t, \Closure $e, bool $isActive): string
	{
		$html = '';
		if ($t['has_screenshot']) {
			$ext = file_exists($t['path'] . '/screenshot.png') ? 'png' : 'jpg';
			$html .= '<div style="margin-bottom:14px;border-radius:var(--kb-radius);overflow:hidden;"><img src="/kb-content/templates/' . $e($t['slug']) . '/screenshot.' . $ext . '" style="width:100%;display:block;" alt=""></div>';
		}
		$html .= '<div style="margin-bottom:14px;"><div style="font-size:16px;font-weight:700;margin-bottom:4px;">' . $e($t['name']) . '</div>';
		if ($isActive) { $html .= '<span class="kb-badge kb-badge-published">Active</span>'; }
		$html .= '</div>';
		$html .= '<div style="font-size:13px;color:var(--kb-text-secondary);margin-bottom:14px;">' . $e($t['description']) . '</div>';
		$html .= '<div style="font-size:12px;border-top:1px solid var(--kb-border);padding-top:10px;">';
		$rows = ['Version' => 'v' . $t['version'], 'Author' => $t['author_url'] ? '<a href="' . $e($t['author_url']) . '" target="_blank">' . $e($t['author']) . '</a>' : $e($t['author']), 'License' => $t['license'], 'Files' => $t['file_count'] . ' template file' . ($t['file_count'] !== 1 ? 's' : '')];
		if (!empty($t['locations'])) { $locs = []; foreach ($t['locations'] as $key => $label) { $locs[] = $e($label); } $rows['Menu locations'] = implode(', ', $locs); }
		foreach ($rows as $label => $value) { $html .= '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--kb-border);"><span style="color:var(--kb-text-hint);">' . $e($label) . '</span><span style="color:var(--kb-text);">' . $value . '</span></div>'; }
		$html .= '</div>';
		return $html;
	}

	private function loadManifest(string $path): array
	{
		$defaults = ['name' => basename($path), 'version' => '1.0.0', 'description' => '', 'author' => 'Unknown', 'author_url' => '', 'license' => 'Proprietary', 'requires_kreblu' => '1.0.0', 'locations' => [], 'settings' => []];
		$jsonPath = $path . '/theme.json';
		if (!file_exists($jsonPath)) return $defaults;
		$data = json_decode(file_get_contents($jsonPath), true);
		return is_array($data) ? array_merge($defaults, $data) : $defaults;
	}
}
