<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class PostController extends BaseController
{
	public function list(AdminLayout $layout, string $type): string
	{
		$ucType = ucfirst($type);
		$prefix = $type === 'page' ? 'pages' : 'posts';
		$layout->setTitle($ucType . 's');
		$layout->setActivePage($prefix);

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);

		if ($type === 'post') {
			return $this->postsListView($layout, $db, $e);
		}
		return $this->pagesListView($layout, $db, $e);
	}

	private function postsListView(AdminLayout $layout, $db, \Closure $e): string
	{
		$filter = $this->request->query('status', '');
		$query = $db->table('posts')->where('type', '=', 'post');
		if ($filter !== '' && in_array($filter, ['published', 'draft', 'trash'])) {
			$query->where('status', '=', $filter);
		}
		$posts = $query->orderBy('created_at', 'DESC')->get();

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

	private function pagesListView(AdminLayout $layout, $db, \Closure $e): string
	{
		$pages = $db->table('posts')->where('type', '=', 'page')->orderBy('created_at', 'DESC')->get();

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

	public function editor(AdminLayout $layout, string $type, ?int $id = null): string
	{
		$layout->setTitle($id ? 'Edit ' . $type : 'New ' . $type);
		$layout->setActivePage($type === 'page' ? 'pages' : 'posts');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);
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
				$catIds = array_map('intval', $this->request->input('categories', []));
				$taxonomy->setPostTerms($id, 'category', $catIds);
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
				$layout->addNotice('success', ucfirst($type) . ' updated successfully.');
			} else {
				$id = $posts->create($data);
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

		// Revisions
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

		// Delete/trash buttons
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

		// Toolbar
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

	public function trash(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->delete($id, false);
		if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
		header('Location: /kb-admin/' . $type . 's');
		exit;
	}

	public function restore(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->restore($id);
		header('Location: /kb-admin/' . $type . 's');
		exit;
	}

	public function delete(int $id, string $type): string
	{
		$posts = new \Kreblu\Core\Content\PostManager($this->app->db());
		$posts->delete($id, true);
		if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
		header('Location: /kb-admin/' . $type . 's');
		exit;
	}
}
