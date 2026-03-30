<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class CommentController extends BaseController
{
	public function list(AdminLayout $layout): string
	{
		$layout->setTitle('Comments');
		$layout->setActivePage('comments');

		$comments = $this->app->db()->table('comments')->orderBy('created_at', 'DESC')->limit(50)->get();
		$e = fn(string $s): string => $this->e($s);

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
}
