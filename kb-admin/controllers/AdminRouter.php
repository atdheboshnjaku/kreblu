<?php declare(strict_types=1);

/**
 * K Hub Admin Router
 *
 * Routes /kb-admin/* requests to the appropriate admin page.
 * Each page returns HTML content which gets wrapped in AdminLayout.
 */

namespace Kreblu\Admin;

use Kreblu\Core\App;
use Kreblu\Core\Http\Request;

final class AdminRouter
{
	public function __construct(
		private readonly App $app,
		private readonly Request $request,
	) {}

	/**
	 * Handle the admin request and return the full HTML response.
	 */
	public function handle(): string
	{
		$path = $this->request->path();

		// Strip /kb-admin prefix and trailing slashes
		$route = trim(str_replace('/kb-admin', '', $path), '/');
		if ($route === '') {
			$route = 'dashboard';
		}

		$layout = new AdminLayout($this->app, $this->request);

		// Route to page controller
		$content = match (true) {
			$route === 'dashboard'      => $this->dashboard($layout),
			$route === 'posts'          => $this->postsList($layout),
			$route === 'posts/new'      => $this->postEditor($layout),
			str_starts_with($route, 'posts/edit/') => $this->postEditor($layout, (int) str_replace('posts/edit/', '', $route)),
			$route === 'pages'          => $this->pagesList($layout),
			$route === 'pages/new'      => $this->pageEditor($layout),
			str_starts_with($route, 'pages/edit/') => $this->pageEditor($layout, (int) str_replace('pages/edit/', '', $route)),
			$route === 'comments'       => $this->commentsList($layout),
			$route === 'categories'     => $this->categoriesList($layout),
			$route === 'media'          => $this->mediaList($layout),
			$route === 'users'          => $this->usersList($layout),
			$route === 'settings'       => $this->settings($layout),
			$route === 'kaps'           => $this->comingSoon($layout, 'Kaps', 'kaps'),
			$route === 'templates'      => $this->comingSoon($layout, 'Templates', 'templates'),
			$route === 'logout'         => $this->logout(),
			default                     => $this->notFound($layout),
		};

		return $content;
	}

	private function dashboard(AdminLayout $layout): string
	{
		$layout->setTitle('Dashboard');
		$layout->setActivePage('dashboard');

		$db = $this->app->db();
		$postCount = $db->table('posts')->where('type', '=', 'post')->count();
		$pageCount = $db->table('posts')->where('type', '=', 'page')->count();
		$publishedCount = $db->table('posts')->where('status', '=', 'published')->count();
		$draftCount = $db->table('posts')->where('status', '=', 'draft')->count();
		$commentCount = $db->table('comments')->count();
		$userCount = $db->table('users')->count();

		$recentPosts = $db->table('posts')
			->orderBy('created_at', 'DESC')
			->limit(5)
			->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$postsHtml = '';
		foreach ($recentPosts as $post) {
			$statusClass = $post->status === 'published' ? 'published' : 'draft';
			$editUrl = $post->type === 'page' ? '/kb-admin/pages/edit/' . $post->id : '/kb-admin/posts/edit/' . $post->id;
			$postsHtml .= <<<ROW
			<tr>
				<td><a href="{$editUrl}" style="font-weight:700;color:var(--kb-text);text-decoration:none;">{$e($post->title ?: '(no title)')}</a></td>
				<td>{$e($post->type)}</td>
				<td><span class="kb-badge kb-badge-{$statusClass}">{$e(ucfirst($post->status))}</span></td>
				<td style="color:var(--kb-text-hint);font-size:12px;">{$e($post->created_at)}</td>
			</tr>
ROW;
		}

		$content = <<<HTML
		<div class="kb-stats">
			<div class="kb-stat">
				<div class="kb-stat-label">Published</div>
				<div class="kb-stat-value">{$publishedCount}</div>
			</div>
			<div class="kb-stat">
				<div class="kb-stat-label">Drafts</div>
				<div class="kb-stat-value">{$draftCount}</div>
			</div>
			<div class="kb-stat">
				<div class="kb-stat-label">Comments</div>
				<div class="kb-stat-value">{$commentCount}</div>
			</div>
			<div class="kb-stat">
				<div class="kb-stat-label">Users</div>
				<div class="kb-stat-value">{$userCount}</div>
			</div>
		</div>

		<div class="kb-card kb-mb-lg">
			<div class="kb-card-header">
				<h3>Recent content</h3>
				<a href="/kb-admin/posts">View all posts</a>
			</div>
			<table class="kb-table">
				<thead>
					<tr>
						<th>Title</th>
						<th>Type</th>
						<th>Status</th>
						<th>Date</th>
					</tr>
				</thead>
				<tbody>
					{$postsHtml}
				</tbody>
			</table>
		</div>

		<div class="kb-grid-equal">
			<div class="kb-card">
				<div class="kb-card-header"><h3>Quick draft</h3></div>
				<div class="kb-card-body">
					<form method="POST" action="/kb-admin/posts/new" class="kb-quick-draft">
						<input type="text" name="title" class="kb-input" placeholder="Post title...">
						<textarea name="body" class="kb-textarea" rows="3" placeholder="Start writing..."></textarea>
						<input type="hidden" name="status" value="draft">
						<input type="hidden" name="type" value="post">
						<div><button type="submit" class="kb-btn kb-btn-primary kb-btn-sm">Save draft</button></div>
					</form>
				</div>
			</div>
			<div class="kb-card">
				<div class="kb-card-header"><h3>System</h3></div>
				<div class="kb-card-body" style="font-size:12px;">
					<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--kb-border);color:var(--kb-text-secondary);"><span>Kreblu</span><span style="color:var(--kb-text);">1.0.0-dev</span></div>
					<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--kb-border);color:var(--kb-text-secondary);"><span>PHP</span><span style="color:var(--kb-text);">{$e(PHP_VERSION)}</span></div>
					<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--kb-border);color:var(--kb-text-secondary);"><span>Active template</span><span style="color:var(--kb-text);">developer-default</span></div>
					<div style="display:flex;justify-content:space-between;padding:7px 0;color:var(--kb-text-secondary);"><span>Page cache</span><span style="color:var(--kb-success);">Active</span></div>
				</div>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}

	private function postsList(AdminLayout $layout): string
	{
		$layout->setTitle('Posts');
		$layout->setActivePage('posts');

		$db = $this->app->db();
		$posts = $db->table('posts')
			->where('type', '=', 'post')
			->orderBy('created_at', 'DESC')
			->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($posts as $post) {
			$statusClass = $post->status;
			$rows .= <<<ROW
			<tr>
				<td><a href="/kb-admin/posts/edit/{$post->id}" style="font-weight:700;color:var(--kb-text);text-decoration:none;">{$e($post->title ?: '(no title)')}</a></td>
				<td><span class="kb-badge kb-badge-{$statusClass}">{$e(ucfirst($post->status))}</span></td>
				<td style="color:var(--kb-text-hint);font-size:12px;">{$e($post->created_at)}</td>
				<td class="kb-table-actions">
					<a href="/kb-admin/posts/edit/{$post->id}">Edit</a>
					<a href="/{$e($post->slug)}" target="_blank">View</a>
				</td>
			</tr>
ROW;
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No posts yet</h3><p>Create your first post to get started.</p><a href="/kb-admin/posts/new" class="kb-btn kb-btn-primary">+ New post</a></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header">
			<h2>All posts</h2>
			<a href="/kb-admin/posts/new" class="kb-btn kb-btn-primary">+ New post</a>
		</div>
		<div class="kb-card">
			<table class="kb-table">
				<thead>
					<tr>
						<th>Title</th>
						<th>Status</th>
						<th>Date</th>
						<th></th>
					</tr>
				</thead>
				<tbody>{$rows}</tbody>
			</table>
		</div>
HTML;

		return $layout->render($content);
	}

	private function pagesList(AdminLayout $layout): string
	{
		$layout->setTitle('Pages');
		$layout->setActivePage('pages');

		$db = $this->app->db();
		$pages = $db->table('posts')
			->where('type', '=', 'page')
			->orderBy('created_at', 'DESC')
			->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($pages as $page) {
			$rows .= <<<ROW
			<tr>
				<td><a href="/kb-admin/pages/edit/{$page->id}" style="font-weight:700;color:var(--kb-text);text-decoration:none;">{$e($page->title ?: '(no title)')}</a></td>
				<td><span class="kb-badge kb-badge-{$page->status}">{$e(ucfirst($page->status))}</span></td>
				<td style="color:var(--kb-text-hint);font-size:12px;">{$e($page->created_at)}</td>
				<td class="kb-table-actions">
					<a href="/kb-admin/pages/edit/{$page->id}">Edit</a>
					<a href="/{$e($page->slug)}" target="_blank">View</a>
				</td>
			</tr>
ROW;
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No pages yet</h3><p>Create your first page.</p><a href="/kb-admin/pages/new" class="kb-btn kb-btn-primary">+ New page</a></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header">
			<h2>All pages</h2>
			<a href="/kb-admin/pages/new" class="kb-btn kb-btn-primary">+ New page</a>
		</div>
		<div class="kb-card">
			<table class="kb-table">
				<thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead>
				<tbody>{$rows}</tbody>
			</table>
		</div>
HTML;

		return $layout->render($content);
	}

	private function postEditor(AdminLayout $layout, ?int $id = null): string
	{
		$type = str_contains($this->request->path(), '/pages') ? 'page' : 'post';
		$layout->setTitle($id ? 'Edit ' . $type : 'New ' . $type);
		$layout->setActivePage($type === 'page' ? 'pages' : 'posts');

		$db = $this->app->db();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		// Handle save
		if ($this->request->method() === 'POST') {
			$posts = new \Kreblu\Core\Content\PostManager($db);
			$data = [
				'title'     => $this->request->input('title', ''),
				'body'      => $this->request->input('body', ''),
				'status'    => $this->request->input('status', 'draft'),
				'type'      => $this->request->input('type', $type),
				'author_id' => $this->app->auth()->currentUserId(),
			];

			if ($id) {
				$posts->update($id, $data);
				$layout->addNotice('success', ucfirst($type) . ' updated successfully.');
			} else {
				$id = $posts->create($data);
				header('Location: /kb-admin/' . $type . 's/edit/' . $id);
				exit;
			}
		}

		$post = null;
		if ($id) {
			$post = $db->table('posts')->where('id', '=', $id)->first();
		}

		$title = $post->title ?? '';
		$body = $post->body ?? '';
		$status = $post->status ?? 'draft';
		$slug = $post->slug ?? '';
		$listUrl = $type === 'page' ? '/kb-admin/pages' : '/kb-admin/posts';
		$actionUrl = $id ? "/kb-admin/{$type}s/edit/{$id}" : "/kb-admin/{$type}s/new";

		$content = <<<HTML
		<div style="margin-bottom:16px;">
			<a href="{$listUrl}" style="font-size:12px;color:var(--kb-text-secondary);">&larr; Back to {$type}s</a>
		</div>
		<form method="POST" action="{$actionUrl}">
			<input type="hidden" name="type" value="{$type}">
			<div class="kb-grid-2">
				<div class="kb-stack">
					<div class="kb-card">
						<div class="kb-card-body">
							<div class="kb-form-group">
								<input type="text" name="title" class="kb-input" value="{$e($title)}" placeholder="Title..." style="font-size:18px;font-weight:700;padding:12px;border:none;background:transparent;">
							</div>
							<div class="kb-form-group">
								<textarea name="body" class="kb-textarea" rows="16" placeholder="Write your content here..." style="font-size:14px;line-height:1.7;">{$e($body)}</textarea>
							</div>
						</div>
					</div>
				</div>
				<div class="kb-stack">
					<div class="kb-card">
						<div class="kb-card-header"><h3>Publish</h3></div>
						<div class="kb-card-body">
							<div class="kb-form-group">
								<label class="kb-label">Status</label>
								<select name="status" class="kb-select">
									<option value="draft" {$this->selected($status, 'draft')}>Draft</option>
									<option value="published" {$this->selected($status, 'published')}>Published</option>
								</select>
							</div>
							{$this->slugField($slug)}
							<div style="display:flex;gap:8px;margin-top:12px;">
								<button type="submit" class="kb-btn kb-btn-primary">Save</button>
								<a href="{$listUrl}" class="kb-btn kb-btn-outline">Cancel</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</form>
HTML;

		return $layout->render($content);
	}

	private function pageEditor(AdminLayout $layout, ?int $id = null): string
	{
		return $this->postEditor($layout, $id);
	}

	private function commentsList(AdminLayout $layout): string
	{
		$layout->setTitle('Comments');
		$layout->setActivePage('comments');

		$comments = $this->app->db()->table('comments')
			->orderBy('created_at', 'DESC')
			->limit(50)
			->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($comments as $comment) {
			$rows .= '<tr><td>' . $e($comment->author_name ?? 'Anonymous') . '</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $e(mb_substr($comment->body, 0, 80)) . '</td><td><span class="kb-badge kb-badge-' . $comment->status . '">' . $e(ucfirst($comment->status)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($comment->created_at) . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No comments yet</h3><p>Comments will appear here once visitors start engaging.</p></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Comments</h2></div>
		<div class="kb-card">
			<table class="kb-table">
				<thead><tr><th>Author</th><th>Comment</th><th>Status</th><th>Date</th></tr></thead>
				<tbody>{$rows}</tbody>
			</table>
		</div>
HTML;

		return $layout->render($content);
	}

	private function categoriesList(AdminLayout $layout): string
	{
		$layout->setTitle('Categories');
		$layout->setActivePage('categories');

		$terms = $this->app->db()->table('terms')
			->where('taxonomy', '=', 'category')
			->orderBy('name', 'ASC')
			->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($terms as $term) {
			$rows .= '<tr><td style="font-weight:700;">' . $e($term->name) . '</td><td style="color:var(--kb-text-hint);">' . $e($term->slug) . '</td><td>' . (int) $term->count . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="3"><div class="kb-empty"><h3>No categories</h3></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Categories</h2></div>
		<div class="kb-card">
			<table class="kb-table">
				<thead><tr><th>Name</th><th>Slug</th><th>Count</th></tr></thead>
				<tbody>{$rows}</tbody>
			</table>
		</div>
HTML;

		return $layout->render($content);
	}

	private function mediaList(AdminLayout $layout): string
	{
		$layout->setTitle('Media');
		$layout->setActivePage('media');

		$content = <<<HTML
		<div class="kb-content-header"><h2>Media library</h2></div>
		<div class="kb-card">
			<div class="kb-card-body">
				<div class="kb-empty">
					<h3>Media library</h3>
					<p>Upload and manage images, documents, and files. Full media management coming in Phase 2.2.</p>
				</div>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}

	private function usersList(AdminLayout $layout): string
	{
		$layout->setTitle('Users');
		$layout->setActivePage('users');

		$users = $this->app->db()->table('users')->orderBy('created_at', 'DESC')->get();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($users as $user) {
			$rows .= '<tr><td style="font-weight:700;">' . $e($user->username) . '</td><td>' . $e($user->email) . '</td><td><span class="kb-badge kb-badge-' . $user->role . '">' . $e(ucfirst($user->role)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($user->created_at) . '</td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Users</h2></div>
		<div class="kb-card">
			<table class="kb-table">
				<thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
				<tbody>{$rows}</tbody>
			</table>
		</div>
HTML;

		return $layout->render($content);
	}

	private function settings(AdminLayout $layout): string
	{
		$layout->setTitle('Settings');
		$layout->setActivePage('settings');

		$db = $this->app->db();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		// Handle save
		if ($this->request->method() === 'POST') {
			$fields = ['site_name', 'site_description', 'posts_per_page', 'date_format'];
			foreach ($fields as $field) {
				$value = $this->request->input($field, '');
				$existing = $db->table('options')->where('option_key', '=', $field)->first();
				if ($existing) {
					$db->table('options')->where('option_key', '=', $field)->update(['option_value' => $value]);
				} else {
					$db->table('options')->insert(['option_key' => $field, 'option_value' => $value, 'autoload' => 1]);
				}
			}
			$layout->addNotice('success', 'Settings saved.');
		}

		$opts = [];
		$rows = $db->table('options')->where('autoload', '=', 1)->get();
		foreach ($rows as $row) {
			$opts[$row->option_key] = $row->option_value;
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>General settings</h2></div>
		<form method="POST" action="/kb-admin/settings">
			<div class="kb-card kb-mb-lg">
				<div class="kb-card-body">
					<div class="kb-form-group">
						<label class="kb-label">Site name</label>
						<input type="text" name="site_name" class="kb-input" value="{$e($opts['site_name'] ?? '')}">
					</div>
					<div class="kb-form-group">
						<label class="kb-label">Site description</label>
						<input type="text" name="site_description" class="kb-input" value="{$e($opts['site_description'] ?? '')}">
					</div>
					<div class="kb-form-group">
						<label class="kb-label">Posts per page</label>
						<input type="number" name="posts_per_page" class="kb-input" value="{$e($opts['posts_per_page'] ?? '10')}" style="max-width:120px;">
					</div>
					<div class="kb-form-group">
						<label class="kb-label">Date format</label>
						<input type="text" name="date_format" class="kb-input" value="{$e($opts['date_format'] ?? 'F j, Y')}" style="max-width:200px;">
					</div>
					<button type="submit" class="kb-btn kb-btn-primary">Save settings</button>
				</div>
			</div>
		</form>
HTML;

		return $layout->render($content);
	}

	private function comingSoon(AdminLayout $layout, string $title, string $page): string
	{
		$layout->setTitle($title);
		$layout->setActivePage($page);

		$content = <<<HTML
		<div class="kb-card">
			<div class="kb-card-body">
				<div class="kb-empty">
					<h3>{$title}</h3>
					<p>{$title} management is coming soon.</p>
				</div>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}

	private function notFound(AdminLayout $layout): string
	{
		$layout->setTitle('Not Found');
		$content = '<div class="kb-card"><div class="kb-card-body"><div class="kb-empty"><h3>Page not found</h3><p>The admin page you requested doesn\'t exist.</p><a href="/kb-admin/" class="kb-btn kb-btn-primary">Go to dashboard</a></div></div></div>';
		return $layout->render($content);
	}

	private function logout(): string
	{
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		$_SESSION = [];
		session_destroy();
		setcookie('kb_session', '', ['expires' => time() - 3600, 'path' => '/']);
		header('Location: /kb-admin/');
		exit;
	}

	private function selected(string $current, string $value): string
	{
		return $current === $value ? 'selected' : '';
	}

	private function slugField(string $slug): string
	{
		if ($slug === '') {
			return '';
		}
		$e = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
		return '<div class="kb-form-group"><label class="kb-label">Slug</label><input type="text" class="kb-input" value="' . $e . '" disabled style="color:var(--kb-text-hint);"></div>';
	}
}