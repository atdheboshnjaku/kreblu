/**
 * Kreblu Admin — Shared Utilities
 *
 * Provides reusable components for the admin panel:
 *   - KB.toast(type, msg)      — toast notifications
 *   - KB.csrf()                — get CSRF token
 *   - KB.confirm(msg)          — promise-based confirm dialog
 *   - KB.slugify(text)         — URL-safe slug
 *   - KB.debounce(fn, ms)      — debounce wrapper
 *   - KB.Sortable              — drag-and-drop reorder for lists
 */

const KB = (() => {
	'use strict';

	// =================================================================
	// CSRF
	// =================================================================
	const csrf = () =>
		document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
		?? document.querySelector('input[name="_nonce"]')?.value
		?? '';

	// =================================================================
	// Toast notifications
	// =================================================================
	const toast = (type, msg, duration = 3000) => {
		document.querySelector('.kb-toast')?.remove();
		const el = Object.assign(document.createElement('div'), {
			className: `kb-toast kb-toast-${type}`,
			innerHTML: msg,
		});
		document.body.appendChild(el);
		requestAnimationFrame(() => el.classList.add('show'));
		setTimeout(() => {
			el.classList.remove('show');
			setTimeout(() => el.remove(), 300);
		}, duration);
	};

	// =================================================================
	// Confirm (promise-based)
	// =================================================================
	const confirm = (msg) => new Promise((resolve) => {
		resolve(window.confirm(msg));
	});

	// =================================================================
	// Slugify
	// =================================================================
	const slugify = (text) =>
		text.toLowerCase().trim()
			.replace(/[^\w\s-]/g, '')
			.replace(/[\s_]+/g, '-')
			.replace(/-+/g, '-')
			.replace(/^-+|-+$/g, '');

	// =================================================================
	// Debounce
	// =================================================================
	const debounce = (fn, ms = 300) => {
		let timer;
		return (...args) => {
			clearTimeout(timer);
			timer = setTimeout(() => fn(...args), ms);
		};
	};

	// =================================================================
	// Sortable — Drag-and-drop reorder for lists
	//
	// Usage:
	//   new KB.Sortable(containerEl, {
	//     itemSelector: '.kb-sortable-item',
	//     handleSelector: '.kb-drag-handle',    // optional, defaults to whole item
	//     nestable: false,                       // allow nesting (indent/outdent)
	//     maxDepth: 2,                           // max nesting depth
	//     onReorder: (items) => { ... },         // callback with ordered items array
	//   });
	//
	// Each item needs: data-id="<id>"
	// Nested items get data-depth="0|1|2" and a visual indent.
	// =================================================================
	class Sortable {
		#container;
		#options;
		#dragItem = null;
		#placeholder = null;
		#dragOffsetY = 0;

		constructor(container, options = {}) {
			this.#container = container;
			this.#options = {
				itemSelector: '.kb-sortable-item',
				handleSelector: null,
				nestable: false,
				maxDepth: 2,
				onReorder: null,
				...options,
			};

			this.#bindEvents();
		}

		#bindEvents() {
			this.#container.addEventListener('mousedown', (e) => this.#onMouseDown(e));
			this.#container.addEventListener('touchstart', (e) => this.#onTouchStart(e), { passive: false });
		}

		#getItem(target) {
			return target.closest(this.#options.itemSelector);
		}

		#isHandle(target) {
			if (!this.#options.handleSelector) return true;
			return target.closest(this.#options.handleSelector) !== null;
		}

		#onMouseDown(e) {
			const item = this.#getItem(e.target);
			if (!item || !this.#isHandle(e.target)) return;
			e.preventDefault();
			this.#startDrag(item, e.clientY);

			const onMove = (ev) => this.#onDrag(ev.clientY);
			const onUp = () => {
				document.removeEventListener('mousemove', onMove);
				document.removeEventListener('mouseup', onUp);
				this.#endDrag();
			};
			document.addEventListener('mousemove', onMove);
			document.addEventListener('mouseup', onUp);
		}

		#onTouchStart(e) {
			const item = this.#getItem(e.target);
			if (!item || !this.#isHandle(e.target)) return;

			const touch = e.touches[0];
			let moved = false;

			const onMove = (ev) => {
				if (!moved) {
					e.preventDefault();
					this.#startDrag(item, touch.clientY);
					moved = true;
				}
				this.#onDrag(ev.touches[0].clientY);
			};
			const onEnd = () => {
				document.removeEventListener('touchmove', onMove);
				document.removeEventListener('touchend', onEnd);
				if (moved) this.#endDrag();
			};
			document.addEventListener('touchmove', onMove, { passive: false });
			document.addEventListener('touchend', onEnd);
		}

		#startDrag(item, clientY) {
			this.#dragItem = item;
			const rect = item.getBoundingClientRect();
			this.#dragOffsetY = clientY - rect.top;

			// Create placeholder
			this.#placeholder = document.createElement('div');
			this.#placeholder.className = 'kb-sortable-placeholder';
			this.#placeholder.style.height = rect.height + 'px';

			// Style dragged item
			item.classList.add('kb-sortable-dragging');
			item.style.width = rect.width + 'px';
			item.style.position = 'fixed';
			item.style.zIndex = '9999';
			item.style.left = rect.left + 'px';
			item.style.top = (clientY - this.#dragOffsetY) + 'px';
			item.style.pointerEvents = 'none';

			// Insert placeholder
			item.parentNode.insertBefore(this.#placeholder, item);
			document.body.appendChild(item);
		}

		#onDrag(clientY) {
			if (!this.#dragItem) return;

			this.#dragItem.style.top = (clientY - this.#dragOffsetY) + 'px';

			// Find the item we're hovering over
			const items = [...this.#container.querySelectorAll(this.#options.itemSelector)]
				.filter((el) => el !== this.#dragItem);

			for (const item of items) {
				const rect = item.getBoundingClientRect();
				const midY = rect.top + rect.height / 2;

				if (clientY < midY) {
					this.#container.insertBefore(this.#placeholder, item);
					return;
				}
			}

			// Past all items — append at end
			if (this.#placeholder.parentNode === this.#container) {
				this.#container.appendChild(this.#placeholder);
			}
		}

		#endDrag() {
			if (!this.#dragItem || !this.#placeholder) return;

			// Reset dragged item styles
			this.#dragItem.classList.remove('kb-sortable-dragging');
			this.#dragItem.style.cssText = '';

			// Insert item where placeholder is
			this.#placeholder.parentNode.insertBefore(this.#dragItem, this.#placeholder);
			this.#placeholder.remove();

			this.#dragItem = null;
			this.#placeholder = null;

			// Notify callback
			if (this.#options.onReorder) {
				const items = [...this.#container.querySelectorAll(this.#options.itemSelector)];
				const ordered = items.map((el, i) => ({
					id: parseInt(el.dataset.id, 10),
					sort_order: i,
					depth: parseInt(el.dataset.depth ?? '0', 10),
				}));
				this.#options.onReorder(ordered);
			}
		}

		/**
		 * Update nesting depth on an item (for nestable menus).
		 */
		indent(itemEl) {
			const current = parseInt(itemEl.dataset.depth ?? '0', 10);
			if (current < this.#options.maxDepth) {
				itemEl.dataset.depth = current + 1;
				this.#applyIndent(itemEl);
				this.#fireReorder();
			}
		}

		outdent(itemEl) {
			const current = parseInt(itemEl.dataset.depth ?? '0', 10);
			if (current > 0) {
				itemEl.dataset.depth = current - 1;
				this.#applyIndent(itemEl);
				this.#fireReorder();
			}
		}

		#applyIndent(el) {
			const depth = parseInt(el.dataset.depth ?? '0', 10);
			el.style.paddingLeft = (16 + depth * 28) + 'px';
		}

		#fireReorder() {
			if (!this.#options.onReorder) return;
			const items = [...this.#container.querySelectorAll(this.#options.itemSelector)];
			const ordered = items.map((el, i) => ({
				id: parseInt(el.dataset.id, 10),
				sort_order: i,
				depth: parseInt(el.dataset.depth ?? '0', 10),
			}));
			this.#options.onReorder(ordered);
		}

		/**
		 * Apply visual indents to all items based on their data-depth.
		 */
		refreshIndents() {
			const items = this.#container.querySelectorAll(this.#options.itemSelector);
			for (const el of items) {
				this.#applyIndent(el);
			}
		}
	}

	// =================================================================
	// Public API
	// =================================================================
	return { csrf, toast, confirm, slugify, debounce, Sortable };
})();

// Make globally available
window.KB = KB;