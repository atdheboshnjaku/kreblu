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

	private function wysiwygContent(string $html): string
	{
		return "'" . str_replace(["\\", "'", "\n", "\r", "</"], ["\\\\", "\\'", "\\n", "\\r", "<\\/"], $html) . "'";
	}

	/**
	 * REPLACE the editor() method in PostController.php with this.
	 */
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
			if ($slugInput !== '') { $data['slug'] = $slugInput; }

			if ($id) {
				$posts->update($id, $data);
				$catIds = array_map('intval', $this->request->input('categories', []));
				$taxonomy->setPostTerms($id, 'category', $catIds);
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
				$layout->addNotice('success', ucfirst($type) . ' updated.');
			} else {
				$id = $posts->create($data);
				$catIds = array_map('intval', $this->request->input('categories', []));
				if (!empty($catIds)) { $taxonomy->setPostTerms($id, 'category', $catIds); }
				if ($this->app->has('cache')) { $this->app->cache()->clearPageCache(); }
				header('Location: /kb-admin/' . $type . 's/edit/' . $id);
				exit;
			}
		}

		$post = null;
		if ($id) { $post = $db->table('posts')->where('id', '=', $id)->first(); }

		$title = $post->title ?? '';
		$body = $post->body ?? '';
		$status = $post->status ?? 'draft';
		$slug = $post->slug ?? '';
		$featuredImageId = $post->featured_image ?? null;
		$listUrl = $type === 'page' ? '/kb-admin/pages' : '/kb-admin/posts';
		$actionUrl = $id ? "/kb-admin/{$type}s/edit/{$id}" : "/kb-admin/{$type}s/new";
		$isNew = $id === null;

		// Status
		$statusDraft = $this->selected($status, 'draft');
		$statusPub = $this->selected($status, 'published');

		// Categories
		$catHtml = '';
		if ($type === 'post') {
			$allCats = $taxonomy->getTerms('category');
			$assignedIds = $id ? array_map(fn($t) => (int) $t->id, $taxonomy->getPostTerms($id, 'category')) : [];
			foreach ($allCats as $cat) {
				$chk = in_array((int) $cat->id, $assignedIds) ? ' checked' : '';
				$catHtml .= '<label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:13px;cursor:pointer;"><input type="checkbox" name="categories[]" value="' . (int) $cat->id . '"' . $chk . ' style="accent-color:var(--kb-rust);width:14px;height:14px;"> ' . $e($cat->name) . '</label>';
			}
			if (!$catHtml) $catHtml = '<span style="font-size:12px;color:var(--kb-text-hint);">No categories yet.</span>';
		}

		// Featured image
		$featPreview = '';
		$featBtnVis = '';
		if ($featuredImageId) {
			try {
				$mm = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
				$mi = $mm->findById((int) $featuredImageId);
				if ($mi && str_starts_with($mi->mime_type, 'image/')) {
					$featPreview = '<div id="kb-feat-preview" style="margin-top:8px;"><img src="/' . $e($mm->getUrl($mi->filepath)) . '" style="max-height:120px;border-radius:var(--kb-radius);"><br><button type="button" class="kbe-link-btn" onclick="removeFeaturedImage()" style="margin-top:4px;">Remove</button></div>';
					$featBtnVis = ' style="display:none;"';
				}
			} catch (\Throwable) {}
		}

		// Trash/restore/delete buttons
		$trashHtml = '';
		if ($id && $post && $post->status !== 'trash') {
			$trashHtml = '<a href="/kb-admin/' . $type . 's/trash/' . $id . '" class="kbe-trash-btn" onclick="return confirm(\'Move to trash?\')"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 4h10M5 4V3h6v1M6 7v4M10 7v4M4 4l1 9h6l1-9"/></svg> Trash</a>';
		} elseif ($id && $post && $post->status === 'trash') {
			$trashHtml = '<a href="/kb-admin/' . $type . 's/restore/' . $id . '" class="kb-btn kb-btn-outline kb-btn-sm" style="font-size:12px;">Restore</a>'
				. '<a href="/kb-admin/' . $type . 's/delete/' . $id . '" class="kbe-trash-btn" onclick="return confirm(\'Delete permanently?\')"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 4h10M5 4V3h6v1M6 7v4M10 7v4M4 4l1 9h6l1-9"/></svg> Delete</a>';
		}

		$catSection = $type === 'post' ? '<div class="kbe-drawer-section"><div class="kbe-drawer-label">Categories</div><div style="max-height:140px;overflow-y:auto;">' . $catHtml . '</div></div>' : '';
		$bodyJson = $this->wysiwygContent($body);

		$content = <<<HTML
		<form method="POST" action="{$actionUrl}" id="kb-editor-form">
			<input type="hidden" name="type" value="{$type}">
			<input type="hidden" name="body" id="kb-editor-body-hidden">
			<input type="hidden" name="featured_image" id="kb-feat-id" value="{$e((string) ($featuredImageId ?? ''))}">

			<div class="kbe-writing">
				<input type="text" name="title" id="kb-editor-title" value="{$e($title)}" placeholder="Title" class="kbe-title">
				<div class="kbe-slug-row" id="kb-slug-row">
					<span>/</span>
					<input type="text" name="slug" id="kb-editor-slug" value="{$e($slug)}" class="kbe-slug-input" placeholder="auto-generated">
				</div>
				<div id="kb-editor-wysiwyg"></div>
			</div>

			<!-- Settings drawer -->
			<div class="kbe-drawer" id="kbe-drawer">
				<div class="kbe-drawer-hd">
					<span>Settings</span>
					<button type="button" class="kbe-drawer-close" onclick="document.getElementById('kbe-drawer').classList.remove('open')">&times;</button>
				</div>
				<div class="kbe-drawer-bd">
					<div class="kbe-drawer-section">
						<div class="kbe-drawer-label">Featured image</div>
						<div id="kb-feat-wrap">
							{$featPreview}
							<button type="button" class="kbe-link-btn" id="kb-feat-btn"{$featBtnVis} onclick="selectFeaturedImage()">Set featured image</button>
						</div>
					</div>
					{$catSection}
				</div>
			</div>
		</form>

		<script src="/kb-admin/assets/js/media.js"></script>
		<script src="/kb-admin/assets/js/kb-editor.js"></script>
		<script>
		(() => {
			// === Replace topbar content with editor controls ===
			const topbar = document.querySelector('.kb-topbar');
			if (topbar) {
				topbar.innerHTML = `
					<div class="kbe-topbar">
						<a href="{$listUrl}" class="kbe-topbar-back">&larr; {$e(ucfirst($type))}s</a>
						<div class="kbe-topbar-center">
							<span id="kb-save-ind" style="font-size:11px;color:var(--kb-text-hint);"></span>
							<span id="kb-word-count" style="font-size:11px;color:var(--kb-text-hint);"></span>
						</div>
						<div class="kbe-topbar-actions">
							{$trashHtml}
							<select name="status" form="kb-editor-form" class="kb-select" style="font-size:12px;padding:5px 28px 5px 8px;min-width:0;">
								<option value="draft" {$statusDraft}>Draft</option>
								<option value="published" {$statusPub}>Published</option>
							</select>
							<button type="submit" form="kb-editor-form" class="kb-btn kb-btn-primary kb-btn-sm">Save</button>
							<button type="button" class="kbe-settings-btn" onclick="document.getElementById('kbe-drawer').classList.toggle('open')" title="Settings">⚙</button>
						</div>
					</div>`;
			}

			// === Init editor ===
			const hiddenBody = document.getElementById('kb-editor-body-hidden');
			const wordEl = document.getElementById('kb-word-count');
			let dirty = false;

			const editor = new KBEditor('#kb-editor-wysiwyg', {
				placeholder: 'Start writing... type / for commands',
				content: {$bodyJson},
				onChange: (html) => {
					hiddenBody.value = html;
					dirty = true;
					const ind = document.getElementById('kb-save-ind');
					if (ind) { ind.textContent = 'Unsaved'; ind.style.color = 'var(--kb-warning)'; }
					if (wordEl) { const c = editor.getWordCount(); wordEl.textContent = c + ' word' + (c !== 1 ? 's' : ''); }
				},
			});

			hiddenBody.value = editor.getHTML();
			if (wordEl) { const c = editor.getWordCount(); wordEl.textContent = c + ' word' + (c !== 1 ? 's' : ''); }

			// Form submit
			document.getElementById('kb-editor-form')?.addEventListener('submit', () => { hiddenBody.value = editor.getHTML(); dirty = false; });
			window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
			document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); document.getElementById('kb-editor-form')?.submit(); } });

			// === Slug logic ===
			const titleField = document.getElementById('kb-editor-title');
			const slugField = document.getElementById('kb-editor-slug');
			let slugManual = slugField.value !== '';
			const slugify = (t) => t.toLowerCase().trim().replace(/[^\w\s-]/g, '').replace(/[\s_]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');

			titleField?.addEventListener('input', () => {
				if (slugManual) return;
				slugField.value = slugify(titleField.value);
			});

			slugField?.addEventListener('input', () => { slugManual = true; });
			slugField?.addEventListener('blur', () => {
				if (slugField.value.trim() === '') {
					slugManual = false;
					slugField.value = slugify(titleField?.value ?? '');
				}
			});

			// === Featured image ===
			window.selectFeaturedImage = () => {
				KBMediaSelector.open((item) => {
					document.getElementById('kb-feat-id').value = item.id;
					const wrap = document.getElementById('kb-feat-wrap');
					const btn = document.getElementById('kb-feat-btn');
					document.getElementById('kb-feat-preview')?.remove();
					const div = document.createElement('div');
					div.id = 'kb-feat-preview';
					div.style.marginTop = '8px';
					div.innerHTML = '<img src="/' + item.url + '" style="max-height:120px;border-radius:var(--kb-radius);"><br><button type="button" class="kbe-link-btn" onclick="removeFeaturedImage()" style="margin-top:4px;">Remove</button>';
					wrap.insertBefore(div, btn);
					btn.style.display = 'none';
				}, 'image');
			};
			window.removeFeaturedImage = () => {
				document.getElementById('kb-feat-id').value = '';
				document.getElementById('kb-feat-preview')?.remove();
				document.getElementById('kb-feat-btn').style.display = '';
			};
		})();
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
