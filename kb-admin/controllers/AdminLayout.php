<?php declare(strict_types=1);

/**
 * K Hub Admin Layout
 *
 * The shell that wraps every admin page. Provides sidebar navigation,
 * topbar with actions, theme toggle (light/dark), and content area.
 *
 * Usage from admin page controllers:
 *   $layout = new AdminLayout($app, $request);
 *   $layout->setTitle('Posts');
 *   $layout->setActivePage('posts');
 *   echo $layout->render($contentHtml);
 */

namespace Kreblu\Admin;

use Kreblu\Core\App;
use Kreblu\Core\Http\Request;

final class AdminLayout
{
	private string $title = 'Dashboard';
	private string $activePage = 'dashboard';
	private array $notices = [];

	public function __construct(
		private readonly App $app,
		private readonly Request $request,
	) {}

	public function setTitle(string $title): void
	{
		$this->title = $title;
	}

	public function setActivePage(string $page): void
	{
		$this->activePage = $page;
	}

	public function addNotice(string $type, string $message): void
	{
		$this->notices[] = ['type' => $type, 'message' => $message];
	}

	public function render(string $content): string
	{
		$user = $this->app->auth()->currentUser();
		$displayName = $user->display_name ?: $user->username;
		$initials = $this->getInitials($displayName);
		$siteName = 'Kreblu';
		$siteUrl = '/';

		try {
			$opt = $this->app->db()->table('options')->where('option_key', '=', 'site_name')->first();
			if ($opt !== null) {
				$siteName = $opt->option_value;
			}
			$optUrl = $this->app->db()->table('options')->where('option_key', '=', 'site_url')->first();
			if ($optUrl !== null) {
				$siteUrl = $optUrl->option_value;
			}
		} catch (\Throwable) {}

		$postCount = 0;
		$commentCount = 0;
		$userCount = 0;
		try {
			$postCount = $this->app->db()->table('posts')->count();
			$commentCount = $this->app->db()->table('comments')->count();
			$userCount = $this->app->db()->table('users')->count();
		} catch (\Throwable) {}

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$active = fn(string $page): string => $this->activePage === $page ? ' active' : '';

		$noticesHtml = '';
		foreach ($this->notices as $notice) {
			$noticesHtml .= '<div class="kb-notice kb-notice-' . $e($notice['type']) . '">' . $e($notice['message']) . '</div>';
		}

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{$e($this->title)} — K Hub</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/kb-admin/assets/css/khub.css">
</head>
<body class="kb-admin" data-theme="light">
<div class="kb-layout">
	<!-- Sidebar -->
	<aside class="kb-sidebar" id="kb-sidebar">
		<div class="kb-sidebar-brand">
			<div class="kb-logo">K</div>
			<span class="kb-brand-text">K Hub</span>
		</div>

		<nav class="kb-nav">
			<a href="/kb-admin/" class="kb-nav-item{$active('dashboard')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
				<span class="nav-label">Dashboard</span>
			</a>
			<a href="/kb-admin/posts" class="kb-nav-item{$active('posts')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3h10v10H3z" rx="1"/><path d="M3 6h10"/></svg>
				<span class="nav-label">Posts</span>
			</a>
			<a href="/kb-admin/pages" class="kb-nav-item{$active('pages')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="10" height="10" rx="1"/><path d="M3 6h10"/></svg>
				<span class="nav-label">Pages</span>
			</a>
			<a href="/kb-admin/media" class="kb-nav-item{$active('media')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M5 8l2 2 4-4"/></svg>
				<span class="nav-label">Media</span>
			</a>

			<div class="kb-nav-section">Content</div>
			<a href="/kb-admin/comments" class="kb-nav-item{$active('comments')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3h10v7H6l-3 3V3z" rx="1"/></svg>
				<span class="nav-label">Comments</span>
			</a>
			<a href="/kb-admin/categories" class="kb-nav-item{$active('categories')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3h4v4H3zM9 3h4v4H9zM3 9h4v4H3z"/></svg>
				<span class="nav-label">Categories</span>
			</a>

			<div class="kb-nav-section">System</div>
			<a href="/kb-admin/kaps" class="kb-nav-item{$active('kaps')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h8M4 8h8M4 12h5"/></svg>
				<span class="nav-label">Kaps</span>
			</a>
			<a href="/kb-admin/templates" class="kb-nav-item{$active('templates')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5l5-2 5 2v4l-5 4-5-4z"/></svg>
				<span class="nav-label">Templates</span>
			</a>
			<a href="/kb-admin/users" class="kb-nav-item{$active('users')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="3"/><path d="M3 14c0-2.8 2.2-5 5-5s5 2.2 5 5"/></svg>
				<span class="nav-label">Users</span>
			</a>
			<a href="/kb-admin/settings" class="kb-nav-item{$active('settings')}">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="2"/><path d="M8 3v1M8 12v1M3 8h1M12 8h1M4.9 4.9l.7.7M10.4 10.4l.7.7M4.9 11.1l.7-.7M10.4 5.6l.7-.7"/></svg>
				<span class="nav-label">Settings</span>
			</a>
		</nav>

		<div class="kb-sidebar-toggle">
			<button onclick="toggleSidebar()" title="Toggle sidebar">
				<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
			</button>
		</div>
	</aside>

	<!-- Main -->
	<div class="kb-main">
		<header class="kb-topbar">
			<h1 class="kb-topbar-title">{$e($this->title)}</h1>
			<div class="kb-topbar-actions">
				<a href="/kb-admin/posts/new" class="kb-btn kb-btn-primary kb-btn-sm">+ New post</a>
				<button class="kb-theme-toggle" onclick="toggleTheme()" title="Toggle dark mode">
					<span id="theme-icon">☀</span>
				</button>
				<a href="{$e($siteUrl)}" class="kb-topbar-link" target="_blank">View site</a>
				<div class="kb-avatar">{$e($initials)}</div>
			</div>
		</header>

		<main class="kb-content">
			{$noticesHtml}
			{$content}
		</main>
	</div>
</div>

<script>
function toggleSidebar() {
	var s = document.getElementById('kb-sidebar');
	s.classList.toggle('collapsed');
	localStorage.setItem('kb_sidebar', s.classList.contains('collapsed') ? 'collapsed' : 'expanded');
}

function toggleTheme() {
	var b = document.body;
	var current = b.getAttribute('data-theme');
	var next = current === 'dark' ? 'light' : 'dark';
	b.setAttribute('data-theme', next);
	document.getElementById('theme-icon').textContent = next === 'dark' ? '☾' : '☀';
	localStorage.setItem('kb_theme', next);
}

(function() {
	var theme = localStorage.getItem('kb_theme');
	if (theme === 'dark') {
		document.body.setAttribute('data-theme', 'dark');
		document.getElementById('theme-icon').textContent = '☾';
	}
	var sidebar = localStorage.getItem('kb_sidebar');
	if (sidebar === 'collapsed') {
		document.getElementById('kb-sidebar').classList.add('collapsed');
	}
})();
</script>
</body>
</html>
HTML;
	}

	private function getInitials(string $name): string
	{
		$parts = explode(' ', trim($name));
		if (count($parts) >= 2) {
			return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[array_key_last($parts)], 0, 1));
		}
		return strtoupper(mb_substr($name, 0, 2));
	}
}