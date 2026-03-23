/**
 * K Hub Post Editor
 * ES2022+
 */

const $ = (sel) => document.querySelector(sel);

const toolbar = $('#kb-editor-toolbar');
const bodyField = $('#kb-editor-body');
const inlinePanel = $('#kb-inline-panel');

// ---------------------------------------------------------------
// Selection helpers
// ---------------------------------------------------------------
const getSelection = () => {
	const { selectionStart: start, selectionEnd: end } = bodyField;
	return { start, end, text: bodyField.value.substring(start, end) };
};

const insertAt = (text, start, end, selectMode = 'end') => {
	bodyField.setRangeText(text, start, end, selectMode);
	bodyField.focus();
	markDirty();
};

const wrapSelection = (before, after, fallback = '') => {
	const { start, end, text } = getSelection();
	insertAt(`${before}${text || fallback}${after}`, start, end, 'select');
};

// ---------------------------------------------------------------
// Inline panel (link/image — plain divs, no nested forms)
// ---------------------------------------------------------------
let savedSelection = null;

const showPanel = (html, onInsert) => {
	if (!inlinePanel) return;

	savedSelection = getSelection();

	inlinePanel.innerHTML = html;
	inlinePanel.style.display = 'flex';

	inlinePanel.querySelector('.kb-panel-insert')?.addEventListener('click', () => {
		onInsert();
		hidePanel();
	});

	inlinePanel.querySelector('.kb-panel-cancel')?.addEventListener('click', hidePanel);

	inlinePanel.querySelectorAll('input[type="url"], input[type="text"]').forEach(input => {
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				onInsert();
				hidePanel();
			}
			if (e.key === 'Escape') {
				hidePanel();
			}
		});
	});

	inlinePanel.querySelector('input')?.focus();
};

const hidePanel = () => {
	if (!inlinePanel) return;
	inlinePanel.innerHTML = '';
	inlinePanel.style.display = 'none';
	bodyField?.focus();
	savedSelection = null;
};

// ---------------------------------------------------------------
// Toolbar actions
// ---------------------------------------------------------------
const toolbarActions = {
	bold: () => wrapSelection('<strong>', '</strong>', 'bold text'),
	italic: () => wrapSelection('<em>', '</em>', 'italic text'),
	h2: () => wrapSelection('\n<h2>', '</h2>\n', 'Heading'),
	h3: () => wrapSelection('\n<h3>', '</h3>\n', 'Subheading'),
	p: () => wrapSelection('\n<p>', '</p>\n', 'Paragraph text'),
	blockquote: () => wrapSelection('\n<blockquote>', '</blockquote>\n', 'Quote text'),
	code: () => wrapSelection('<code>', '</code>', 'code'),
	codeblock: () => wrapSelection('\n<pre><code>', '</code></pre>\n', '// code here'),

	hr() {
		const { start, end } = getSelection();
		insertAt('\n<hr>\n', start, end);
	},

	link() {
		const { text } = getSelection();
		const escapedText = text.replace(/"/g, '&quot;');

		showPanel(`
			<input type="url" placeholder="https://example.com" class="kb-input kb-input-sm" id="kb-panel-url" style="min-width:200px;">
			<input type="text" placeholder="Link text" class="kb-input kb-input-sm" id="kb-panel-label" value="${escapedText}" style="min-width:140px;">
			<label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--kb-text-secondary);white-space:nowrap;cursor:pointer;user-select:none;">
				<input type="checkbox" id="kb-panel-newtab" checked style="accent-color:var(--kb-rust);width:14px;height:14px;"> New tab
			</label>
			<button type="button" class="kb-btn kb-btn-primary kb-btn-sm kb-panel-insert">Insert</button>
			<button type="button" class="kb-btn kb-btn-outline kb-btn-sm kb-panel-cancel">Cancel</button>
		`, () => {
			const url = $('#kb-panel-url')?.value.trim();
			const label = $('#kb-panel-label')?.value.trim() || url;
			const newTab = $('#kb-panel-newtab')?.checked;
			if (!url) return;

			const attrs = newTab
				? `href="${url}" target="_blank" rel="noopener noreferrer nofollow"`
				: `href="${url}"`;

			const { start, end } = savedSelection ?? getSelection();
			insertAt(`<a ${attrs}>${label}</a>`, start, end);
		});
	},

	img() {
		// Use media selector modal if available, otherwise fall back to URL input
		if (typeof KBMediaSelector !== 'undefined') {
			const sel = getSelection();
			KBMediaSelector.open((item) => {
				const alt = item.alt_text || item.title || '';
				insertAt(`<img src="/${item.url}" alt="${alt}">`, sel.start, sel.end);
			}, 'image');
			return;
		}
		showPanel(`
			<input type="url" placeholder="https://example.com/image.jpg" class="kb-input kb-input-sm" id="kb-panel-src" style="min-width:220px;">
			<input type="text" placeholder="Alt text (description)" class="kb-input kb-input-sm" id="kb-panel-alt" style="min-width:160px;">
			<button type="button" class="kb-btn kb-btn-primary kb-btn-sm kb-panel-insert">Insert</button>
			<button type="button" class="kb-btn kb-btn-outline kb-btn-sm kb-panel-cancel">Cancel</button>
		`, () => {
			const src = $('#kb-panel-src')?.value.trim();
			const alt = $('#kb-panel-alt')?.value.trim() ?? '';
			if (!src) return;
			const { start, end } = savedSelection ?? getSelection();
			insertAt(`<img src="${src}" alt="${alt}">`, start, end);
		});
	},

	ul() {
		const { start, end, text } = getSelection();
		const items = text
			? text.split('\n').filter(l => l.trim()).map(l => `  <li>${l.trim()}</li>`).join('\n')
			: '  <li>Item</li>';
		insertAt(`\n<ul>\n${items}\n</ul>\n`, start, end);
	},

	ol() {
		const { start, end, text } = getSelection();
		const items = text
			? text.split('\n').filter(l => l.trim()).map(l => `  <li>${l.trim()}</li>`).join('\n')
			: '  <li>Item</li>';
		insertAt(`\n<ol>\n${items}\n</ol>\n`, start, end);
	},
};

toolbar?.addEventListener('click', (e) => {
	const btn = e.target.closest('[data-action]');
	if (!btn) return;
	toolbarActions[btn.dataset.action]?.();
});

// ---------------------------------------------------------------
// Auto-slug from title
// ---------------------------------------------------------------
const titleField = $('#kb-editor-title');
const slugField = $('#kb-editor-slug');
const slugDisplay = $('#kb-slug-display');
let slugManuallyEdited = false;

const slugify = (text) =>
	text.toLowerCase().trim()
		.replace(/[^\w\s-]/g, '')
		.replace(/[\s_]+/g, '-')
		.replace(/-+/g, '-')
		.replace(/^-+|-+$/g, '');

if (titleField && slugField && slugField.value === '') {
	titleField.addEventListener('input', () => {
		if (slugManuallyEdited) return;
		const slug = slugify(titleField.value);
		slugField.value = slug;
		if (slugDisplay) slugDisplay.textContent = slug || '...';
	});
}

slugField?.addEventListener('input', () => {
	slugManuallyEdited = true;
	slugField.value = slugify(slugField.value);
	if (slugDisplay) slugDisplay.textContent = slugField.value || '...';
});

// ---------------------------------------------------------------
// Live word count
// ---------------------------------------------------------------
const wordCountEl = $('#kb-word-count');

const updateWordCount = () => {
	if (!bodyField || !wordCountEl) return;
	const text = bodyField.value.replace(/<[^>]*>/g, ' ').trim();
	const count = text === '' ? 0 : text.split(/\s+/).length;
	wordCountEl.textContent = `${count} word${count !== 1 ? 's' : ''}`;
};

bodyField?.addEventListener('input', updateWordCount);
updateWordCount();

// ---------------------------------------------------------------
// Dirty state tracking
// ---------------------------------------------------------------
let isDirty = false;
const saveIndicator = $('#kb-save-indicator');
const editorForm = $('#kb-editor-form');

const markDirty = () => {
	isDirty = true;
	if (saveIndicator) {
		saveIndicator.textContent = 'Unsaved changes';
		saveIndicator.style.color = 'var(--kb-warning)';
	}
};

editorForm?.addEventListener('input', markDirty);
editorForm?.addEventListener('submit', () => { isDirty = false; });

window.addEventListener('beforeunload', (e) => {
	if (isDirty) {
		e.preventDefault();
		e.returnValue = '';
	}
});

// ---------------------------------------------------------------
// Keyboard shortcuts
// ---------------------------------------------------------------
const shortcuts = { b: 'bold', i: 'italic', k: 'link' };

bodyField?.addEventListener('keydown', (e) => {
	if (e.key === 'Escape' && inlinePanel?.style.display !== 'none') {
		hidePanel();
		return;
	}

	if ((e.ctrlKey || e.metaKey) && shortcuts[e.key]) {
		e.preventDefault();
		toolbarActions[shortcuts[e.key]]?.();
		return;
	}

	if (e.key === 'Tab') {
		e.preventDefault();
		bodyField.setRangeText('  ', bodyField.selectionStart, bodyField.selectionEnd, 'end');
	}
});

document.addEventListener('keydown', (e) => {
	if ((e.ctrlKey || e.metaKey) && e.key === 's') {
		e.preventDefault();
		editorForm?.submit();
	}
});