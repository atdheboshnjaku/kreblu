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

	public function handle(): string
	{
		$path = $this->request->path();
		$route = trim(str_replace('/kb-admin', '', $path), '/');
		if ($route === '') {
			$route = 'dashboard';
		}

		$layout = new AdminLayout($this->app, $this->request);

		$content = match (true) {
			$route === 'dashboard'      => $this->dashboard($layout),
			$route === 'posts'          => $this->postsList($layout),
			$route === 'posts/new'      => $this->postEditor($layout),
			str_starts_with($route, 'posts/edit/')   => $this->postEditor($layout, (int) str_replace('posts/edit/', '', $route)),
			str_starts_with($route, 'posts/delete/')  => $this->postDelete((int) str_replace('posts/delete/', '', $route), 'post'),
			str_starts_with($route, 'posts/trash/')   => $this->postTrash((int) str_replace('posts/trash/', '', $route), 'post'),
			str_starts_with($route, 'posts/restore/') => $this->postRestore((int) str_replace('posts/restore/', '', $route), 'post'),
			$route === 'pages'          => $this->pagesList($layout),
			$route === 'pages/new'      => $this->pageEditor($layout),
			str_starts_with($route, 'pages/edit/')    => $this->pageEditor($layout, (int) str_replace('pages/edit/', '', $route)),
			str_starts_with($route, 'pages/delete/')  => $this->postDelete((int) str_replace('pages/delete/', '', $route), 'page'),
			str_starts_with($route, 'pages/trash/')   => $this->postTrash((int) str_replace('pages/trash/', '', $route), 'page'),
			str_starts_with($route, 'pages/restore/') => $this->postRestore((int) str_replace('pages/restore/', '', $route), 'page'),
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

	// ---------------------------------------------------------------
	// Dashboard
	// ---------------------------------------------------------------

	private function dashboard(AdminLayout $layout): string
	{
		$layout->setTitle('Dashboard');
		$layout->setActivePage('dashboard');

		$db = $this->app->db();
		$publishedCount = $db->table('posts')->where('status', '=', 'published')->count();
		$draftCount = $db->table('posts')->where('status', '=', 'draft')->count();
		$commentCount = $db->table('comments')->count();
		$userCount = $db->table('users')->count();

		$recentPosts = $db->table('posts')->orderBy('created_at', 'DESC')->limit(5)->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$postsHtml = '';
		foreach ($recentPosts as $post) {
			$statusClass = $post->status === 'published' ? 'published' : ($post->status === 'trash' ? 'trash' : 'draft');
			$editUrl = $post->type === 'page' ? '/kb-admin/pages/edit/' . $post->id : '/kb-admin/posts/edit/' . $post->id;
			$postsHtml .= '<tr><td><a href="' . $editUrl . '" style="font-weight:700;color:var(--kb-text);text-decoration:none;">' . $e($post->title ?: '(no title)') . '</a></td><td>' . $e($post->type) . '</td><td><span class="kb-badge kb-badge-' . $statusClass . '">' . $e(ucfirst($post->status)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($post->created_at) . '</td></tr>';
		}

		$content = <<<HTML
		<div class="kb-stats">
			<div class="kb-stat"><div class="kb-stat-label">Published</div><div class="kb-stat-value">{$publishedCount}</div></div>
			<div class="kb-stat"><div class="kb-stat-label">Drafts</div><div class="kb-stat-value">{$draftCount}</div></div>
			<div class="kb-stat"><div class="kb-stat-label">Comments</div><div class="kb-stat-value">{$commentCount}</div></div>
			<div class="kb-stat"><div class="kb-stat-label">Users</div><div class="kb-stat-value">{$userCount}</div></div>
		</div>
		<div class="kb-card kb-mb-lg">
			<div class="kb-card-header"><h3>Recent content</h3><a href="/kb-admin/posts">View all posts</a></div>
			<table class="kb-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Date</th></tr></thead><tbody>{$postsHtml}</tbody></table>
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
					<div style="display:flex;justify-content:space-between;padding:7px 0;color:var(--kb-text-secondary);"><span>Page cache</span><span style="color:var(--kb-success);">Active</span></div>
				</div>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}

	// ---------------------------------------------------------------
	// Posts list
	// ---------------------------------------------------------------

	private function postsList(AdminLayout $layout): string
	{
		$layout->setTitle('Posts');
		$layout->setActivePage('posts');

		$db = $this->app->db();
		$filter = $this->request->query('status', '');
		$query = $db->table('posts')->where('type', '=', 'post');
		if ($filter !== '' && in_array($filter, ['published', 'draft', 'trash'])) {
			$query->where('status', '=', $filter);
		}
		$posts = $query->orderBy('created_at', 'DESC')->get();

		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		// Status filter tabs
		$allCount = $db->table('posts')->where('type', '=', 'post')->count();
		$pubCount = $db->table('posts')->where('type', '=', 'post')->where('status', '=', 'published')->count();
		$dftCount = $db->table('posts')->where('type', '=', 'post')->where('status', '=', 'draft')->count();
		$trsCount = $db->table('posts')->where('type', '=', 'post')->where('status', '=', 'trash')->count();

		$tabs = '<div style="display:flex;gap:16px;margin-bottom:16px;font-size:13px;">';
		$tabs .= '<a href="/kb-admin/posts" style="' . ($filter === '' ? 'font-weight:700;color:var(--kb-text);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">All (' . $allCount . ')</a>';
		$tabs .= '<a href="/kb-admin/posts?status=published" style="' . ($filter === 'published' ? 'font-weight:700;color:var(--kb-text);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">Published (' . $pubCount . ')</a>';
		$tabs .= '<a href="/kb-admin/posts?status=draft" style="' . ($filter === 'draft' ? 'font-weight:700;color:var(--kb-text);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">Drafts (' . $dftCount . ')</a>';
		if ($trsCount > 0) {
			$tabs .= '<a href="/kb-admin/posts?status=trash" style="' . ($filter === 'trash' ? 'font-weight:700;color:var(--kb-danger);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">Trash (' . $trsCount . ')</a>';
		}
		$tabs .= '</div>';

		$rows = '';
		foreach ($posts as $post) {
			$actions = '<a href="/kb-admin/posts/edit/' . $post->id . '">Edit</a>';
			if ($post->status === 'published') {
				$actions .= ' <a href="/' . $e($post->slug) . '" target="_blank">View</a>';
			}
			if ($post->status === 'trash') {
				$actions .= ' <a href="/kb-admin/posts/restore/' . $post->id . '">Restore</a>';
				$actions .= ' <a href="/kb-admin/posts/delete/' . $post->id . '" class="delete" onclick="return confirm(\'Permanently delete?\')">Delete</a>';
			} else {
				$actions .= ' <a href="/kb-admin/posts/trash/' . $post->id . '" class="delete">Trash</a>';
			}
			$rows .= '<tr><td><a href="/kb-admin/posts/edit/' . $post->id . '" style="font-weight:700;color:var(--kb-text);text-decoration:none;">' . $e($post->title ?: '(no title)') . '</a></td><td><span class="kb-badge kb-badge-' . $post->status . '">' . $e(ucfirst($post->status)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($post->created_at) . '</td><td class="kb-table-actions">' . $actions . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No posts found</h3><p>Create your first post to get started.</p><a href="/kb-admin/posts/new" class="kb-btn kb-btn-primary">+ New post</a></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>All posts</h2><a href="/kb-admin/posts/new" class="kb-btn kb-btn-primary">+ New post</a></div>
		{$tabs}
		<div class="kb-card">
			<table class="kb-table"><thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>{$rows}</tbody></table>
		</div>
HTML;

		return $layout->render($content);
	}

	// ---------------------------------------------------------------
	// Pages list
	// ---------------------------------------------------------------

	private function pagesList(AdminLayout $layout): string
	{
		$layout->setTitle('Pages');
		$layout->setActivePage('pages');

		$db = $this->app->db();
		$pages = $db->table('posts')->where('type', '=', 'page')->orderBy('created_at', 'DESC')->get();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($pages as $page) {
			$actions = '<a href="/kb-admin/pages/edit/' . $page->id . '">Edit</a>';
			if ($page->status === 'published') {
				$actions .= ' <a href="/' . $e($page->slug) . '" target="_blank">View</a>';
			}
			if ($page->status === 'trash') {
				$actions .= ' <a href="/kb-admin/pages/restore/' . $page->id . '">Restore</a>';
				$actions .= ' <a href="/kb-admin/pages/delete/' . $page->id . '" class="delete" onclick="return confirm(\'Permanently delete?\')">Delete</a>';
			} else {
				$actions .= ' <a href="/kb-admin/pages/trash/' . $page->id . '" class="delete">Trash</a>';
			}
			$rows .= '<tr><td><a href="/kb-admin/pages/edit/' . $page->id . '" style="font-weight:700;color:var(--kb-text);text-decoration:none;">' . $e($page->title ?: '(no title)') . '</a></td><td><span class="kb-badge kb-badge-' . $page->status . '">' . $e(ucfirst($page->status)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($page->created_at) . '</td><td class="kb-table-actions">' . $actions . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No pages yet</h3><p>Create your first page.</p><a href="/kb-admin/pages/new" class="kb-btn kb-btn-primary">+ New page</a></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>All pages</h2><a href="/kb-admin/pages/new" class="kb-btn kb-btn-primary">+ New page</a></div>
		<div class="kb-card">
			<table class="kb-table"><thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>{$rows}</tbody></table>
		</div>
HTML;

		return $layout->render($content);
	}

	// ---------------------------------------------------------------
	// Post/Page editor (enhanced)
	// ---------------------------------------------------------------

	private function postEditor(AdminLayout $layout, ?int $id = null): string
	{
		$type = str_contains($this->request->path(), '/pages') ? 'page' : 'post';
		$layout->setTitle($id ? 'Edit ' . $type : 'New ' . $type);
		$layout->setActivePage($type === 'page' ? 'pages' : 'posts');

		$db = $this->app->db();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$posts = new \Kreblu\Core\Content\PostManager($db);
		$taxonomy = new \Kreblu\Core\Content\TaxonomyManager($db);

		// Handle save
		if ($this->request->method() === 'POST') {
			$featuredInput = $this->request->input('featured_image', '');
			$data = [
				'title'          => $this->request->input('title', ''),
				'body'           => $this->request->input('body', ''),
				'status'         => $this->request->input('status', 'draft'),
				'type'           => $this->request->input('type', $type),
				'author_id'      => $this->app->auth()->currentUserId(),
				'featured_image' => $featuredInput !== '' ? (int) $featuredInput : null,
			];

			$slugInput = $this->request->input('slug', '');
			if ($slugInput !== '') {
				$data['slug'] = $slugInput;
			}

			if ($id) {
				$posts->update($id, $data);
				// Handle categories
				$catIds = array_map('intval', $this->request->input('categories', []));
				$taxonomy->setPostTerms($id, 'category', $catIds);
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
				$layout->addNotice('success', ucfirst($type) . ' updated successfully.');
			} else {
				$id = $posts->create($data);
				// Handle categories for new post
				$catIds = array_map('intval', $this->request->input('categories', []));
				if (!empty($catIds)) {
					$taxonomy->setPostTerms($id, 'category', $catIds);
				}
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
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
		$featuredImageId = $post->featured_image ?? null;
		$listUrl = $type === 'page' ? '/kb-admin/pages' : '/kb-admin/posts';
		$actionUrl = $id ? "/kb-admin/{$type}s/edit/{$id}" : "/kb-admin/{$type}s/new";

		// Categories (only for posts)
		$categoriesHtml = '';
		if ($type === 'post') {
			$allCategories = $taxonomy->getTerms('category');
			$assignedIds = [];
			if ($id) {
				$assigned = $taxonomy->getPostTerms($id, 'category');
				$assignedIds = array_map(fn(object $t) => (int) $t->id, $assigned);
			}

			$catItems = '';
			foreach ($allCategories as $cat) {
				$checked = in_array((int) $cat->id, $assignedIds) ? ' checked' : '';
				$catItems .= '<div class="kb-checkbox-group"><input type="checkbox" name="categories[]" value="' . (int) $cat->id . '" id="cat-' . (int) $cat->id . '"' . $checked . '><label for="cat-' . (int) $cat->id . '">' . $e($cat->name) . '</label></div>';
			}

			if ($catItems === '') {
				$catItems = '<p style="font-size:12px;color:var(--kb-text-hint);">No categories yet. Create one in Categories.</p>';
			}

			$categoriesHtml = <<<CATS
			<div class="kb-card">
				<div class="kb-card-header"><h3>Categories</h3></div>
				<div class="kb-card-body" style="max-height:180px;overflow-y:auto;">{$catItems}</div>
			</div>
CATS;
		}

		// Featured image
		$featuredImageHtml = '';
		$featuredPreviewHtml = '';
		$featuredBtnStyle = '';
		if ($featuredImageId) {
			try {
				$mediaManager = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
				$mediaItem = $mediaManager->findById((int) $featuredImageId);
				if ($mediaItem && str_starts_with($mediaItem->mime_type, 'image/')) {
					$imgUrl = '/' . $mediaManager->getUrl($mediaItem->filepath);
					$featuredPreviewHtml = '<div id="kb-featured-preview" style="margin-bottom:8px;"><img src="' . $e($imgUrl) . '" style="width:100%;border-radius:var(--kb-radius);"><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" style="margin-top:6px;" onclick="removeFeaturedImage()">Remove</button></div>';
					$featuredBtnStyle = ' style="display:none;"';
				}
			} catch (\Throwable) {}
		}
		$featuredImageHtml = '<div class="kb-card"><div class="kb-card-header"><h3>Featured image</h3></div><div class="kb-card-body"><div id="kb-featured-wrap">' . $featuredPreviewHtml . '<button type="button" class="kb-btn kb-btn-outline kb-btn-sm" id="kb-featured-btn"' . $featuredBtnStyle . ' onclick="selectFeaturedImage()">Set featured image</button></div><input type="hidden" name="featured_image" id="kb-featured-id" value="' . $e((string) ($featuredImageId ?? '')) . '"></div></div>';
		// Revisions (only for existing posts)
		$revisionsHtml = '';
		if ($id) {
			$revisions = $posts->getRevisions($id);
			if (!empty($revisions)) {
				$revItems = '';
				$count = min(count($revisions), 5);
				for ($i = 0; $i < $count; $i++) {
					$rev = $revisions[$i];
					$revItems .= '<div style="padding:6px 0;border-bottom:1px solid var(--kb-border);font-size:12px;">';
					$revItems .= '<span style="color:var(--kb-text);">' . $e($rev->title) . '</span>';
					$revItems .= '<span style="color:var(--kb-text-hint);display:block;">' . $e($rev->created_at) . '</span>';
					$revItems .= '</div>';
				}
				$revisionsHtml = '<div class="kb-card"><div class="kb-card-header"><h3>Revisions (' . count($revisions) . ')</h3></div><div class="kb-card-body">' . $revItems . '</div></div>';
			}
		}

		// Delete/trash buttons (only for existing posts)
		$deleteHtml = '';
		if ($id && $post) {
			if ($post->status === 'trash') {
				$deleteHtml = '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--kb-border);">';
				$deleteHtml .= '<a href="/kb-admin/' . $type . 's/restore/' . $id . '" class="kb-btn kb-btn-outline kb-btn-sm" style="margin-right:6px;">Restore</a>';
				$deleteHtml .= '<a href="/kb-admin/' . $type . 's/delete/' . $id . '" class="kb-btn kb-btn-danger kb-btn-sm" id="kb-delete-btn" data-delete-action="force" onclick="return confirm(\'Permanently delete? This cannot be undone.\')">Delete permanently</a>';
				$deleteHtml .= '</div>';
			} else {
				$deleteHtml = '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--kb-border);">';
				$deleteHtml .= '<a href="/kb-admin/' . $type . 's/trash/' . $id . '" class="kb-btn kb-btn-danger kb-btn-sm" id="kb-delete-btn" data-delete-action="trash" onclick="return confirm(\'Move to trash?\')">Move to trash</a>';
				$deleteHtml .= '</div>';
			}
		}

		// Slug field
		$slugFieldHtml = '';
		if ($id) {
			$slugFieldHtml = '<div class="kb-form-group"><label class="kb-label">Slug</label><div style="display:flex;align-items:center;gap:6px;"><span style="font-size:12px;color:var(--kb-text-hint);">/' . '</span><input type="text" name="slug" id="kb-editor-slug" class="kb-input" value="' . $e($slug) . '" style="font-size:12px;"></div></div>';
		} else {
			$slugFieldHtml = '<div class="kb-form-group"><label class="kb-label">Slug</label><div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--kb-text-hint);">/<span id="kb-slug-display">...</span></div><input type="hidden" name="slug" id="kb-editor-slug" value=""></div>';
		}

		// Word count


		// Toolbar HTML
		$toolbar = <<<TB
		<div id="kb-editor-toolbar" style="display:flex;flex-wrap:wrap;gap:2px;padding:8px 12px;border-bottom:1px solid var(--kb-border);background:var(--kb-bg-secondary);border-radius:var(--kb-radius) var(--kb-radius) 0 0;">
			<button type="button" data-action="bold" class="kb-toolbar-btn" title="Bold (Ctrl+B)"><strong>B</strong></button>
			<button type="button" data-action="italic" class="kb-toolbar-btn" title="Italic (Ctrl+I)"><em>I</em></button>
			<span style="width:1px;background:var(--kb-border);margin:2px 4px;"></span>
			<button type="button" data-action="h2" class="kb-toolbar-btn" title="Heading 2">H2</button>
			<button type="button" data-action="h3" class="kb-toolbar-btn" title="Heading 3">H3</button>
			<button type="button" data-action="p" class="kb-toolbar-btn" title="Paragraph">P</button>
			<span style="width:1px;background:var(--kb-border);margin:2px 4px;"></span>
			<button type="button" data-action="link" class="kb-toolbar-btn" title="Link (Ctrl+K)">Link</button>
			<button type="button" data-action="img" class="kb-toolbar-btn" title="Image">Img</button>
			<span style="width:1px;background:var(--kb-border);margin:2px 4px;"></span>
			<button type="button" data-action="ul" class="kb-toolbar-btn" title="Unordered list">UL</button>
			<button type="button" data-action="ol" class="kb-toolbar-btn" title="Ordered list">OL</button>
			<button type="button" data-action="blockquote" class="kb-toolbar-btn" title="Blockquote">Quote</button>
			<button type="button" data-action="code" class="kb-toolbar-btn" title="Inline code">Code</button>
			<button type="button" data-action="codeblock" class="kb-toolbar-btn" title="Code block">Pre</button>
			<button type="button" data-action="hr" class="kb-toolbar-btn" title="Horizontal rule">HR</button>
		</div>
TB;

		$content = <<<HTML
		<div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
			<a href="{$listUrl}" style="font-size:12px;color:var(--kb-text-secondary);">&larr; Back to {$type}s</a>
			<span id="kb-save-indicator" style="font-size:11px;color:var(--kb-text-hint);"></span>
		</div>
		<div id="kb-inline-panel" style="display:none;padding:10px 14px;margin-bottom:12px;border:1px solid var(--kb-border);border-radius:var(--kb-radius);background:var(--kb-card);"></div>
		<form method="POST" action="{$actionUrl}" id="kb-editor-form">
			<input type="hidden" name="type" value="{$type}">
			<div class="kb-grid-2">
				<div class="kb-stack">
					<div class="kb-card" style="overflow:hidden;">
						<div class="kb-card-body" style="padding:0;">
							<input type="text" name="title" id="kb-editor-title" value="{$e($title)}" placeholder="Title..." style="font-size:18px;font-weight:700;padding:16px 18px;border:none;background:transparent;width:100%;color:var(--kb-text);font-family:inherit;border-bottom:1px solid var(--kb-border);">
							{$toolbar}
							<textarea name="body" id="kb-editor-body" class="kb-textarea" rows="20" placeholder="Write your content here..." style="font-size:14px;line-height:1.7;border:none;border-radius:0;padding:16px 18px;min-height:400px;">{$e($body)}</textarea>
						</div>
					</div>
					<div id="kb-word-count" style="font-size:11px;color:var(--kb-text-hint);"></div>
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
							{$slugFieldHtml}
							<div style="display:flex;gap:8px;margin-top:12px;">
								<button type="submit" class="kb-btn kb-btn-primary">Save</button>
								<a href="{$listUrl}" class="kb-btn kb-btn-outline">Cancel</a>
							</div>
							{$deleteHtml}
						</div>
					</div>
					{$featuredImageHtml}
					{$categoriesHtml}
					{$revisionsHtml}
				</div>
			</div>
		</form>
		<style>.kb-toolbar-btn{background:none;border:1px solid transparent;border-radius:3px;padding:4px 8px;font-size:12px;color:var(--kb-text-secondary);cursor:pointer;font-family:inherit;line-height:1;}.kb-toolbar-btn:hover{background:var(--kb-card);border-color:var(--kb-border);color:var(--kb-text);}.kb-panel-form{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}.kb-panel-form .kb-input-sm{padding:5px 8px;font-size:12px;max-width:220px;}</style>
		<script src="/kb-admin/assets/js/media.js"></script>
		<script src="/kb-admin/assets/js/editor.js"></script>
		<script>
		function selectFeaturedImage() {
			KBMediaSelector.open(function(item) {
				document.getElementById('kb-featured-id').value = item.id;
				var wrap = document.getElementById('kb-featured-wrap');
				var btn = document.getElementById('kb-featured-btn');
				var old = document.getElementById('kb-featured-preview');
				if (old) old.remove();
				var div = document.createElement('div');
				div.id = 'kb-featured-preview';
				div.style.marginBottom = '8px';
				div.innerHTML = '<img src="/' + item.url + '" style="width:100%;border-radius:var(--kb-radius);"><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" style="margin-top:6px;" onclick="removeFeaturedImage()">Remove</button>';
				wrap.insertBefore(div, btn);
				btn.style.display = 'none';
			}, 'image');
		}
		function removeFeaturedImage() {
			document.getElementById('kb-featured-id').value = '';
			var preview = document.getElementById('kb-featured-preview');
			if (preview) preview.remove();
			document.getElementById('kb-featured-btn').style.display = '';
		}
		</script>
HTML;

		return $layout->render($content);
	}

	private function pageEditor(AdminLayout $layout, ?int $id = null): string
	{
		return $this->postEditor($layout, $id);
	}

	// ---------------------------------------------------------------
	// Post actions: trash, restore, delete
	// ---------------------------------------------------------------

	private function postTrash(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->delete($id, false);

		// Clear page cache
		if ($this->app->has('cache')) {
			$this->app->cache()->clearPageCache();
		}

		header('Location: /kb-admin/' . $type . 's');
		exit;
	}

	private function postRestore(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->restore($id);
		header('Location: /kb-admin/' . $type . 's');
		exit;
	}

	private function postDelete(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->delete($id, true);

		if ($this->app->has('cache')) {
			$this->app->cache()->clearPageCache();
		}

		header('Location: /kb-admin/' . $type . 's');
		exit;
	}

	// ---------------------------------------------------------------
	// Comments
	// ---------------------------------------------------------------

	private function commentsList(AdminLayout $layout): string
	{
		$layout->setTitle('Comments');
		$layout->setActivePage('comments');

		$comments = $this->app->db()->table('comments')->orderBy('created_at', 'DESC')->limit(50)->get();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		$rows = '';
		foreach ($comments as $comment) {
			$rows .= '<tr><td>' . $e($comment->author_name ?? 'Anonymous') . '</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $e(mb_substr($comment->body, 0, 80)) . '</td><td><span class="kb-badge kb-badge-' . $comment->status . '">' . $e(ucfirst($comment->status)) . '</span></td><td style="color:var(--kb-text-hint);font-size:12px;">' . $e($comment->created_at) . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4"><div class="kb-empty"><h3>No comments yet</h3><p>Comments will appear here once visitors start engaging.</p></div></td></tr>';
		}

		$content = '<div class="kb-content-header"><h2>Comments</h2></div><div class="kb-card"><table class="kb-table"><thead><tr><th>Author</th><th>Comment</th><th>Status</th><th>Date</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		return $layout->render($content);
	}

	// ---------------------------------------------------------------
	// Categories
	// ---------------------------------------------------------------

	private function categoriesList(AdminLayout $layout): string
	{
		$layout->setTitle('Categories');
		$layout->setActivePage('categories');

		$db = $this->app->db();
		$taxonomy = new \Kreblu\Core\Content\TaxonomyManager($db);
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

		// Handle create
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

	// ---------------------------------------------------------------
	// Media — Phase 2.3
	// ---------------------------------------------------------------

	private function mediaList(AdminLayout $layout): string
	{
		$layout->setTitle('Media');
		$layout->setActivePage('media');

		// Handle AJAX endpoints via ?action= parameter
		$action = $this->request->query('action', '');
		if ($action !== '') {
			header('Content-Type: application/json');
			return match ($action) {
				'list'   => $this->mediaListJson(),
				'upload' => $this->mediaUploadJson(),
				'update' => $this->mediaUpdateJson(),
				'delete' => $this->mediaDeleteJson(),
				default  => json_encode(['error' => 'Unknown action']),
			};
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Media library</h2></div>

		<div class="kb-upload-zone" id="kb-upload-zone">
			<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" style="width:40px;height:40px;">
				<path d="M14 30l10-10 10 10"/><path d="M24 20v20"/><path d="M40 32v6a4 4 0 0 1-4 4H12a4 4 0 0 1-4-4v-6"/>
			</svg>
			<p><strong>Drop files here</strong> or click to browse</p>
			<p style="font-size:12px;margin-top:4px;">Max 32MB per file</p>
			<input type="file" id="kb-upload-input" multiple style="display:none;" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.mp3,.wav,.ogg,.mp4,.webm,.zip">
			<div class="kb-upload-progress" id="kb-upload-progress">
				<span class="kb-upload-text" id="kb-upload-text">Uploading...</span>
				<div class="kb-upload-bar-wrap"><div class="kb-upload-bar" id="kb-upload-bar"></div></div>
			</div>
		</div>

		<div class="kb-media-toolbar">
			<div class="kb-media-filters">
				<button data-filter="all" class="active">All <span class="kb-filter-count"></span></button>
				<button data-filter="image">Images <span class="kb-filter-count"></span></button>
				<button data-filter="video">Video <span class="kb-filter-count"></span></button>
				<button data-filter="audio">Audio <span class="kb-filter-count"></span></button>
				<button data-filter="application">Documents <span class="kb-filter-count"></span></button>
			</div>
			<input type="text" class="kb-input" id="kb-media-search" placeholder="Search media..." style="max-width:220px;">
			<div class="kb-view-toggle">
				<button data-view="grid" title="Grid view"><svg viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg></button>
				<button data-view="list" title="List view"><svg viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="2" width="14" height="2" rx="1"/><rect x="1" y="7" width="14" height="2" rx="1"/><rect x="1" y="12" width="14" height="2" rx="1"/></svg></button>
			</div>
		</div>

		<div class="kb-media-grid" id="kb-media-grid"><div class="kb-media-loading">Loading...</div></div>
		<div id="kb-media-pagination"></div>
		<div class="kb-media-detail" id="kb-media-detail"></div>

		<script src="/kb-admin/assets/js/media.js"></script>
HTML;

		return $layout->render($content);
	}

	private function mediaListJson(): string
	{
		$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);

		$type = $this->request->query('type', 'all');
		$search = $this->request->query('search', '');
		$page = max(1, (int) $this->request->query('page', '1'));
		$perPage = min(100, max(1, (int) $this->request->query('per_page', '40')));
		$offset = ($page - 1) * $perPage;

		$args = ['limit' => $perPage, 'offset' => $offset];
		if ($type !== 'all' && $type !== '') {
			$args['mime_type'] = $type;
		}
		if ($search !== '') {
			$args['search'] = $search;
		}

		$items = $media->list($args);
		$mimeFilter = ($type !== 'all' && $type !== '') ? $type . '/' : null;
		$total = $media->count($mimeFilter);

		$counts = [
			'all'         => $media->count(),
			'image'       => $media->count('image/'),
			'video'       => $media->count('video/'),
			'audio'       => $media->count('audio/'),
			'application' => $media->count('application/'),
		];

		$formatted = [];
		foreach ($items as $item) {
			$obj = [
				'id'         => (int) $item->id,
				'filename'   => $item->filename,
				'mime_type'  => $item->mime_type,
				'file_size'  => (int) $item->file_size,
				'width'      => $item->width ? (int) $item->width : null,
				'height'     => $item->height ? (int) $item->height : null,
				'alt_text'   => $item->alt_text ?? '',
				'title'      => $item->title ?? '',
				'caption'    => $item->caption ?? '',
				'url'        => $media->getUrl($item->filepath),
				'created_at' => $item->created_at ?? '',
			];
			if ($item->meta) {
				$meta = json_decode($item->meta, true);
				if (isset($meta['sizes']['thumbnail']['path'])) {
					$obj['thumb_url'] = $media->getUrl($meta['sizes']['thumbnail']['path']);
				}
			}
			$formatted[] = $obj;
		}

		return json_encode(['items' => $formatted, 'total' => $total, 'counts' => $counts]);
	}

	private function mediaUploadJson(): string
	{
		if ($this->request->method() !== 'POST') {
			return json_encode(['error' => 'Method not allowed']);
		}
		if (!isset($_FILES['file'])) {
			return json_encode(['error' => 'No file uploaded']);
		}
		$user = $this->app->auth()->currentUser();
		if (!$user) {
			return json_encode(['error' => 'Not authenticated']);
		}
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$id = $media->upload($_FILES['file'], (int) $user->id);
			return json_encode(['success' => true, 'id' => $id]);
		} catch (\Throwable $ex) {
			return json_encode(['error' => $ex->getMessage()]);
		}
	}

	private function mediaUpdateJson(): string
	{
		if ($this->request->method() !== 'POST') {
			return json_encode(['error' => 'Method not allowed']);
		}
		$input = json_decode(file_get_contents('php://input'), true);
		if (!$input || !isset($input['id'])) {
			return json_encode(['error' => 'Invalid request']);
		}
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$media->update((int) $input['id'], [
				'title'    => $input['title'] ?? '',
				'alt_text' => $input['alt_text'] ?? '',
				'caption'  => $input['caption'] ?? '',
			]);
			return json_encode(['success' => true]);
		} catch (\Throwable $ex) {
			return json_encode(['error' => $ex->getMessage()]);
		}
	}

	private function mediaDeleteJson(): string
	{
		if ($this->request->method() !== 'POST') {
			return json_encode(['error' => 'Method not allowed']);
		}
		$input = json_decode(file_get_contents('php://input'), true);
		if (!$input || !isset($input['id'])) {
			return json_encode(['error' => 'Invalid request']);
		}
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$result = $media->delete((int) $input['id']);
			return json_encode(['success' => $result]);
		} catch (\Throwable $ex) {
			return json_encode(['error' => $ex->getMessage()]);
		}
	}

	// ---------------------------------------------------------------
	// Users, Settings (unchanged)
	// ---------------------------------------------------------------

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
		$content = '<div class="kb-content-header"><h2>Users</h2></div><div class="kb-card"><table class="kb-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		return $layout->render($content);
	}

	private function settings(AdminLayout $layout): string
	{
		$layout->setTitle('Settings');
		$layout->setActivePage('settings');
		$db = $this->app->db();
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

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
			if ($this->app->has('cache')) {
				$this->app->cache()->clearPageCache();
			}
			$layout->addNotice('success', 'Settings saved.');
		}

		$opts = [];
		$rows = $db->table('options')->where('autoload', '=', 1)->get();
		foreach ($rows as $row) { $opts[$row->option_key] = $row->option_value; }

		$content = '<div class="kb-content-header"><h2>General settings</h2></div><form method="POST" action="/kb-admin/settings"><div class="kb-card kb-mb-lg"><div class="kb-card-body"><div class="kb-form-group"><label class="kb-label">Site name</label><input type="text" name="site_name" class="kb-input" value="' . $e($opts['site_name'] ?? '') . '"></div><div class="kb-form-group"><label class="kb-label">Site description</label><input type="text" name="site_description" class="kb-input" value="' . $e($opts['site_description'] ?? '') . '"></div><div class="kb-form-group"><label class="kb-label">Posts per page</label><input type="number" name="posts_per_page" class="kb-input" value="' . $e($opts['posts_per_page'] ?? '10') . '" style="max-width:120px;"></div><div class="kb-form-group"><label class="kb-label">Date format</label><input type="text" name="date_format" class="kb-input" value="' . $e($opts['date_format'] ?? 'F j, Y') . '" style="max-width:200px;"></div><button type="submit" class="kb-btn kb-btn-primary">Save settings</button></div></div></form>';
		return $layout->render($content);
	}

	// ---------------------------------------------------------------
	// Utility pages
	// ---------------------------------------------------------------

	private function comingSoon(AdminLayout $layout, string $title, string $page): string
	{
		$layout->setTitle($title);
		$layout->setActivePage($page);
		return $layout->render('<div class="kb-card"><div class="kb-card-body"><div class="kb-empty"><h3>' . $title . '</h3><p>' . $title . ' management is coming soon.</p></div></div></div>');
	}

	private function notFound(AdminLayout $layout): string
	{
		$layout->setTitle('Not Found');
		return $layout->render('<div class="kb-card"><div class="kb-card-body"><div class="kb-empty"><h3>Page not found</h3><p>The admin page you requested doesn\'t exist.</p><a href="/kb-admin/" class="kb-btn kb-btn-primary">Go to dashboard</a></div></div></div>');
	}

	private function logout(): string
	{
		if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
}
