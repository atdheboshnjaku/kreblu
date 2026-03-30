<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class DashboardController extends BaseController
{
	public function index(AdminLayout $layout): string
	{
		$layout->setTitle('Dashboard');
		$layout->setActivePage('dashboard');

		$db = $this->app->db();
		$publishedCount = $db->table('posts')->where('status', '=', 'published')->count();
		$draftCount = $db->table('posts')->where('status', '=', 'draft')->count();
		$commentCount = $db->table('comments')->count();
		$userCount = $db->table('users')->count();

		$recentPosts = $db->table('posts')->orderBy('created_at', 'DESC')->limit(5)->get();

		$e = fn(string $s): string => $this->e($s);

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
}
