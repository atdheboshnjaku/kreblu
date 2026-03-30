<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class MenuController extends BaseController
{
	public function index(AdminLayout $layout): string
	{
		$layout->setTitle('Menus');
		$layout->setActivePage('menus');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);

		// AJAX endpoints
		$action = $this->request->query('action', '');
		if ($action !== '') {
			header('Content-Type: application/json');
			return match ($action) {
				'save_items'  => $this->saveItems(),
				'delete_item' => $this->deleteItem(),
				'add_items'   => $this->addItems(),
				default       => json_encode(['error' => 'Unknown action']),
			};
		}

		// Handle create new menu
		if ($this->request->method() === 'POST' && $this->request->input('_action') === 'create_menu') {
			$name = trim($this->request->input('menu_name', ''));
			if ($name !== '') {
				$slug = $this->slugify($name);
				$existing = $db->table('menus')->where('slug', '=', $slug)->first();
				if ($existing) { $slug .= '-' . time(); }
				$newId = $db->table('menus')->insert(['name' => $name, 'slug' => $slug]);
				header('Location: /kb-admin/menus?menu_id=' . $newId);
				exit;
			}
		}

		// Handle delete menu
		if ($this->request->method() === 'POST' && $this->request->input('_action') === 'delete_menu') {
			$deleteId = (int) $this->request->input('menu_id', '0');
			if ($deleteId > 0) {
				$db->table('menu_items')->where('menu_id', '=', $deleteId)->delete();
				$db->table('menus')->where('id', '=', $deleteId)->delete();
			}
			header('Location: /kb-admin/menus');
			exit;
		}

		// Handle save menu settings
		if ($this->request->method() === 'POST' && $this->request->input('_action') === 'save_menu_settings') {
			$menuId = (int) $this->request->input('menu_id', '0');
			$menuName = trim($this->request->input('menu_name', ''));
			$menuLocation = trim($this->request->input('menu_location', ''));
			if ($menuId > 0 && $menuName !== '') {
				$db->table('menus')->where('id', '=', $menuId)->update([
					'name' => $menuName, 'location' => $menuLocation !== '' ? $menuLocation : null,
				]);
				$layout->addNotice('success', 'Menu saved.');
			}
		}

		// Get all menus
		$menus = $db->table('menus')->orderBy('name', 'ASC')->get();
		$selectedMenuId = (int) $this->request->query('menu_id', '0');
		if ($selectedMenuId === 0 && !empty($menus)) { $selectedMenuId = (int) $menus[0]->id; }

		$selectedMenu = null;
		$menuItems = [];
		if ($selectedMenuId > 0) {
			$selectedMenu = $db->table('menus')->where('id', '=', $selectedMenuId)->first();
			if ($selectedMenu) {
				$menuItems = $db->table('menu_items')->where('menu_id', '=', $selectedMenuId)->orderBy('sort_order', 'ASC')->get();
			}
		}

		$menuOptions = '';
		foreach ($menus as $menu) {
			$sel = (int) $menu->id === $selectedMenuId ? ' selected' : '';
			$menuOptions .= '<option value="' . $menu->id . '"' . $sel . '>' . $e($menu->name) . '</option>';
		}

		// Render menu items
		$itemsHtml = '';
		if (!empty($menuItems)) {
			foreach ($menuItems as $item) {
				$depth = 0;
				if ($item->parent_id) {
					$parentId = $item->parent_id;
					$d = 0;
					while ($parentId && $d < 3) {
						foreach ($menuItems as $p) {
							if ((int) $p->id === (int) $parentId) { $parentId = $p->parent_id; $d++; break; }
						}
						if (!$parentId) break;
					}
					$depth = $d;
				}
				$indent = 16 + $depth * 28;
				$typeLabel = match ($item->type) { 'page' => 'Page', 'post' => 'Post', 'category' => 'Category', default => 'Custom link' };
				$itemsHtml .= '<div class="kb-sortable-item" data-id="' . $item->id . '" data-depth="' . $depth . '" style="padding-left:' . $indent . 'px;">';
				$itemsHtml .= '<span class="kb-drag-handle"><svg viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.5"/><circle cx="11" cy="4" r="1.5"/><circle cx="5" cy="8" r="1.5"/><circle cx="11" cy="8" r="1.5"/><circle cx="5" cy="12" r="1.5"/><circle cx="11" cy="12" r="1.5"/></svg></span>';
				$itemsHtml .= '<div class="kb-menu-item-content"><span class="kb-menu-item-label">' . $e($item->label) . '</span><span class="kb-menu-item-type"> &mdash; ' . $e($typeLabel) . '</span></div>';
				$itemsHtml .= '<div class="kb-menu-item-actions"><button type="button" onclick="kbMenuIndent(' . $item->id . ')" title="Indent">→</button><button type="button" onclick="kbMenuOutdent(' . $item->id . ')" title="Outdent">←</button><button type="button" onclick="kbMenuEditItem(' . $item->id . ')" title="Edit">Edit</button><button type="button" class="delete" onclick="kbMenuDeleteItem(' . $item->id . ')" title="Remove">×</button></div></div>';
			}
		} else if ($selectedMenu) {
			$itemsHtml = '<div class="kb-empty" style="padding:30px;"><p>This menu is empty. Add items from the panels on the left.</p></div>';
		}

		// Add-to-menu panels data
		$pages = $db->table('posts')->where('type', '=', 'page')->where('status', '=', 'published')->orderBy('title', 'ASC')->get();
		$pagesCheckboxes = '';
		foreach ($pages as $page) { $pagesCheckboxes .= '<label><input type="checkbox" value="' . $page->id . '" data-label="' . $e($page->title) . '" data-url="/' . $e($page->slug) . '"> ' . $e($page->title) . '</label>'; }

		$postsList = $db->table('posts')->where('type', '=', 'post')->where('status', '=', 'published')->orderBy('title', 'ASC')->limit(20)->get();
		$postsCheckboxes = '';
		foreach ($postsList as $post) { $postsCheckboxes .= '<label><input type="checkbox" value="' . $post->id . '" data-label="' . $e($post->title) . '" data-url="/' . $e($post->slug) . '"> ' . $e($post->title) . '</label>'; }

		$cats = $db->table('terms')->where('taxonomy', '=', 'category')->orderBy('name', 'ASC')->get();
		$catsCheckboxes = '';
		foreach ($cats as $cat) { $catsCheckboxes .= '<label><input type="checkbox" value="' . $cat->id . '" data-label="' . $e($cat->name) . '" data-url="/category/' . $e($cat->slug) . '"> ' . $e($cat->name) . '</label>'; }

		$locations = ['primary' => 'Primary navigation', 'footer' => 'Footer navigation'];
		$locationOptions = '<option value="">— None —</option>';
		foreach ($locations as $loc => $label) {
			$sel = ($selectedMenu && $selectedMenu->location === $loc) ? ' selected' : '';
			$locationOptions .= '<option value="' . $e($loc) . '"' . $sel . '>' . $e($label) . '</option>';
		}

		$menuSettingsHtml = '';
		$menuContentHtml = '';

		if ($selectedMenu) {
			$menuSettingsHtml = '<form method="POST" action="/kb-admin/menus?menu_id=' . $selectedMenuId . '"><input type="hidden" name="_action" value="save_menu_settings"><input type="hidden" name="menu_id" value="' . $selectedMenuId . '"><div style="display:flex;gap:10px;align-items:end;margin-bottom:16px;flex-wrap:wrap;"><div class="kb-form-group" style="margin-bottom:0;"><label class="kb-label">Menu name</label><input type="text" name="menu_name" class="kb-input" value="' . $e($selectedMenu->name) . '" style="max-width:240px;"></div><div class="kb-form-group" style="margin-bottom:0;"><label class="kb-label">Location</label><select name="menu_location" class="kb-select" style="max-width:220px;">' . $locationOptions . '</select></div><button type="submit" class="kb-btn kb-btn-primary kb-btn-sm">Save menu</button></div></form>';

			$menuContentHtml = '<div id="kb-menu-items-list">' . $itemsHtml . '</div><div style="margin-top:12px;display:flex;gap:8px;justify-content:space-between;"><span class="kb-menu-unsaved" id="kb-menu-unsaved" style="display:none;">Unsaved order changes</span><div style="display:flex;gap:8px;"><button type="button" class="kb-btn kb-btn-primary kb-btn-sm" id="kb-menu-save-order" style="display:none;" onclick="kbMenuSaveOrder()">Save order</button><form method="POST" action="/kb-admin/menus?menu_id=' . $selectedMenuId . '" style="display:inline;" onsubmit="return window.confirm(\'Delete this menu and all its items?\');"><input type="hidden" name="_action" value="delete_menu"><input type="hidden" name="menu_id" value="' . $selectedMenuId . '"><button type="submit" class="kb-btn kb-btn-danger kb-btn-sm">Delete menu</button></form></div></div>';
		} else {
			$menuContentHtml = '<div class="kb-empty"><h3>No menu selected</h3><p>Create a menu to get started.</p></div>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Menus</h2></div>
		<div class="kb-menu-selector"><select class="kb-select" id="kb-menu-select" onchange="window.location='/kb-admin/menus?menu_id='+this.value" style="max-width:260px;">{$menuOptions}</select><span style="color:var(--kb-text-hint);font-size:13px;">or</span><form method="POST" action="/kb-admin/menus" style="display:flex;gap:6px;align-items:center;"><input type="hidden" name="_action" value="create_menu"><input type="text" name="menu_name" class="kb-input" placeholder="New menu name..." style="max-width:200px;" required><button type="submit" class="kb-btn kb-btn-primary kb-btn-sm">Create</button></form></div>
		{$menuSettingsHtml}
		<div class="kb-grid-2" style="grid-template-columns:280px 1fr;">
			<div class="kb-stack">
				<div class="kb-menu-add-panel"><div class="kb-menu-add-header" onclick="this.nextElementSibling.classList.toggle('open')">Pages <span>▸</span></div><div class="kb-menu-add-body"><div class="kb-menu-add-list" id="kb-add-pages">{$pagesCheckboxes}</div><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" onclick="kbMenuAddChecked('kb-add-pages', 'page')">Add to menu</button></div></div>
				<div class="kb-menu-add-panel"><div class="kb-menu-add-header" onclick="this.nextElementSibling.classList.toggle('open')">Posts <span>▸</span></div><div class="kb-menu-add-body"><div class="kb-menu-add-list" id="kb-add-posts">{$postsCheckboxes}</div><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" onclick="kbMenuAddChecked('kb-add-posts', 'post')">Add to menu</button></div></div>
				<div class="kb-menu-add-panel"><div class="kb-menu-add-header" onclick="this.nextElementSibling.classList.toggle('open')">Categories <span>▸</span></div><div class="kb-menu-add-body"><div class="kb-menu-add-list" id="kb-add-cats">{$catsCheckboxes}</div><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" onclick="kbMenuAddChecked('kb-add-cats', 'category')">Add to menu</button></div></div>
				<div class="kb-menu-add-panel"><div class="kb-menu-add-header" onclick="this.nextElementSibling.classList.toggle('open')">Custom link <span>▸</span></div><div class="kb-menu-add-body open"><div class="kb-form-group"><label class="kb-label">URL</label><input type="url" id="kb-custom-url" class="kb-input" placeholder="https://"></div><div class="kb-form-group"><label class="kb-label">Label</label><input type="text" id="kb-custom-label" class="kb-input" placeholder="Link text"></div><button type="button" class="kb-btn kb-btn-outline kb-btn-sm" onclick="kbMenuAddCustom()">Add to menu</button></div></div>
			</div>
			<div><div class="kb-card"><div class="kb-card-header"><h3>Menu structure</h3></div><div class="kb-card-body" style="min-height:100px;">{$menuContentHtml}</div></div></div>
		</div>
		<script src="/kb-admin/assets/js/kreblu.js"></script>
		<script>
		const MENU_ID={$selectedMenuId};let menuSortable=null;let menuDirty=false;
		document.addEventListener('DOMContentLoaded',()=>{const list=document.getElementById('kb-menu-items-list');if(!list||!MENU_ID)return;menuSortable=new KB.Sortable(list,{itemSelector:'.kb-sortable-item',handleSelector:'.kb-drag-handle',nestable:true,maxDepth:2,onReorder:()=>{menuDirty=true;document.getElementById('kb-menu-unsaved').style.display='';document.getElementById('kb-menu-save-order').style.display='';}});menuSortable.refreshIndents();});
		function kbMenuIndent(id){const el=document.querySelector('[data-id="'+id+'"]');if(el&&menuSortable){menuSortable.indent(el);menuDirty=true;document.getElementById('kb-menu-unsaved').style.display='';document.getElementById('kb-menu-save-order').style.display='';}}
		function kbMenuOutdent(id){const el=document.querySelector('[data-id="'+id+'"]');if(el&&menuSortable){menuSortable.outdent(el);menuDirty=true;document.getElementById('kb-menu-unsaved').style.display='';document.getElementById('kb-menu-save-order').style.display='';}}
		async function kbMenuSaveOrder(){const items=[...document.querySelectorAll('#kb-menu-items-list .kb-sortable-item')].map((el,i)=>({id:parseInt(el.dataset.id),sort_order:i,depth:parseInt(el.dataset.depth??'0')}));try{const res=await fetch('/kb-admin/menus?action=save_items',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({menu_id:MENU_ID,items})});const data=await res.json();if(data.success){KB.toast('success','Menu order saved.');menuDirty=false;document.getElementById('kb-menu-unsaved').style.display='none';document.getElementById('kb-menu-save-order').style.display='none';}else{KB.toast('error',data.error??'Failed to save.');}}catch{KB.toast('error','Network error.');}}
		async function kbMenuDeleteItem(id){if(!window.confirm('Remove this menu item?'))return;try{const res=await fetch('/kb-admin/menus?action=delete_item',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});const data=await res.json();if(data.success){document.querySelector('[data-id="'+id+'"]')?.remove();KB.toast('success','Item removed.');}}catch{KB.toast('error','Network error.');}}
		function kbMenuEditItem(id){const el=document.querySelector('[data-id="'+id+'"]');if(!el)return;const labelEl=el.querySelector('.kb-menu-item-label');const current=labelEl.textContent;const newLabel=prompt('Edit label:',current);if(newLabel&&newLabel!==current){labelEl.textContent=newLabel;menuDirty=true;document.getElementById('kb-menu-unsaved').style.display='';document.getElementById('kb-menu-save-order').style.display='';}}
		async function kbMenuAddChecked(containerId,type){if(!MENU_ID)return;const container=document.getElementById(containerId);const checked=[...container.querySelectorAll('input[type="checkbox"]:checked')];if(!checked.length)return;const items=checked.map(cb=>({type,object_id:parseInt(cb.value),label:cb.dataset.label,url:cb.dataset.url}));try{const res=await fetch('/kb-admin/menus?action=add_items',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({menu_id:MENU_ID,items})});const data=await res.json();if(data.success)window.location.reload();}catch{KB.toast('error','Network error.');}checked.forEach(cb=>cb.checked=false);}
		async function kbMenuAddCustom(){if(!MENU_ID)return;const url=document.getElementById('kb-custom-url')?.value.trim();const label=document.getElementById('kb-custom-label')?.value.trim();if(!url||!label){KB.toast('error','URL and label are required.');return;}try{const res=await fetch('/kb-admin/menus?action=add_items',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({menu_id:MENU_ID,items:[{type:'custom',label,url}]})});const data=await res.json();if(data.success)window.location.reload();}catch{KB.toast('error','Network error.');}}
		window.addEventListener('beforeunload',(e)=>{if(menuDirty){e.preventDefault();e.returnValue='';}});
		</script>
HTML;

		return $layout->render($content);
	}

	private function saveItems(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		$input = json_decode(file_get_contents('php://input'), true);
		$menuId = (int) ($input['menu_id'] ?? 0);
		$items = $input['items'] ?? [];
		if (!$menuId || empty($items)) return json_encode(['error' => 'Invalid data']);

		$db = $this->app->db();
		foreach ($items as $i => $item) {
			$id = (int) $item['id'];
			$depth = (int) $item['depth'];
			$parentId = null;
			if ($depth > 0) {
				for ($j = $i - 1; $j >= 0; $j--) {
					if ((int) $items[$j]['depth'] === $depth - 1) { $parentId = (int) $items[$j]['id']; break; }
				}
			}
			$updateData = ['sort_order' => (int) $item['sort_order'], 'parent_id' => $parentId];
			if (isset($item['label'])) { $updateData['label'] = $item['label']; }
			$db->table('menu_items')->where('id', '=', $id)->update($updateData);
		}
		return json_encode(['success' => true]);
	}

	private function deleteItem(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		$input = json_decode(file_get_contents('php://input'), true);
		$id = (int) ($input['id'] ?? 0);
		if (!$id) return json_encode(['error' => 'Invalid ID']);
		$db = $this->app->db();
		$db->table('menu_items')->where('parent_id', '=', $id)->update(['parent_id' => null]);
		$db->table('menu_items')->where('id', '=', $id)->delete();
		return json_encode(['success' => true]);
	}

	private function addItems(): string
	{
		if ($this->request->method() !== 'POST') return json_encode(['error' => 'Method not allowed']);
		$input = json_decode(file_get_contents('php://input'), true);
		$menuId = (int) ($input['menu_id'] ?? 0);
		$items = $input['items'] ?? [];
		if (!$menuId || empty($items)) return json_encode(['error' => 'Invalid data']);

		$db = $this->app->db();
		$maxOrder = 0;
		$last = $db->table('menu_items')->where('menu_id', '=', $menuId)->orderBy('sort_order', 'DESC')->first();
		if ($last) { $maxOrder = (int) $last->sort_order + 1; }

		foreach ($items as $item) {
			$db->table('menu_items')->insert([
				'menu_id' => $menuId, 'type' => $item['type'] ?? 'custom', 'label' => $item['label'] ?? 'Menu item',
				'url' => $item['url'] ?? '#', 'object_type' => in_array($item['type'] ?? '', ['page', 'post', 'category']) ? $item['type'] : null,
				'object_id' => isset($item['object_id']) ? (int) $item['object_id'] : null, 'sort_order' => $maxOrder++,
			]);
		}
		return json_encode(['success' => true]);
	}
}
