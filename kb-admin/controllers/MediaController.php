<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class MediaController extends BaseController
{
	public function index(AdminLayout $layout): string
	{
		$layout->setTitle('Media');
		$layout->setActivePage('media');

		$action = $this->request->query('action', '');
		if ($action !== '') {
			header('Content-Type: application/json');
			return match ($action) {
				'list'   => $this->listJson(),
				'upload' => $this->uploadJson(),
				'update' => $this->updateJson(),
				'delete' => $this->deleteJson(),
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

	private function listJson(): string
	{
		$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);

		$type = $this->request->query('type', 'all');
		$search = $this->request->query('search', '');
		$page = max(1, (int) $this->request->query('page', '1'));
		$perPage = min(100, max(1, (int) $this->request->query('per_page', '40')));
		$offset = ($page - 1) * $perPage;

		$args = ['limit' => $perPage, 'offset' => $offset];
		if ($type !== 'all' && $type !== '') { $args['mime_type'] = $type; }
		if ($search !== '') { $args['search'] = $search; }

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
				'id' => (int) $item->id, 'filename' => $item->filename, 'mime_type' => $item->mime_type,
				'file_size' => (int) $item->file_size, 'width' => $item->width ? (int) $item->width : null,
				'height' => $item->height ? (int) $item->height : null, 'alt_text' => $item->alt_text ?? '',
				'title' => $item->title ?? '', 'caption' => $item->caption ?? '',
				'url' => $media->getUrl($item->filepath), 'created_at' => $item->created_at ?? '',
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

	private function uploadJson(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		if (!isset($_FILES['file'])) return json_encode(['error' => 'No file uploaded']);
		$user = $this->app->auth()->currentUser();
		if (!$user) return json_encode(['error' => 'Not authenticated']);
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$id = $media->upload($_FILES['file'], (int) $user->id);
			return json_encode(['success' => true, 'id' => $id]);
		} catch (\Throwable $ex) { return json_encode(['error' => $ex->getMessage()]); }
	}

	private function updateJson(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		$input = json_decode(file_get_contents('php://input'), true);
		if (!$input || !isset($input['id'])) return json_encode(['error' => 'Invalid request']);
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$media->update((int) $input['id'], ['title' => $input['title'] ?? '', 'alt_text' => $input['alt_text'] ?? '', 'caption' => $input['caption'] ?? '']);
			return json_encode(['success' => true]);
		} catch (\Throwable $ex) { return json_encode(['error' => $ex->getMessage()]); }
	}

	private function deleteJson(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		$input = json_decode(file_get_contents('php://input'), true);
		if (!$input || !isset($input['id'])) return json_encode(['error' => 'Invalid request']);
		try {
			$media = new \Kreblu\Core\Content\MediaManager($this->app->db(), KREBLU_ROOT);
			$result = $media->delete((int) $input['id']);
			return json_encode(['success' => $result]);
		} catch (\Throwable $ex) { return json_encode(['error' => $ex->getMessage()]); }
	}
}
