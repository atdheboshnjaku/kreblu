<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class SettingsController extends BaseController
{
	public function index(AdminLayout $layout): string
	{
		$layout->setTitle('Settings');
		$layout->setActivePage('settings');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);
		$tab = $this->request->query('tab', 'general');

		$opts = [];
		$allOpts = $db->table('options')->get();
		foreach ($allOpts as $row) { $opts[$row->option_key] = $row->option_value; }

		if ($this->request->method() === 'POST') {
			$savedTab = $this->request->input('_tab', 'general');
			$fieldsPerTab = $this->getSettingsFields();

			if ($savedTab === 'permalinks') {
				$structure = $this->request->input('permalink_structure', '/{slug}');
				if ($structure === 'custom') {
					$custom = $this->request->input('permalink_custom', '/{slug}');
					$_POST['permalink_structure'] = $custom !== '' ? $custom : '/{slug}';
				}
			}

			if (isset($fieldsPerTab[$savedTab])) {
				foreach ($fieldsPerTab[$savedTab] as $field) {
					$value = $this->request->input($field, '');
					$existing = $db->table('options')->where('option_key', '=', $field)->first();
					if ($existing) {
						$db->table('options')->where('option_key', '=', $field)->update(['option_value' => $value]);
					} else {
						$db->table('options')->insert(['option_key' => $field, 'option_value' => $value, 'autoload' => 1]);
					}
					$opts[$field] = $value;
				}
			}

			if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
			$layout->addNotice('success', 'Settings saved.');
			$tab = $savedTab;
		}

		$tabs = ['general' => 'General', 'reading' => 'Reading', 'writing' => 'Writing', 'permalinks' => 'Permalinks', 'email' => 'Email'];
		$tabNav = '<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--kb-border);padding-bottom:0;">';
		foreach ($tabs as $key => $label) {
			$activeStyle = $tab === $key ? 'font-weight:700;color:var(--kb-rust);border-bottom:2px solid var(--kb-rust);margin-bottom:-2px;' : 'color:var(--kb-text-secondary);';
			$tabNav .= '<a href="/kb-admin/settings?tab=' . $e($key) . '" style="padding:8px 16px;font-size:13px;text-decoration:none;' . $activeStyle . '">' . $e($label) . '</a>';
		}
		$tabNav .= '</div>';

		$formContent = match ($tab) {
			'reading'    => $this->settingsReading($opts, $e),
			'writing'    => $this->settingsWriting($opts, $e),
			'permalinks' => $this->settingsPermalinks($opts, $e),
			'email'      => $this->settingsEmail($opts, $e),
			default      => $this->settingsGeneral($opts, $e),
		};

		$content = <<<HTML
		<div class="kb-content-header"><h2>Settings</h2></div>
		{$tabNav}
		<form method="POST" action="/kb-admin/settings?tab={$e($tab)}">
			<input type="hidden" name="_tab" value="{$e($tab)}">
			{$formContent}
			<div style="margin-top:20px;"><button type="submit" class="kb-btn kb-btn-primary">Save settings</button></div>
		</form>
HTML;

		return $layout->render($content);
	}

	private function settingsGeneral(array $opts, \Closure $e): string
	{
		return '<div class="kb-card"><div class="kb-card-header"><h3>General</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><label class="kb-label">Site name</label><input type="text" name="site_name" class="kb-input" value="' . $e($opts['site_name'] ?? '') . '"></div>'
			. '<div class="kb-form-group"><label class="kb-label">Site description</label><input type="text" name="site_description" class="kb-input" value="' . $e($opts['site_description'] ?? '') . '"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">A short tagline explaining what this site is about.</span></div>'
			. '<div class="kb-form-group"><label class="kb-label">Site URL</label><input type="url" name="site_url" class="kb-input" value="' . $e($opts['site_url'] ?? '') . '" style="max-width:400px;"></div>'
			. '<div class="kb-form-group"><label class="kb-label">Timezone</label><input type="text" name="timezone" class="kb-input" value="' . $e($opts['timezone'] ?? 'UTC') . '" style="max-width:240px;" placeholder="e.g. Europe/London, America/New_York"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">Used for scheduled posts and date display.</span></div>'
			. '<div class="kb-form-group"><label class="kb-label">Date format</label><input type="text" name="date_format" class="kb-input" value="' . $e($opts['date_format'] ?? 'F j, Y') . '" style="max-width:200px;"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">PHP date format. Examples: F j, Y &middot; d/m/Y &middot; Y-m-d</span></div>'
			. '<div class="kb-form-group"><label class="kb-label">Time format</label><input type="text" name="time_format" class="kb-input" value="' . $e($opts['time_format'] ?? 'g:i a') . '" style="max-width:200px;"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">PHP time format. Examples: g:i a (3:45 pm) &middot; H:i (15:45)</span></div>'
			. '</div></div>';
	}

	private function settingsReading(array $opts, \Closure $e): string
	{
		$homeLatest = ($opts['homepage_display'] ?? 'latest') === 'latest' ? ' checked' : '';
		$homeStatic = ($opts['homepage_display'] ?? 'latest') === 'static' ? ' checked' : '';

		return '<div class="kb-card"><div class="kb-card-header"><h3>Reading</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><label class="kb-label">Homepage display</label><div style="display:flex;flex-direction:column;gap:8px;margin-top:4px;"><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="radio" name="homepage_display" value="latest"' . $homeLatest . ' style="accent-color:var(--kb-rust);"> Your latest posts</label><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="radio" name="homepage_display" value="static"' . $homeStatic . ' style="accent-color:var(--kb-rust);"> A static page</label></div></div>'
			. '<div class="kb-form-group"><label class="kb-label">Posts per page</label><input type="number" name="posts_per_page" class="kb-input" value="' . $e($opts['posts_per_page'] ?? '10') . '" style="max-width:100px;" min="1" max="100"></div>'
			. '<div class="kb-form-group"><label class="kb-label">Feed shows</label><select name="feed_display" class="kb-select" style="max-width:200px;"><option value="full"' . $this->selected($opts['feed_display'] ?? 'full', 'full') . '>Full text</option><option value="excerpt"' . $this->selected($opts['feed_display'] ?? 'full', 'excerpt') . '>Excerpt</option></select></div>'
			. '<div class="kb-form-group"><label class="kb-label">Search engine visibility</label><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-top:4px;"><input type="checkbox" name="discourage_search_engines" value="1"' . $this->checked($opts['discourage_search_engines'] ?? '0') . ' style="accent-color:var(--kb-rust);width:15px;height:15px;"> Discourage search engines from indexing this site</label><span style="font-size:11px;color:var(--kb-text-hint);margin-top:4px;display:block;">Adds a noindex meta tag. Search engines may still index the site.</span></div>'
			. '</div></div>';
	}

	private function settingsWriting(array $opts, \Closure $e): string
	{
		return '<div class="kb-card"><div class="kb-card-header"><h3>Writing</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><label class="kb-label">Default post status</label><select name="default_post_status" class="kb-select" style="max-width:200px;"><option value="draft"' . $this->selected($opts['default_post_status'] ?? 'draft', 'draft') . '>Draft</option><option value="published"' . $this->selected($opts['default_post_status'] ?? 'draft', 'published') . '>Published</option></select></div>'
			. '<div class="kb-form-group"><label class="kb-label">Default comment status</label><select name="default_comment_status" class="kb-select" style="max-width:200px;"><option value="open"' . $this->selected($opts['default_comment_status'] ?? 'open', 'open') . '>Open</option><option value="closed"' . $this->selected($opts['default_comment_status'] ?? 'open', 'closed') . '>Closed</option></select></div>'
			. '<div class="kb-form-group"><label class="kb-label">Auto-save interval (seconds)</label><input type="number" name="autosave_interval" class="kb-input" value="' . $e($opts['autosave_interval'] ?? '60') . '" style="max-width:100px;" min="0" max="300"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">How often the editor auto-saves drafts. Set to 0 to disable.</span></div>'
			. '<div class="kb-form-group"><label class="kb-label">Excerpt length (words)</label><input type="number" name="excerpt_length" class="kb-input" value="' . $e($opts['excerpt_length'] ?? '55') . '" style="max-width:100px;" min="10" max="500"></div>'
			. '</div></div>';
	}

	private function settingsPermalinks(array $opts, \Closure $e): string
	{
		$current = $opts['permalink_structure'] ?? '/{slug}';
		$structures = ['/{slug}' => 'Post name — /sample-post', '/{year}/{month}/{slug}' => 'Date and name — /2026/03/sample-post', '/{year}/{slug}' => 'Year and name — /2026/sample-post', '/{id}' => 'Numeric — /123'];

		$radios = '';
		foreach ($structures as $value => $label) {
			$chk = $current === $value ? ' checked' : '';
			$radios .= '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:6px 0;"><input type="radio" name="permalink_structure" value="' . $e($value) . '"' . $chk . ' style="accent-color:var(--kb-rust);"> ' . $e($label) . '</label>';
		}

		$isCustom = !isset($structures[$current]);
		$customChecked = $isCustom ? ' checked' : '';
		$customValue = $isCustom ? $e($current) : '';

		return '<div class="kb-card"><div class="kb-card-header"><h3>Permalink structure</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><div style="display:flex;flex-direction:column;gap:2px;">' . $radios
			. '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:6px 0;"><input type="radio" name="permalink_structure" value="custom"' . $customChecked . ' style="accent-color:var(--kb-rust);" id="kb-permalink-custom-radio"> Custom</label></div></div>'
			. '<div class="kb-form-group"><label class="kb-label">Custom structure</label><input type="text" name="permalink_custom" id="kb-permalink-custom" class="kb-input" value="' . $customValue . '" style="max-width:400px;" placeholder="/{year}/{month}/{slug}"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">Available tags: {slug}, {id}, {year}, {month}, {day}, {category}</span></div>'
			. '</div></div>'
			. '<script>document.querySelectorAll(\'input[name="permalink_structure"]\').forEach(function(r){r.addEventListener("change",function(){if(this.value==="custom")document.getElementById("kb-permalink-custom")?.focus();});});document.getElementById("kb-permalink-custom")?.addEventListener("focus",function(){document.getElementById("kb-permalink-custom-radio").checked=true;});</script>';
	}

	private function settingsEmail(array $opts, \Closure $e): string
	{
		$smtpChecked = ($opts['smtp_enabled'] ?? '0') === '1' ? ' checked' : '';

		return '<div class="kb-card kb-mb-lg"><div class="kb-card-header"><h3>Email sending</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><label class="kb-label">From name</label><input type="text" name="email_from_name" class="kb-input" value="' . $e($opts['email_from_name'] ?? $opts['site_name'] ?? '') . '" style="max-width:300px;"></div>'
			. '<div class="kb-form-group"><label class="kb-label">From email address</label><input type="email" name="email_from_address" class="kb-input" value="' . $e($opts['email_from_address'] ?? '') . '" style="max-width:300px;" placeholder="noreply@yourdomain.com"></div>'
			. '</div></div>'
			. '<div class="kb-card"><div class="kb-card-header"><h3>SMTP configuration</h3></div><div class="kb-card-body">'
			. '<div class="kb-form-group"><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="checkbox" name="smtp_enabled" value="1"' . $smtpChecked . ' style="accent-color:var(--kb-rust);width:15px;height:15px;"> Enable SMTP</label><span style="font-size:11px;color:var(--kb-text-hint);margin-top:4px;display:block;">When disabled, Kreblu uses PHP\'s built-in mail() function.</span></div>'
			. '<div class="kb-form-group"><label class="kb-label">SMTP host</label><input type="text" name="smtp_host" class="kb-input" value="' . $e($opts['smtp_host'] ?? '') . '" style="max-width:300px;" placeholder="smtp.example.com"></div>'
			. '<div class="kb-form-group"><label class="kb-label">SMTP port</label><input type="number" name="smtp_port" class="kb-input" value="' . $e($opts['smtp_port'] ?? '587') . '" style="max-width:100px;"></div>'
			. '<div class="kb-form-group"><label class="kb-label">Encryption</label><select name="smtp_encryption" class="kb-select" style="max-width:150px;"><option value="tls"' . $this->selected($opts['smtp_encryption'] ?? 'tls', 'tls') . '>TLS</option><option value="ssl"' . $this->selected($opts['smtp_encryption'] ?? 'tls', 'ssl') . '>SSL</option><option value="none"' . $this->selected($opts['smtp_encryption'] ?? 'tls', 'none') . '>None</option></select></div>'
			. '<div class="kb-form-group"><label class="kb-label">SMTP username</label><input type="text" name="smtp_username" class="kb-input" value="' . $e($opts['smtp_username'] ?? '') . '" style="max-width:300px;"></div>'
			. '<div class="kb-form-group"><label class="kb-label">SMTP password</label><input type="password" name="smtp_password" class="kb-input" value="' . $e($opts['smtp_password'] ?? '') . '" style="max-width:300px;" placeholder="Enter to change"></div>'
			. '</div></div>';
	}

	private function getSettingsFields(): array
	{
		return [
			'general' => ['site_name', 'site_description', 'site_url', 'timezone', 'date_format', 'time_format'],
			'reading' => ['homepage_display', 'posts_per_page', 'feed_display', 'discourage_search_engines'],
			'writing' => ['default_post_status', 'default_comment_status', 'autosave_interval', 'excerpt_length'],
			'permalinks' => ['permalink_structure', 'permalink_custom'],
			'email' => ['email_from_name', 'email_from_address', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'],
		];
	}
}
