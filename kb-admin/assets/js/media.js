/**
 * K Hub Media Library
 *
 * Drag-and-drop uploads, grid/list view, type filters, search,
 * detail panel for editing metadata, delete confirmation,
 * and a reusable media selector modal for the content editor.
 *
 * ES2022+ · No dependencies · Vanilla JS
 */

class MediaLibrary {
	// Private state
	#filter = 'all';
	#search = '';
	#view = localStorage.getItem('kb_media_view') ?? 'grid';
	#page = 1;
	#perPage = 40;
	#total = 0;
	#selectedId = null;
	#uploading = false;
	#items = [];

	// DOM refs
	#zone = null;
	#grid = null;
	#panel = null;
	#pagination = null;

	constructor() {
		this.#zone = document.getElementById('kb-upload-zone');
		this.#grid = document.getElementById('kb-media-grid');
		this.#panel = document.getElementById('kb-media-detail');
		this.#pagination = document.getElementById('kb-media-pagination');

		if (!this.#grid) return;

		this.#initUploadZone();
		this.#initViewToggle();
		this.#initFilters();
		this.#initSearch();
		this.#loadMedia();

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') this.closeDetail();
		});
	}

	// =================================================================
	// Upload
	// =================================================================

	#initUploadZone() {
		if (!this.#zone) return;

		const input = document.getElementById('kb-upload-input');

		this.#zone.addEventListener('click', () => {
			if (!this.#uploading) input?.click();
		});

		input?.addEventListener('change', () => {
			if (input.files.length) this.#uploadFiles(input.files);
			input.value = '';
		});

		// Drag events on zone
		this.#zone.addEventListener('dragover', (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.#zone.classList.add('dragover');
		});

		this.#zone.addEventListener('dragleave', (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.#zone.classList.remove('dragover');
		});

		this.#zone.addEventListener('drop', (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.#zone.classList.remove('dragover');
			if (e.dataTransfer.files.length) this.#uploadFiles(e.dataTransfer.files);
		});

		// Full-page drop target
		document.body.addEventListener('dragover', (e) => {
			e.preventDefault();
			if (!this.#uploading) this.#zone.classList.add('dragover');
		});

		document.body.addEventListener('dragleave', (e) => {
			if (!e.relatedTarget || e.relatedTarget === document.documentElement) {
				this.#zone.classList.remove('dragover');
			}
		});

		document.body.addEventListener('drop', (e) => {
			e.preventDefault();
			this.#zone.classList.remove('dragover');
			if (e.dataTransfer.files.length && !this.#uploading) {
				this.#uploadFiles(e.dataTransfer.files);
			}
		});
	}

	async #uploadFiles(fileList) {
		const files = [...fileList];
		this.#uploading = true;
		const progressWrap = document.getElementById('kb-upload-progress');
		const progressBar = document.getElementById('kb-upload-bar');
		const progressText = document.getElementById('kb-upload-text');

		this.#zone.classList.add('uploading');
		progressWrap.style.display = 'block';

		const errors = [];
		const total = files.length;

		for (let i = 0; i < total; i++) {
			progressText.textContent = `Uploading ${i + 1} of ${total}...`;
			progressBar.style.width = `${Math.round((i / total) * 100)}%`;

			try {
				await this.#uploadSingleFile(files[i], (filePct) => {
					const overall = Math.round(((i + filePct / 100) / total) * 100);
					progressBar.style.width = `${overall}%`;
				});
			} catch (err) {
				errors.push(`${files[i].name}: ${err.message}`);
			}
		}

		this.#uploading = false;
		this.#zone.classList.remove('uploading');
		progressWrap.style.display = 'none';
		progressBar.style.width = '0%';

		if (errors.length) showToast('error', errors.join('<br>'));
		const succeeded = total - errors.length;
		if (succeeded > 0) showToast('success', `${succeeded} file(s) uploaded successfully.`);

		this.#loadMedia();
	}

	#uploadSingleFile(file, onProgress) {
		return new Promise((resolve, reject) => {
			const form = new FormData();
			form.append('file', file);
			form.append('_nonce', getCSRF());

			const xhr = new XMLHttpRequest();
			xhr.open('POST', '/kb-admin/media?action=upload');

			xhr.upload.addEventListener('progress', (e) => {
				if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
			});

			xhr.addEventListener('load', () => {
				if (xhr.status < 200 || xhr.status >= 300) {
					return reject(new Error(`HTTP ${xhr.status}`));
				}
				try {
					const res = JSON.parse(xhr.responseText);
					res.error ? reject(new Error(res.error)) : resolve(res);
				} catch {
					reject(new Error('Invalid server response'));
				}
			});

			xhr.addEventListener('error', () => reject(new Error('Network error')));
			xhr.send(form);
		});
	}

	// =================================================================
	// View toggle
	// =================================================================

	#initViewToggle() {
		for (const btn of document.querySelectorAll('[data-view]')) {
			if (btn.dataset.view === this.#view) btn.classList.add('active');

			btn.addEventListener('click', () => {
				this.#view = btn.dataset.view;
				localStorage.setItem('kb_media_view', this.#view);
				document.querySelectorAll('[data-view]').forEach((b) => b.classList.remove('active'));
				btn.classList.add('active');
				this.#renderItems();
			});
		}
	}

	// =================================================================
	// Filters
	// =================================================================

	#initFilters() {
		for (const btn of document.querySelectorAll('[data-filter]')) {
			btn.addEventListener('click', () => {
				this.#filter = btn.dataset.filter;
				this.#page = 1;
				document.querySelectorAll('[data-filter]').forEach((b) => b.classList.remove('active'));
				btn.classList.add('active');
				this.#loadMedia();
			});
		}
	}

	// =================================================================
	// Search
	// =================================================================

	#initSearch() {
		const input = document.getElementById('kb-media-search');
		if (!input) return;

		let timer;
		input.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				this.#search = input.value;
				this.#page = 1;
				this.#loadMedia();
			}, 300);
		});
	}

	// =================================================================
	// Load media
	// =================================================================

	async #loadMedia() {
		this.#grid.innerHTML = '<div class="kb-media-loading">Loading...</div>';

		const params = new URLSearchParams({
			page: this.#page,
			per_page: this.#perPage,
		});
		if (this.#filter !== 'all') params.set('type', this.#filter);
		if (this.#search) params.set('search', this.#search);

		try {
			const res = await fetch(`/kb-admin/media?action=list&${params}`, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			});
			const data = await res.json();

			this.#items = data.items ?? [];
			this.#total = data.total ?? 0;
			this.#updateCounts(data.counts ?? {});
			this.#renderItems();
			this.#renderPagination();
		} catch {
			this.#grid.innerHTML = '<div class="kb-media-loading">Failed to load media.</div>';
		}
	}

	#updateCounts(counts) {
		for (const btn of document.querySelectorAll('[data-filter]')) {
			const countEl = btn.querySelector('.kb-filter-count');
			if (countEl && counts[btn.dataset.filter] !== undefined) {
				countEl.textContent = `(${counts[btn.dataset.filter]})`;
			}
		}
	}

	// =================================================================
	// Render
	// =================================================================

	#renderItems() {
		if (!this.#items.length) {
			this.#grid.className = 'kb-media-grid';
			this.#grid.innerHTML = `
				<div class="kb-media-empty">
					<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" style="width:48px;height:48px;opacity:0.3;">
						<rect x="6" y="6" width="36" height="36" rx="4"/><circle cx="18" cy="18" r="4"/><path d="M6 32l10-10 8 8 6-6 12 12"/>
					</svg>
					<p>No media files found.</p>
				</div>`;
			return;
		}

		this.#grid.className = this.#view === 'list' ? 'kb-media-list' : 'kb-media-grid';

		if (this.#view === 'list') {
			const rows = this.#items.map((item) => {
				const sel = this.#selectedId === item.id ? ' class="selected"' : '';
				return `<tr${sel} data-id="${item.id}" onclick="kbMedia.select(${item.id})">
					<td>${thumbHtml(item, 'list')}</td>
					<td><strong>${esc(item.title || item.filename)}</strong><br><small style="color:var(--kb-text-hint);">${esc(item.filename)}</small></td>
					<td><span class="kb-badge">${esc(friendlyType(item.mime_type))}</span></td>
					<td>${formatSize(item.file_size)}</td>
					<td style="color:var(--kb-text-hint);white-space:nowrap;">${esc(formatDate(item.created_at))}</td>
				</tr>`;
			}).join('');

			this.#grid.innerHTML = `
				<table class="kb-table kb-media-table">
					<thead><tr><th style="width:60px;"></th><th>File</th><th>Type</th><th>Size</th><th>Date</th></tr></thead>
					<tbody>${rows}</tbody>
				</table>`;
		} else {
			this.#grid.innerHTML = this.#items.map((item) => {
				const sel = this.#selectedId === item.id ? ' selected' : '';
				return `<div class="kb-media-item${sel}" data-id="${item.id}" onclick="kbMedia.select(${item.id})">
					<div class="kb-media-thumb">${thumbHtml(item, 'grid')}</div>
					<div class="kb-media-name" title="${esc(item.filename)}">${esc(item.title || item.filename)}</div>
				</div>`;
			}).join('');
		}
	}

	// =================================================================
	// Pagination
	// =================================================================

	#renderPagination() {
		if (!this.#pagination) return;

		const totalPages = Math.ceil(this.#total / this.#perPage);
		if (totalPages <= 1) { this.#pagination.innerHTML = ''; return; }

		const start = Math.max(1, this.#page - 2);
		const end = Math.min(totalPages, this.#page + 2);

		let btns = '';
		if (this.#page > 1) btns += `<button onclick="kbMedia.page(${this.#page - 1})" class="kb-btn kb-btn-sm">&laquo; Prev</button>`;
		for (let i = start; i <= end; i++) {
			const cls = i === this.#page ? 'kb-btn kb-btn-sm kb-btn-primary' : 'kb-btn kb-btn-sm';
			btns += `<button onclick="kbMedia.page(${i})" class="${cls}">${i}</button>`;
		}
		if (this.#page < totalPages) btns += `<button onclick="kbMedia.page(${this.#page + 1})" class="kb-btn kb-btn-sm">Next &raquo;</button>`;

		this.#pagination.innerHTML = `
			<span class="kb-pagination-info">${this.#total} item${this.#total !== 1 ? 's' : ''}</span>
			<div class="kb-pagination-btns">${btns}</div>`;
	}

	// =================================================================
	// Detail panel
	// =================================================================

	select(id) {
		this.#selectedId = id;
		this.#renderItems();

		const item = this.#items.find((m) => m.id === id);
		if (!item || !this.#panel) return;

		const isImage = item.mime_type?.startsWith('image/');
		const previewUrl = isImage ? `/${item.url}` : '';
		const dims = (item.width && item.height) ? `${item.width} × ${item.height} px` : '';

		this.#panel.innerHTML = `
			<div class="kb-detail-inner">
				<div class="kb-detail-header">
					<h3>Attachment details</h3>
					<button class="kb-detail-close" onclick="kbMedia.closeDetail()" title="Close">&times;</button>
				</div>
				<div class="kb-detail-preview">
					${isImage
						? `<img src="${esc(previewUrl)}" alt="">`
						: `<div class="kb-media-file-icon large">${fileIcon(item.mime_type)}<span>${esc(fileExt(item.filename))}</span></div>`}
				</div>
				<div class="kb-detail-meta">
					<p><strong>${esc(item.filename)}</strong></p>
					<p>${esc(friendlyType(item.mime_type))} &mdash; ${formatSize(item.file_size)}${dims ? ` &mdash; ${dims}` : ''}</p>
					<p style="color:var(--kb-text-hint);font-size:12px;">Uploaded ${esc(formatDate(item.created_at))}</p>
				</div>
				<div class="kb-detail-fields">
					<div class="kb-form-group"><label class="kb-label">Title</label><input type="text" class="kb-input" id="kb-detail-title" value="${esc(item.title ?? '')}"></div>
					<div class="kb-form-group"><label class="kb-label">Alt text</label><input type="text" class="kb-input" id="kb-detail-alt" value="${esc(item.alt_text ?? '')}" placeholder="Describe the image for accessibility"></div>
					<div class="kb-form-group"><label class="kb-label">Caption</label><textarea class="kb-input" id="kb-detail-caption" rows="2">${esc(item.caption ?? '')}</textarea></div>
					<div class="kb-form-group"><label class="kb-label">File URL</label><div class="kb-input-copy"><input type="text" class="kb-input" id="kb-detail-url" value="${esc(`/${item.url}`)}" readonly><button class="kb-btn kb-btn-sm" onclick="kbMedia.copyUrl()">Copy</button></div></div>
				</div>
				<div class="kb-detail-actions">
					<button class="kb-btn kb-btn-primary kb-btn-sm" onclick="kbMedia.saveDetail(${item.id})">Save changes</button>
					<button class="kb-btn kb-btn-danger kb-btn-sm" onclick="kbMedia.confirmDelete(${item.id})">Delete permanently</button>
				</div>
			</div>`;

		this.#panel.classList.add('open');

		// Auto-save on blur
		for (const fid of ['kb-detail-title', 'kb-detail-alt', 'kb-detail-caption']) {
			document.getElementById(fid)?.addEventListener('blur', () => this.saveDetail(id));
		}
	}

	closeDetail() {
		this.#selectedId = null;
		this.#panel?.classList.remove('open');
		this.#renderItems();
	}

	async saveDetail(id) {
		const title = document.getElementById('kb-detail-title')?.value ?? '';
		const altText = document.getElementById('kb-detail-alt')?.value ?? '';
		const caption = document.getElementById('kb-detail-caption')?.value ?? '';

		try {
			const res = await fetch('/kb-admin/media?action=update', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({ id, title, alt_text: altText, caption, _nonce: getCSRF() }),
			});
			const data = await res.json();

			if (data.success) {
				const item = this.#items.find((m) => m.id === id);
				if (item) Object.assign(item, { title, alt_text: altText, caption });
				showToast('success', 'Media updated.');
			} else {
				showToast('error', data.error ?? 'Failed to save.');
			}
		} catch {
			showToast('error', 'Network error saving media.');
		}
	}

	async confirmDelete(id) {
		const item = this.#items.find((m) => m.id === id);
		if (!confirm(`Permanently delete "${item?.filename ?? 'this file'}"? This cannot be undone.`)) return;

		try {
			const res = await fetch('/kb-admin/media?action=delete', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({ id, _nonce: getCSRF() }),
			});
			const data = await res.json();

			if (data.success) {
				this.closeDetail();
				showToast('success', 'File deleted.');
				this.#loadMedia();
			} else {
				showToast('error', data.error ?? 'Failed to delete.');
			}
		} catch {
			showToast('error', 'Network error deleting file.');
		}
	}

	copyUrl() {
		const input = document.getElementById('kb-detail-url');
		if (!input) return;
		navigator.clipboard?.writeText(input.value).then(
			() => showToast('success', 'URL copied to clipboard.'),
			() => { input.select(); document.execCommand('copy'); showToast('success', 'URL copied.'); }
		);
	}

	page(p) {
		this.#page = p;
		this.#loadMedia();
	}
}

// =================================================================
// Media Selector Modal (used by editor for featured image / inline)
// =================================================================

class MediaSelectorModal {
	#callback = null;
	#filter = 'image';
	#items = [];
	#selectedId = null;
	#modal = null;

	open(callback, filter = 'image') {
		this.#callback = callback;
		this.#filter = filter;
		this.#selectedId = null;
		this.#createModal();
		this.#loadItems('');
	}

	#createModal() {
		document.getElementById('kb-media-modal')?.remove();

		this.#modal = document.createElement('div');
		this.#modal.id = 'kb-media-modal';
		this.#modal.className = 'kb-modal-overlay';
		this.#modal.innerHTML = `
			<div class="kb-modal kb-modal-large">
				<div class="kb-modal-header">
					<h3>Select media</h3>
					<button class="kb-modal-close" id="kb-modal-close-btn">&times;</button>
				</div>
				<div class="kb-modal-toolbar">
					<button class="kb-btn kb-btn-primary kb-btn-sm" id="kb-modal-upload-btn">Upload new</button>
					<input type="file" id="kb-modal-upload-input" multiple accept="image/*" style="display:none;">
					<input type="text" class="kb-input kb-input-sm" id="kb-modal-search" placeholder="Search..." style="max-width:240px;margin-left:auto;">
				</div>
				<div class="kb-modal-grid" id="kb-modal-grid"><div class="kb-media-loading">Loading...</div></div>
				<div class="kb-modal-footer">
					<button class="kb-btn kb-btn-sm" id="kb-modal-cancel-btn">Cancel</button>
					<button class="kb-btn kb-btn-primary kb-btn-sm" id="kb-modal-select-btn" disabled>Select</button>
				</div>
			</div>`;

		document.body.appendChild(this.#modal);

		// Events via delegation & direct binding
		this.#modal.addEventListener('click', (e) => {
			if (e.target === this.#modal) this.close();
		});
		this.#modal.querySelector('#kb-modal-close-btn').addEventListener('click', () => this.close());
		this.#modal.querySelector('#kb-modal-cancel-btn').addEventListener('click', () => this.close());
		this.#modal.querySelector('#kb-modal-select-btn').addEventListener('click', () => this.#confirmSelect());

		const uploadInput = this.#modal.querySelector('#kb-modal-upload-input');
		this.#modal.querySelector('#kb-modal-upload-btn').addEventListener('click', () => uploadInput.click());
		uploadInput.addEventListener('change', () => {
			if (uploadInput.files.length) this.#uploadFiles(uploadInput.files);
			uploadInput.value = '';
		});

		let timer;
		this.#modal.querySelector('#kb-modal-search').addEventListener('input', (e) => {
			clearTimeout(timer);
			timer = setTimeout(() => this.#loadItems(e.target.value), 300);
		});
	}

	async #loadItems(search) {
		const grid = document.getElementById('kb-modal-grid');
		if (!grid) return;

		const params = new URLSearchParams({ per_page: '60', type: this.#filter });
		if (search) params.set('search', search);

		try {
			const res = await fetch(`/kb-admin/media?action=list&${params}`, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			});
			const data = await res.json();
			this.#items = data.items ?? [];
			this.#selectedId = null;
			this.#renderGrid();
		} catch {
			grid.innerHTML = '<div class="kb-media-loading">Failed to load media.</div>';
		}
	}

	#renderGrid() {
		const grid = document.getElementById('kb-modal-grid');
		const selectBtn = document.getElementById('kb-modal-select-btn');
		if (!grid) return;

		if (!this.#items.length) {
			grid.innerHTML = '<div class="kb-media-empty"><p>No media found. Upload a file to get started.</p></div>';
			if (selectBtn) selectBtn.disabled = true;
			return;
		}

		grid.innerHTML = this.#items.map((item) => {
			const sel = this.#selectedId === item.id ? ' selected' : '';
			return `<div class="kb-media-item${sel}" data-id="${item.id}">
				<div class="kb-media-thumb">${thumbHtml(item, 'grid')}</div>
				<div class="kb-media-name">${esc(item.title || item.filename)}</div>
			</div>`;
		}).join('');

		// Click handlers via delegation
		for (const el of grid.querySelectorAll('.kb-media-item')) {
			el.addEventListener('click', () => {
				this.#selectedId = Number(el.dataset.id);
				this.#renderGrid();
			});
		}

		if (selectBtn) selectBtn.disabled = !this.#selectedId;
	}

	#confirmSelect() {
		if (!this.#selectedId || !this.#callback) return;
		const item = this.#items.find((m) => m.id === this.#selectedId);
		if (item) this.#callback(item);
		this.close();
	}

	async #uploadFiles(files) {
		for (const file of files) {
			const form = new FormData();
			form.append('file', file);
			form.append('_nonce', getCSRF());
			try { await fetch('/kb-admin/media?action=upload', { method: 'POST', body: form }); } catch { /* swallow */ }
		}
		const search = document.getElementById('kb-modal-search')?.value ?? '';
		this.#loadItems(search);
	}

	close() {
		this.#modal?.remove();
		this.#modal = null;
		this.#callback = null;
		this.#selectedId = null;
	}
}

// =================================================================
// Shared utilities (module-level)
// =================================================================

const esc = (str) => {
	if (!str) return '';
	const d = document.createElement('div');
	d.appendChild(document.createTextNode(String(str)));
	return d.innerHTML;
};

const getCSRF = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
	?? document.querySelector('input[name="_nonce"]')?.value
	?? '';

const formatSize = (bytes) => {
	if (!bytes) return '0 B';
	const k = 1024;
	const units = ['B', 'KB', 'MB', 'GB'];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return `${parseFloat((bytes / k ** i).toFixed(1))} ${units[i]}`;
};

const formatDate = (str) => {
	if (!str) return '';
	const d = new Date(str);
	return Number.isNaN(d.getTime()) ? str : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const friendlyType = (mime) => {
	if (!mime) return 'Unknown';
	if (mime.startsWith('image/')) return mime.replace('image/', '').toUpperCase();
	if (mime.startsWith('video/')) return 'Video';
	if (mime.startsWith('audio/')) return 'Audio';
	if (mime === 'application/pdf') return 'PDF';
	if (mime.includes('word') || mime.includes('document')) return 'DOC';
	if (mime.includes('excel') || mime.includes('sheet')) return 'XLS';
	if (mime === 'application/zip') return 'ZIP';
	return 'File';
};

const fileExt = (filename) => filename?.split('.').pop()?.toUpperCase() ?? '';

const fileIcon = (mime) => {
	const doc = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
	if (!mime) return doc;
	if (mime.startsWith('video/')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="5 3 19 12 5 21"/></svg>';
	if (mime.startsWith('audio/')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
	if (mime === 'application/pdf') return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15h6"/></svg>';
	return doc;
};

const thumbHtml = (item, mode) => {
	if (item.mime_type?.startsWith('image/')) {
		const url = item.thumb_url ? `/${item.thumb_url}` : `/${item.url}`;
		return `<img src="${esc(url)}" alt="${esc(item.alt_text ?? '')}" loading="lazy">`;
	}
	return `<div class="kb-media-file-icon">${fileIcon(item.mime_type)}<span>${esc(fileExt(item.filename))}</span></div>`;
};

const showToast = (type, msg) => {
	document.querySelector('.kb-toast')?.remove();
	const toast = Object.assign(document.createElement('div'), {
		className: `kb-toast kb-toast-${type}`,
		innerHTML: msg,
	});
	document.body.appendChild(toast);
	requestAnimationFrame(() => toast.classList.add('show'));
	setTimeout(() => {
		toast.classList.remove('show');
		setTimeout(() => toast.remove(), 300);
	}, 3000);
};

// =================================================================
// Init & global API
// =================================================================

const kbMediaSelector = new MediaSelectorModal();
let kbMediaInstance;

document.addEventListener('DOMContentLoaded', () => {
	kbMediaInstance = new MediaLibrary();
});

// Expose for inline onclick handlers & editor integration
window.kbMedia = {
	select: (...a) => kbMediaInstance?.select(...a),
	closeDetail: () => kbMediaInstance?.closeDetail(),
	saveDetail: (...a) => kbMediaInstance?.saveDetail(...a),
	confirmDelete: (...a) => kbMediaInstance?.confirmDelete(...a),
	copyUrl: () => kbMediaInstance?.copyUrl(),
	page: (p) => kbMediaInstance?.page(p),
};

window.KBMediaSelector = {
	open: (cb, filter) => kbMediaSelector.open(cb, filter),
};