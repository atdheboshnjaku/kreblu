/**
 * KBEditor — Reusable WYSIWYG Rich Text Editor
 *
 * Usage:
 *   const editor = new KBEditor(containerEl, {
 *     toolbar: ['bold', 'italic', 'h2', 'h3', 'link', 'image', 'ul', 'ol', 'blockquote', 'code', 'hr'],
 *     placeholder: 'Start writing...',
 *     content: '<p>Initial HTML</p>',
 *     onChange: (html) => { hiddenInput.value = html; },
 *     onFocus: () => {},
 *     onBlur: () => {},
 *   });
 *
 *   editor.getHTML()      — returns current HTML
 *   editor.setHTML(str)   — sets content
 *   editor.focus()        — focus the editor
 *   editor.isEmpty()      — true if no meaningful content
 *   editor.getWordCount() — word count
 */

class KBEditor {
	#container;
	#editable;
	#toolbar;
	#options;
	#hiddenInput;
	#placeholder;

	static #defaultToolbar = ['bold', 'italic', '|', 'h1', 'h2', 'h3', 'p', '|', 'link', 'image', '|', 'ul', 'ol', 'blockquote', 'code', 'hr'];

	static #toolbarConfig = {
		bold:       { icon: '<strong>B</strong>', title: 'Bold (Ctrl+B)', cmd: 'bold' },
		italic:     { icon: '<em>I</em>', title: 'Italic (Ctrl+I)', cmd: 'italic' },
		h1:         { icon: 'H1', title: 'Heading 1', block: 'h1' },
		h2:         { icon: 'H2', title: 'Heading 2', block: 'h2' },
		h3:         { icon: 'H3', title: 'Heading 3', block: 'h3' },
		p:          { icon: '¶', title: 'Paragraph', block: 'p' },
		link:       { icon: '🔗', title: 'Link (Ctrl+K)', action: 'link' },
		image:      { icon: '🖼', title: 'Insert image', action: 'image' },
		ul:         { icon: '• List', title: 'Bulleted list', cmd: 'insertUnorderedList' },
		ol:         { icon: '1. List', title: 'Numbered list', cmd: 'insertOrderedList' },
		blockquote: { icon: '❝', title: 'Blockquote', block: 'blockquote' },
		code:       { icon: '&lt;/&gt;', title: 'Code block', action: 'code' },
		hr:         { icon: '—', title: 'Horizontal rule', cmd: 'insertHorizontalRule' },
	};

	constructor(container, options = {}) {
		this.#container = typeof container === 'string' ? document.querySelector(container) : container;
		if (!this.#container) throw new Error('KBEditor: container not found');

		this.#options = {
			toolbar: KBEditor.#defaultToolbar,
			placeholder: 'Start writing...',
			content: '',
			onChange: null,
			onFocus: null,
			onBlur: null,
			...options,
		};

		this.#build();
		this.#bindEvents();

		if (this.#options.content) {
			this.setHTML(this.#options.content);
		}
	}

	// =================================================================
	// Build DOM
	// =================================================================

	#build() {
		this.#container.classList.add('kb-wysiwyg');

		// Toolbar
		this.#toolbar = document.createElement('div');
		this.#toolbar.className = 'kb-wysiwyg-toolbar';
		this.#toolbar.setAttribute('role', 'toolbar');

		for (const item of this.#options.toolbar) {
			if (item === '|') {
				const sep = document.createElement('span');
				sep.className = 'kb-wysiwyg-sep';
				this.#toolbar.appendChild(sep);
				continue;
			}

			const config = KBEditor.#toolbarConfig[item];
			if (!config) continue;

			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'kb-wysiwyg-btn';
			btn.dataset.action = item;
			btn.title = config.title;
			btn.innerHTML = config.icon;
			this.#toolbar.appendChild(btn);
		}

		// Editable area
		this.#editable = document.createElement('div');
		this.#editable.className = 'kb-wysiwyg-content';
		this.#editable.contentEditable = 'true';
		this.#editable.setAttribute('role', 'textbox');
		this.#editable.setAttribute('aria-multiline', 'true');

		// Placeholder
		this.#placeholder = document.createElement('div');
		this.#placeholder.className = 'kb-wysiwyg-placeholder';
		this.#placeholder.textContent = this.#options.placeholder;

		this.#container.appendChild(this.#toolbar);
		this.#container.appendChild(this.#editable);
		this.#container.appendChild(this.#placeholder);

		this.#updatePlaceholder();
	}

	// =================================================================
	// Events
	// =================================================================

	#bindEvents() {
		// Toolbar clicks
		this.#toolbar.addEventListener('click', (e) => {
			const btn = e.target.closest('[data-action]');
			if (!btn) return;
			e.preventDefault();
			this.#execAction(btn.dataset.action);
		});

		// Content changes
		this.#editable.addEventListener('input', () => {
			this.#ensureBlockWrapping();
			this.#updatePlaceholder();
			this.#updateToolbarState();
			this.#options.onChange?.(this.getHTML());
		});

		// Focus / blur
		this.#editable.addEventListener('focus', () => {
			this.#container.classList.add('kb-wysiwyg-focused');
			this.#updateToolbarState();
			this.#options.onFocus?.();
		});

		this.#editable.addEventListener('blur', () => {
			this.#container.classList.remove('kb-wysiwyg-focused');
			this.#options.onBlur?.();
		});

		// Selection change — update toolbar active states
		document.addEventListener('selectionchange', () => {
			if (this.#editable.contains(document.activeElement) || document.activeElement === this.#editable) {
				this.#updateToolbarState();
			}
		});

		// Keyboard shortcuts
		this.#editable.addEventListener('keydown', (e) => {
			if (e.ctrlKey || e.metaKey) {
				switch (e.key) {
					case 'b': e.preventDefault(); this.#execAction('bold'); break;
					case 'i': e.preventDefault(); this.#execAction('italic'); break;
					case 'k': e.preventDefault(); this.#execAction('link'); break;
				}
			}

			// Enter in an empty block — reset to paragraph
			if (e.key === 'Enter' && !e.shiftKey) {
				const block = this.#getCurrentBlock();
				if (block && ['H2', 'H3', 'H4', 'BLOCKQUOTE'].includes(block.tagName)) {
					if (block.textContent.trim() === '') {
						e.preventDefault();
						document.execCommand('formatBlock', false, 'p');
					}
				}
			}

			// Tab — insert spaces (not change focus)
			if (e.key === 'Tab') {
				e.preventDefault();
				document.execCommand('insertText', false, '    ');
			}
		});

		// Paste — clean up pasted content
		this.#editable.addEventListener('paste', (e) => {
			e.preventDefault();
			const html = e.clipboardData?.getData('text/html');
			const text = e.clipboardData?.getData('text/plain') ?? '';

			if (html) {
				const cleaned = this.#cleanPastedHTML(html);
				document.execCommand('insertHTML', false, cleaned);
			} else {
				document.execCommand('insertText', false, text);
			}
		});
	}

	// =================================================================
	// Actions
	// =================================================================

	#execAction(action) {
		const config = KBEditor.#toolbarConfig[action];
		if (!config) return;

		this.#editable.focus();

		if (config.cmd) {
			document.execCommand(config.cmd, false, null);
		} else if (config.block) {
			document.execCommand('formatBlock', false, `<${config.block}>`);
		} else if (config.action === 'link') {
			this.#insertLink();
		} else if (config.action === 'image') {
			this.#insertImage();
		} else if (config.action === 'code') {
			this.#insertCodeBlock();
		}

		this.#updateToolbarState();
		this.#options.onChange?.(this.getHTML());
	}

	#insertLink() {
		const sel = window.getSelection();
		const existingLink = sel?.anchorNode?.parentElement?.closest('a');

		if (existingLink) {
			// Edit or remove existing link
			const newUrl = prompt('Edit URL (leave empty to remove):', existingLink.href);
			if (newUrl === null) return; // cancelled
			if (newUrl === '') {
				// Remove link, keep text
				const text = existingLink.textContent;
				existingLink.replaceWith(document.createTextNode(text));
			} else {
				existingLink.href = newUrl;
			}
		} else {
			const selectedText = sel?.toString() ?? '';
			const url = prompt('Enter URL:', 'https://');
			if (!url || url === 'https://') return;

			if (selectedText) {
				document.execCommand('createLink', false, url);
				// Add target blank
				const newLink = sel?.anchorNode?.parentElement?.closest('a');
				if (newLink) {
					newLink.target = '_blank';
					newLink.rel = 'noopener noreferrer';
				}
			} else {
				const label = prompt('Link text:', url) ?? url;
				const html = `<a href="${this.#escAttr(url)}" target="_blank" rel="noopener noreferrer">${this.#escHTML(label)}</a>&nbsp;`;
				document.execCommand('insertHTML', false, html);
			}
		}
	}

	#insertImage() {
		// Use media selector if available
		if (typeof KBMediaSelector !== 'undefined') {
			KBMediaSelector.open((item) => {
				const alt = item.alt_text || item.title || '';
				const html = `<figure class="kb-img-wrap"><img src="/${item.url}" alt="${this.#escAttr(alt)}"><figcaption contenteditable="true">${this.#escHTML(alt)}</figcaption></figure><p><br></p>`;
				this.#editable.focus();
				document.execCommand('insertHTML', false, html);
				this.#options.onChange?.(this.getHTML());
			}, 'image');
			return;
		}

		// Fallback: URL prompt
		const url = prompt('Image URL:', 'https://');
		if (!url || url === 'https://') return;
		const alt = prompt('Alt text:', '') ?? '';
		const html = `<img src="${this.#escAttr(url)}" alt="${this.#escAttr(alt)}"><p><br></p>`;
		document.execCommand('insertHTML', false, html);
	}

	#insertCodeBlock() {
		const sel = window.getSelection();
		const text = sel?.toString() ?? '';
		const code = text || '// code here';
		const html = `<pre><code>${this.#escHTML(code)}</code></pre><p><br></p>`;
		document.execCommand('insertHTML', false, html);
	}

	// =================================================================
	// Toolbar state
	// =================================================================

	#updateToolbarState() {
		for (const btn of this.#toolbar.querySelectorAll('[data-action]')) {
			const action = btn.dataset.action;
			const config = KBEditor.#toolbarConfig[action];
			if (!config) continue;

			let active = false;

			if (config.cmd) {
				try { active = document.queryCommandState(config.cmd); } catch {}
			} else if (config.block) {
				const block = this.#getCurrentBlock();
				active = block?.tagName?.toLowerCase() === config.block;
			}

			btn.classList.toggle('active', active);
		}
	}

	#getCurrentBlock() {
		const sel = window.getSelection();
		if (!sel?.rangeCount) return null;

		let node = sel.anchorNode;
		while (node && node !== this.#editable) {
			if (node.nodeType === Node.ELEMENT_NODE) {
				const display = window.getComputedStyle(node).display;
				if (display === 'block' || display === 'list-item') return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	// =================================================================
	// Content helpers
	// =================================================================

	#ensureBlockWrapping() {
		// Wrap orphan text nodes in <p>
		for (const node of [...this.#editable.childNodes]) {
			if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '') {
				const p = document.createElement('p');
				node.replaceWith(p);
				p.appendChild(node);

				// Move cursor to end of new paragraph
				const range = document.createRange();
				range.selectNodeContents(p);
				range.collapse(false);
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			}
		}
	}

	#updatePlaceholder() {
		const empty = this.isEmpty();
		this.#placeholder.style.display = empty ? 'block' : 'none';
	}

	#cleanPastedHTML(html) {
		const div = document.createElement('div');
		div.innerHTML = html;

		// Remove scripts, styles, comments
		for (const el of div.querySelectorAll('script, style, meta, link')) {
			el.remove();
		}

		// Strip all attributes except href, src, alt, target, rel
		const allowedAttrs = ['href', 'src', 'alt', 'target', 'rel'];
		for (const el of div.querySelectorAll('*')) {
			for (const attr of [...el.attributes]) {
				if (!allowedAttrs.includes(attr.name)) {
					el.removeAttribute(attr.name);
				}
			}
		}

		// Convert divs to paragraphs
		for (const el of div.querySelectorAll('div')) {
			const p = document.createElement('p');
			p.innerHTML = el.innerHTML;
			el.replaceWith(p);
		}

		// Remove spans (keep content)
		for (const el of div.querySelectorAll('span')) {
			el.replaceWith(...el.childNodes);
		}

		return div.innerHTML;
	}

	#escAttr(str) {
		return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	#escHTML(str) {
		return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	// =================================================================
	// Public API
	// =================================================================

	getHTML() {
		return this.#editable.innerHTML;
	}

	setHTML(html) {
		this.#editable.innerHTML = html;
		this.#updatePlaceholder();
	}

	focus() {
		this.#editable.focus();
	}

	isEmpty() {
		const text = this.#editable.textContent?.trim() ?? '';
		const html = this.#editable.innerHTML?.trim() ?? '';
		return text === '' || html === '' || html === '<br>' || html === '<p><br></p>';
	}

	getWordCount() {
		const text = this.#editable.textContent?.trim() ?? '';
		return text === '' ? 0 : text.split(/\s+/).length;
	}

	getEditable() {
		return this.#editable;
	}

	destroy() {
		this.#container.classList.remove('kb-wysiwyg', 'kb-wysiwyg-focused');
		this.#toolbar.remove();
		this.#editable.remove();
		this.#placeholder.remove();
	}
}

// Make globally available
window.KBEditor = KBEditor;