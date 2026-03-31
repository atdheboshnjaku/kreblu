/**
 * KBEditor v3 — WYSIWYG
 */

class KBEditor {
	#container;
	#editable;
	#floatingBar;
	#slashMenu;
	#imgControls;
	#options;
	#slashQuery = '';
	#slashRange = null;
	#activeImg = null;
	#resizing = false;
	#resizeStart = null;

	static #slashItems = [
		{ key: 'h2', label: 'Heading 2', desc: 'Large heading', icon: 'H2' },
		{ key: 'h3', label: 'Heading 3', desc: 'Small heading', icon: 'H3' },
		{ key: 'image', label: 'Image', desc: 'From media library', icon: '🖼' },
		{ key: 'divider', label: 'Divider', desc: 'Horizontal line', icon: '—' },
		{ key: 'quote', label: 'Quote', desc: 'Blockquote', icon: '❝' },
		{ key: 'code', label: 'Code', desc: 'Code block', icon: '{ }' },
		{ key: 'ul', label: 'Bullet list', desc: 'Unordered list', icon: '•' },
		{ key: 'ol', label: 'Number list', desc: 'Ordered list', icon: '1.' },
	];

	constructor(container, options = {}) {
		this.#container = typeof container === 'string' ? document.querySelector(container) : container;
		if (!this.#container) throw new Error('KBEditor: container not found');
		this.#options = { placeholder: 'Start writing... type / for commands', content: '', onChange: null, ...options };
		this.#build();
		this.#bind();
		if (this.#options.content) this.setHTML(this.#options.content);
		this.#updatePlaceholder();
		this.#refreshBlockLabels();
	}

	// === Build ===
	#build() {
		this.#container.classList.add('kbe');

		this.#editable = document.createElement('div');
		this.#editable.className = 'kbe-content';
		this.#editable.contentEditable = 'true';
		this.#editable.dataset.placeholder = this.#options.placeholder;
		this.#container.appendChild(this.#editable);

		// Floating bar
		this.#floatingBar = document.createElement('div');
		this.#floatingBar.className = 'kbe-float';
		this.#floatingBar.innerHTML = [
			this.#btn('bold', '<b>B</b>'), this.#btn('italic', '<i>I</i>'), '<span class="kbe-sep"></span>',
			this.#btn('h2', 'H2'), this.#btn('h3', 'H3'), this.#btn('p', '¶'), '<span class="kbe-sep"></span>',
			this.#btn('link', '🔗'), this.#btn('quote', '❝'),
		].join('');
		document.body.appendChild(this.#floatingBar);

		// Slash menu
		this.#slashMenu = document.createElement('div');
		this.#slashMenu.className = 'kbe-slash';
		document.body.appendChild(this.#slashMenu);

		// Image controls
		this.#imgControls = document.createElement('div');
		this.#imgControls.className = 'kbe-imgctl';
		document.body.appendChild(this.#imgControls);
	}

	#btn(action, label) {
		return `<button type="button" class="kbe-fbtn" data-a="${action}">${label}</button>`;
	}

	// === Events ===
	#bind() {
		// Float bar
		this.#floatingBar.addEventListener('mousedown', (e) => {
			e.preventDefault();
			const b = e.target.closest('[data-a]');
			if (b) this.#doFloat(b.dataset.a);
		});

		// Selection
		document.addEventListener('selectionchange', () => {
			if (!this.#editable.contains(document.getSelection()?.anchorNode)) { this.#hideFloat(); return; }
			this.#checkSelection();
		});

		// Input
		this.#editable.addEventListener('input', () => {
			this.#wrapOrphans();
			this.#updatePlaceholder();
			this.#cleanEmptyLists();
			this.#handleSlash();
			this.#refreshBlockLabels();
			this.#options.onChange?.(this.getHTML());
		});

		// Keydown
		this.#editable.addEventListener('keydown', (e) => {
			if (this.#slashMenu.classList.contains('vis') && this.#slashKey(e)) return;
			this.#shortcuts(e);
			// Enter in empty heading/quote → paragraph
			if (e.key === 'Enter' && !e.shiftKey) {
				const blk = this.#block();
				if (blk && ['H2', 'H3', 'BLOCKQUOTE'].includes(blk.tagName) && !blk.textContent.trim()) {
					e.preventDefault();
					document.execCommand('formatBlock', false, '<p>');
					this.#refreshBlockLabels();
				}
			}
			// Backspace at start of list item when empty → exit list
			if (e.key === 'Backspace') {
				const sel = window.getSelection();
				const li = sel?.anchorNode?.closest?.('li') ?? sel?.anchorNode?.parentElement?.closest('li');
				if (li && li.textContent.trim() === '' && sel.anchorOffset === 0) {
					e.preventDefault();
					const list = li.closest('ul, ol');
					// Replace empty li with a paragraph after the list
					const p = document.createElement('p');
					p.innerHTML = '<br>';
					if (li.nextElementSibling) {
						// More items after — just remove this li
						li.remove();
					} else if (!li.previousElementSibling) {
						// Only item — remove entire list
						list.replaceWith(p);
					} else {
						// Last item — remove li, add p after list
						li.remove();
						list.after(p);
					}
					// Place cursor in new p
					const r = document.createRange();
					r.selectNodeContents(p);
					r.collapse(true);
					sel.removeAllRanges();
					sel.addRange(r);
					this.#refreshBlockLabels();
					this.#options.onChange?.(this.getHTML());
				}
			}
		});

		// Focus/blur
		this.#editable.addEventListener('focus', () => this.#container.classList.add('kbe-on'));
		this.#editable.addEventListener('blur', () => {
			setTimeout(() => {
				if (!this.#floatingBar.matches(':hover') && !this.#imgControls.matches(':hover')) {
					this.#container.classList.remove('kbe-on');
					this.#hideFloat();
					this.#hideImg();
				}
			}, 150);
		});

		// Click images
		this.#editable.addEventListener('click', (e) => {
			const img = e.target.closest('img');
			if (img) { e.preventDefault(); this.#showImg(img); } else { this.#hideImg(); }
		});

		// Paste
		this.#editable.addEventListener('paste', (e) => {
			e.preventDefault();
			const h = e.clipboardData?.getData('text/html');
			const t = e.clipboardData?.getData('text/plain') ?? '';
			document.execCommand(h ? 'insertHTML' : 'insertText', false, h ? this.#cleanPaste(h) : t);
		});

		// Global click to close menus
		document.addEventListener('click', (e) => {
			if (!this.#slashMenu.contains(e.target)) this.#hideSlash();
			if (!this.#imgControls.contains(e.target) && !e.target.closest('.kbe-content img')) this.#hideImg();
		});

		// Global mousemove/mouseup for image resize
		document.addEventListener('mousemove', (e) => this.#onResizeMove(e));
		document.addEventListener('mouseup', () => this.#onResizeEnd());
	}

	// === Floating toolbar ===
	#checkSelection() {
		const sel = window.getSelection();
		if (!sel?.rangeCount || sel.isCollapsed || !sel.toString().trim()) { this.#hideFloat(); return; }
		const rect = sel.getRangeAt(0).getBoundingClientRect();
		const w = 300;
		let left = rect.left + rect.width / 2 - w / 2;
		left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
		this.#floatingBar.style.left = left + 'px';
		this.#floatingBar.style.top = (rect.top + window.scrollY - 46) + 'px';
		this.#floatingBar.classList.add('vis');
		this.#updateFloatState();
	}

	#updateFloatState() {
		for (const b of this.#floatingBar.querySelectorAll('[data-a]')) {
			let on = false;
			const a = b.dataset.a;
			try {
				if (a === 'bold') on = document.queryCommandState('bold');
				else if (a === 'italic') on = document.queryCommandState('italic');
				else if (a === 'link') on = !!this.#parentLink();
				else { const blk = this.#block(); on = blk?.tagName?.toLowerCase() === a; }
			} catch {}
			b.classList.toggle('on', on);
		}
	}

	#hideFloat() { this.#floatingBar.classList.remove('vis'); }

	#doFloat(a) {
		this.#editable.focus();
		switch (a) {
			case 'bold': document.execCommand('bold'); break;
			case 'italic': document.execCommand('italic'); break;
			case 'h2': document.execCommand('formatBlock', false, '<h2>'); break;
			case 'h3': document.execCommand('formatBlock', false, '<h3>'); break;
			case 'p': document.execCommand('formatBlock', false, '<p>'); break;
			case 'quote': document.execCommand('formatBlock', false, '<blockquote>'); break;
			case 'link': this.#doLink(); break;
		}
		this.#updateFloatState();
		this.#refreshBlockLabels();
		this.#options.onChange?.(this.getHTML());
	}

	// === Block type labels ===
	#refreshBlockLabels() {
		// Remove old labels
		for (const el of this.#editable.querySelectorAll('.kbe-block-label')) el.remove();

		const labelMap = { H2: 'Heading 2', H3: 'Heading 3', BLOCKQUOTE: 'Quote', PRE: 'Code', UL: 'Bullet list', OL: 'Numbered list', FIGURE: 'Image' };

		for (const child of this.#editable.children) {
			const tag = child.tagName;
			const text = labelMap[tag];
			if (!text) continue;
			if (child.querySelector('.kbe-block-label')) continue;

			const lbl = document.createElement('span');
			lbl.className = 'kbe-block-label';
			lbl.textContent = text;
			lbl.contentEditable = 'false';
			child.style.position = 'relative';
			child.appendChild(lbl);
		}
	}

	// === Slash commands ===
	#handleSlash() {
		const sel = window.getSelection();
		if (!sel?.rangeCount) return;
		const node = sel.anchorNode;
		if (node?.nodeType !== Node.TEXT_NODE) { this.#hideSlash(); return; }
		const text = node.textContent;
		const offset = sel.anchorOffset;
		const before = text.substring(0, offset);
		const idx = before.lastIndexOf('/');
		if (idx === -1 || (idx > 0 && !/[\s\u00A0]/.test(before[idx - 1]) && idx !== 0)) { this.#hideSlash(); return; }
		const q = before.substring(idx + 1).toLowerCase();
		this.#slashQuery = q;
		this.#slashRange = { node, start: idx, end: offset };
		this.#showSlash(q);
	}

	#showSlash(q) {
		const items = KBEditor.#slashItems.filter(i => i.label.toLowerCase().includes(q) || i.key.includes(q));
		if (!items.length) { this.#hideSlash(); return; }
		this.#slashMenu.innerHTML = items.map((i, n) =>
			`<div class="kbe-si${n === 0 ? ' on' : ''}" data-k="${i.key}"><span class="kbe-sicon">${i.icon}</span><div><strong>${i.label}</strong><br><span class="kbe-sdesc">${i.desc}</span></div></div>`
		).join('');
		const rect = window.getSelection().getRangeAt(0).getBoundingClientRect();
		this.#slashMenu.style.left = rect.left + 'px';
		this.#slashMenu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
		this.#slashMenu.classList.add('vis');
		for (const el of this.#slashMenu.querySelectorAll('.kbe-si')) {
			el.addEventListener('mousedown', (e) => { e.preventDefault(); this.#doSlash(el.dataset.k); });
		}
	}

	#hideSlash() { this.#slashMenu.classList.remove('vis'); this.#slashQuery = ''; this.#slashRange = null; }

	#slashKey(e) {
		const items = this.#slashMenu.querySelectorAll('.kbe-si');
		const cur = this.#slashMenu.querySelector('.kbe-si.on');
		const idx = [...items].indexOf(cur);
		if (e.key === 'ArrowDown') { e.preventDefault(); cur?.classList.remove('on'); (items[idx + 1] ?? items[0]).classList.add('on'); return true; }
		if (e.key === 'ArrowUp') { e.preventDefault(); cur?.classList.remove('on'); (items[idx - 1] ?? items[items.length - 1]).classList.add('on'); return true; }
		if (e.key === 'Enter') { e.preventDefault(); if (cur) this.#doSlash(cur.dataset.k); return true; }
		if (e.key === 'Escape') { e.preventDefault(); this.#hideSlash(); return true; }
		return false;
	}

	#doSlash(key) {
		if (this.#slashRange) {
			const { node, start, end } = this.#slashRange;
			node.textContent = node.textContent.substring(0, start) + node.textContent.substring(end);
			const r = document.createRange(); r.setStart(node, start); r.collapse(true);
			window.getSelection().removeAllRanges(); window.getSelection().addRange(r);
		}
		this.#hideSlash();
		switch (key) {
			case 'h2': document.execCommand('formatBlock', false, '<h2>'); break;
			case 'h3': document.execCommand('formatBlock', false, '<h3>'); break;
			case 'quote': document.execCommand('formatBlock', false, '<blockquote>'); break;
			case 'ul': document.execCommand('insertUnorderedList'); break;
			case 'ol': document.execCommand('insertOrderedList'); break;
			case 'divider': document.execCommand('insertHTML', false, '<hr><p><br></p>'); break;
			case 'code': document.execCommand('insertHTML', false, '<pre><code>// code</code></pre><p><br></p>'); break;
			case 'image': this.#doImage(); break;
		}
		this.#refreshBlockLabels();
		this.#options.onChange?.(this.getHTML());
	}

	// === Image controls + resize ===
	#showImg(img) {
		this.#activeImg = img;
		const rect = img.getBoundingClientRect();
		const align = img.dataset.align || 'center';

		this.#imgControls.innerHTML = `<div class="kbe-ibar">
			<button type="button" class="kbe-ibtn${align === 'left' ? ' on' : ''}" data-al="left" title="Float left">◧</button>
			<button type="button" class="kbe-ibtn${align === 'center' ? ' on' : ''}" data-al="center" title="Center">◻</button>
			<button type="button" class="kbe-ibtn${align === 'right' ? ' on' : ''}" data-al="right" title="Float right">◨</button>
			<span class="kbe-sep"></span>
			<button type="button" class="kbe-ibtn" data-do="alt" title="Alt text">Alt</button>
			<button type="button" class="kbe-ibtn kbe-idel" data-do="del" title="Remove">✕</button>
		</div>`;

		this.#imgControls.style.left = Math.max(8, rect.left + rect.width / 2 - 120) + 'px';
		this.#imgControls.style.top = (rect.top + window.scrollY - 42) + 'px';
		this.#imgControls.classList.add('vis');

		// Add resize handle to image
		img.classList.add('kbe-img-selected');
		this.#addResizeHandle(img);

		for (const b of this.#imgControls.querySelectorAll('[data-al]')) {
			b.addEventListener('mousedown', (e) => { e.preventDefault(); this.#alignImg(img, b.dataset.al); });
		}
		this.#imgControls.querySelector('[data-do="alt"]')?.addEventListener('mousedown', (e) => {
			e.preventDefault();
			const alt = prompt('Alt text:', img.alt ?? '');
			if (alt !== null) { img.alt = alt; this.#options.onChange?.(this.getHTML()); }
		});
		this.#imgControls.querySelector('[data-do="del"]')?.addEventListener('mousedown', (e) => {
			e.preventDefault();
			(img.closest('figure') ?? img).remove();
			this.#hideImg();
			this.#refreshBlockLabels();
			this.#options.onChange?.(this.getHTML());
		});
	}

	#hideImg() {
		this.#imgControls.classList.remove('vis');
		if (this.#activeImg) this.#activeImg.classList.remove('kbe-img-selected');
		// Remove resize handles
		for (const h of this.#editable.querySelectorAll('.kbe-resize-handle')) h.remove();
		this.#activeImg = null;
	}

	#alignImg(img, align) {
		const fig = img.closest('figure');
		const target = fig ?? img;
		target.className = target.className.replace(/kbe-img-(left|center|right)/g, '').trim();
		target.classList.add(`kbe-img-${align}`);
		img.dataset.align = align;
		this.#showImg(img);
		this.#options.onChange?.(this.getHTML());
	}

	#addResizeHandle(img) {
		for (const old of this.#editable.querySelectorAll('.kbe-resize-handle')) old.remove();
		const handle = document.createElement('div');
		handle.className = 'kbe-resize-handle';
		handle.contentEditable = 'false';
		// Position relative to image
		const fig = img.closest('figure');
		const parent = fig ?? img.parentElement;
		if (parent) {
			parent.style.position = 'relative';
			parent.style.display = 'inline-block';
			parent.appendChild(handle);
		}
		handle.addEventListener('mousedown', (e) => {
			e.preventDefault();
			this.#resizing = true;
			this.#resizeStart = { x: e.clientX, y: e.clientY, w: img.offsetWidth, h: img.offsetHeight };
		});
	}

	#onResizeMove(e) {
		if (!this.#resizing || !this.#activeImg || !this.#resizeStart) return;
		const dx = e.clientX - this.#resizeStart.x;
		const newW = Math.max(60, this.#resizeStart.w + dx);
		const ratio = this.#resizeStart.h / this.#resizeStart.w;
		this.#activeImg.style.width = newW + 'px';
		this.#activeImg.style.height = Math.round(newW * ratio) + 'px';
	}

	#onResizeEnd() {
		if (this.#resizing) {
			this.#resizing = false;
			this.#resizeStart = null;
			this.#options.onChange?.(this.getHTML());
		}
	}

	// === Link ===
	#doLink() {
		const sel = window.getSelection();
		const existing = this.#parentLink();
		if (existing) {
			const u = prompt('Edit URL (empty to remove):', existing.href);
			if (u === null) return;
			if (u === '') { existing.replaceWith(...existing.childNodes); }
			else { existing.href = u; }
		} else {
			const text = sel?.toString() ?? '';
			const u = prompt('URL:', 'https://');
			if (!u || u === 'https://') return;
			if (text) {
				document.execCommand('createLink', false, u);
				const nl = sel?.anchorNode?.parentElement?.closest('a');
				if (nl) { nl.target = '_blank'; nl.rel = 'noopener noreferrer'; }
			} else {
				const lbl = prompt('Link text:', u) ?? u;
				document.execCommand('insertHTML', false, `<a href="${this.#esc(u)}" target="_blank" rel="noopener noreferrer">${this.#esc(lbl)}</a>&nbsp;`);
			}
		}
		this.#options.onChange?.(this.getHTML());
	}

	// === Image insert ===
	#doImage() {
		if (typeof KBMediaSelector !== 'undefined') {
			KBMediaSelector.open((item) => {
				const alt = item.alt_text || item.title || '';
				this.#editable.focus();
				document.execCommand('insertHTML', false, `<figure class="kbe-img-center"><img src="/${item.url}" alt="${this.#esc(alt)}" data-align="center" style="width:100%;height:auto;"></figure><p><br></p>`);
				this.#refreshBlockLabels();
				this.#options.onChange?.(this.getHTML());
			}, 'image');
			return;
		}
		const u = prompt('Image URL:', 'https://');
		if (!u || u === 'https://') return;
		const alt = prompt('Alt text:', '') ?? '';
		document.execCommand('insertHTML', false, `<figure class="kbe-img-center"><img src="${this.#esc(u)}" alt="${this.#esc(alt)}" data-align="center" style="width:100%;height:auto;"></figure><p><br></p>`);
		this.#refreshBlockLabels();
		this.#options.onChange?.(this.getHTML());
	}

	// === Shortcuts ===
	#shortcuts(e) {
		if (e.ctrlKey || e.metaKey) {
			switch (e.key) {
				case 'b': e.preventDefault(); document.execCommand('bold'); break;
				case 'i': e.preventDefault(); document.execCommand('italic'); break;
				case 'k': e.preventDefault(); this.#doLink(); break;
				default: return;
			}
			this.#options.onChange?.(this.getHTML());
		}
	}

	// === Helpers ===
	#block() {
		const sel = window.getSelection();
		if (!sel?.rangeCount) return null;
		let n = sel.anchorNode;
		while (n && n !== this.#editable) {
			if (n.nodeType === Node.ELEMENT_NODE) {
				const d = window.getComputedStyle(n).display;
				if (d === 'block' || d === 'list-item') return n;
			}
			n = n.parentNode;
		}
		return null;
	}

	#parentLink() { return window.getSelection()?.anchorNode?.parentElement?.closest('a') ?? null; }

	#wrapOrphans() {
		for (const n of [...this.#editable.childNodes]) {
			if (n.nodeType === Node.TEXT_NODE && n.textContent.trim()) {
				const p = document.createElement('p');
				n.replaceWith(p); p.appendChild(n);
				const r = document.createRange(); r.selectNodeContents(p); r.collapse(false);
				window.getSelection().removeAllRanges(); window.getSelection().addRange(r);
			}
		}
	}

	#cleanEmptyLists() {
		for (const list of this.#editable.querySelectorAll('ul, ol')) {
			if (!list.querySelector('li') || (list.querySelectorAll('li').length === 1 && !list.querySelector('li').textContent.trim() && !list.querySelector('li').querySelector('br'))) {
				// Don't auto-remove, let backspace handle it
			}
		}
	}

	#updatePlaceholder() { this.#editable.classList.toggle('kbe-empty', this.isEmpty()); }

	#cleanPaste(html) {
		const d = document.createElement('div'); d.innerHTML = html;
		for (const el of d.querySelectorAll('script,style,meta,link')) el.remove();
		const allow = ['href', 'src', 'alt', 'target', 'rel'];
		for (const el of d.querySelectorAll('*')) { for (const a of [...el.attributes]) { if (!allow.includes(a.name) && !a.name.startsWith('data-')) el.removeAttribute(a.name); } }
		for (const el of d.querySelectorAll('div')) { const p = document.createElement('p'); p.innerHTML = el.innerHTML; el.replaceWith(p); }
		for (const el of d.querySelectorAll('span')) el.replaceWith(...el.childNodes);
		return d.innerHTML;
	}

	#esc(s) { return (s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

	// === Public API ===
	getHTML() {
		// Return clean HTML without block labels
		const clone = this.#editable.cloneNode(true);
		for (const lbl of clone.querySelectorAll('.kbe-block-label')) lbl.remove();
		for (const h of clone.querySelectorAll('.kbe-resize-handle')) h.remove();
		for (const el of clone.querySelectorAll('.kbe-img-selected')) el.classList.remove('kbe-img-selected');
		return clone.innerHTML;
	}
	setHTML(html) { this.#editable.innerHTML = html; this.#updatePlaceholder(); this.#refreshBlockLabels(); }
	focus() { this.#editable.focus(); }
	isEmpty() { const t = this.#editable.textContent?.trim() ?? ''; const h = this.#editable.innerHTML?.trim() ?? ''; return !t || !h || h === '<br>' || h === '<p><br></p>'; }
	getWordCount() { const t = this.#editable.textContent?.trim() ?? ''; return t ? t.split(/\s+/).length : 0; }
	getEditable() { return this.#editable; }
	destroy() { this.#floatingBar.remove(); this.#slashMenu.remove(); this.#imgControls.remove(); this.#editable.remove(); this.#container.classList.remove('kbe', 'kbe-on'); }
}

window.KBEditor = KBEditor;