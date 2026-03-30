<?php declare(strict_types=1);

namespace Kreblu\Admin;

final class UserController extends BaseController
{
	public function list(AdminLayout $layout): string
	{
		$layout->setTitle('Users');
		$layout->setActivePage('users');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);

		$roleFilter = $this->request->query('role', '');
		$search = $this->request->query('search', '');

		$query = $db->table('users');
		if ($roleFilter !== '' && \Kreblu\Core\Auth\RoleManager::isValidRole($roleFilter)) {
			$query->where('role', '=', $roleFilter);
		}
		if ($search !== '') {
			$query->where('username', 'LIKE', '%' . $search . '%');
		}
		$users = $query->orderBy('created_at', 'DESC')->get();

		$allCount = $db->table('users')->count();
		$roleCounts = [];
		foreach (\Kreblu\Core\Auth\RoleManager::getAllRoles() as $role) {
			$roleCounts[$role] = $db->table('users')->where('role', '=', $role)->count();
		}

		$tabs = '<div style="display:flex;gap:16px;margin-bottom:16px;font-size:13px;">';
		$tabs .= '<a href="/kb-admin/users" style="' . ($roleFilter === '' ? 'font-weight:700;color:var(--kb-text);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">All (' . $allCount . ')</a>';
		foreach ($roleCounts as $role => $count) {
			if ($count === 0) continue;
			$label = \Kreblu\Core\Auth\RoleManager::getRoleLabel($role);
			$tabs .= '<a href="/kb-admin/users?role=' . $e($role) . '" style="' . ($roleFilter === $role ? 'font-weight:700;color:var(--kb-text);' : 'color:var(--kb-text-secondary);') . 'text-decoration:none;">' . $e($label) . ' (' . $count . ')</a>';
		}
		$tabs .= '</div>';

		$searchHtml = '<div style="margin-bottom:16px;"><form method="GET" action="/kb-admin/users" style="display:flex;gap:8px;align-items:center;">';
		if ($roleFilter !== '') { $searchHtml .= '<input type="hidden" name="role" value="' . $e($roleFilter) . '">'; }
		$searchHtml .= '<input type="text" name="search" class="kb-input" placeholder="Search users..." value="' . $e($search) . '" style="max-width:240px;">';
		$searchHtml .= '<button type="submit" class="kb-btn kb-btn-outline kb-btn-sm">Search</button>';
		if ($search !== '') { $searchHtml .= ' <a href="/kb-admin/users" style="font-size:12px;color:var(--kb-text-secondary);">Clear</a>'; }
		$searchHtml .= '</form></div>';

		$currentUserId = (int) $this->app->auth()->currentUser()->id;
		$rows = '';
		foreach ($users as $user) {
			$roleLabel = \Kreblu\Core\Auth\RoleManager::getRoleLabel($user->role);
			$actions = '<a href="/kb-admin/users/edit/' . $user->id . '">Edit</a>';
			if ((int) $user->id !== $currentUserId) {
				$actions .= ' <a href="/kb-admin/users/delete/' . $user->id . '" class="delete" onclick="return confirm(\'Delete user ' . $e($user->username) . '? This cannot be undone.\')">Delete</a>';
			}
			$rows .= '<tr>';
			$rows .= '<td><a href="/kb-admin/users/edit/' . $user->id . '" style="font-weight:700;color:var(--kb-text);text-decoration:none;">' . $e($user->username) . '</a>';
			if ($user->display_name && $user->display_name !== $user->username) {
				$rows .= '<br><span style="font-size:12px;color:var(--kb-text-hint);">' . $e($user->display_name) . '</span>';
			}
			$rows .= '</td>';
			$rows .= '<td>' . $e($user->email) . '</td>';
			$rows .= '<td><span class="kb-badge kb-badge-' . $e($user->role) . '">' . $e($roleLabel) . '</span></td>';
			$rows .= '<td style="color:var(--kb-text-hint);font-size:12px;">' . $e($user->created_at) . '</td>';
			$rows .= '<td class="kb-table-actions">' . $actions . '</td>';
			$rows .= '</tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="5"><div class="kb-empty"><h3>No users found</h3></div></td></tr>';
		}

		$content = <<<HTML
		<div class="kb-content-header"><h2>Users</h2><a href="/kb-admin/users/new" class="kb-btn kb-btn-primary">+ Add user</a></div>
		{$tabs}
		{$searchHtml}
		<div class="kb-card">
			<table class="kb-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th></th></tr></thead><tbody>{$rows}</tbody></table>
		</div>
HTML;

		return $layout->render($content);
	}

	public function editor(AdminLayout $layout, ?int $id = null): string
	{
		$layout->setTitle($id ? 'Edit user' : 'Add user');
		$layout->setActivePage('users');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);
		$users = new \Kreblu\Core\Auth\UserManager($db, $this->app->auth());

		$user = null;
		if ($id) {
			$user = $users->findById($id);
			if (!$user) {
				$layout->addNotice('error', 'User not found.');
				return $this->list($layout);
			}
		}

		if ($this->request->method() === 'POST') {
			try {
				if ($id) {
					$updateData = [
						'email' => $this->request->input('email', ''), 'username' => $this->request->input('username', ''),
						'display_name' => $this->request->input('display_name', ''), 'role' => $this->request->input('role', 'subscriber'),
					];
					$newPassword = $this->request->input('password', '');
					if ($newPassword !== '') { $updateData['password'] = $newPassword; }
					$users->update($id, $updateData);
					$user = $users->findById($id);
					$layout->addNotice('success', 'User updated.');
				} else {
					$newId = $users->create([
						'email' => $this->request->input('email', ''), 'username' => $this->request->input('username', ''),
						'password' => $this->request->input('password', ''), 'display_name' => $this->request->input('display_name', ''),
						'role' => $this->request->input('role', 'subscriber'),
					]);
					header('Location: /kb-admin/users/edit/' . $newId);
					exit;
				}
			} catch (\Throwable $ex) { $layout->addNotice('error', $ex->getMessage()); }
		}

		$username = $e($user->username ?? '');
		$email = $e($user->email ?? '');
		$displayName = $e($user->display_name ?? '');
		$role = $user->role ?? 'subscriber';
		$actionUrl = $id ? '/kb-admin/users/edit/' . $id : '/kb-admin/users/new';

		$roleOptions = '';
		foreach (\Kreblu\Core\Auth\RoleManager::getAllRoles() as $r) {
			$label = \Kreblu\Core\Auth\RoleManager::getRoleLabel($r);
			$sel = $role === $r ? ' selected' : '';
			$roleOptions .= '<option value="' . $e($r) . '"' . $sel . '>' . $e($label) . '</option>';
		}

		$passwordLabel = $id ? 'New password <span style="font-weight:400;color:var(--kb-text-hint);">(leave blank to keep current)</span>' : 'Password';
		$passwordRequired = $id ? '' : ' required';

		$content = <<<HTML
		<div style="margin-bottom:16px;"><a href="/kb-admin/users" style="font-size:12px;color:var(--kb-text-secondary);">&larr; Back to users</a></div>
		<form method="POST" action="{$actionUrl}">
			<div class="kb-grid-2">
				<div class="kb-stack">
					<div class="kb-card">
						<div class="kb-card-header"><h3>Account details</h3></div>
						<div class="kb-card-body">
							<div class="kb-form-group"><label class="kb-label">Username</label><input type="text" name="username" class="kb-input" value="{$username}" required minlength="3" pattern="[a-zA-Z0-9_.\-]+"></div>
							<div class="kb-form-group"><label class="kb-label">Email</label><input type="email" name="email" class="kb-input" value="{$email}" required></div>
							<div class="kb-form-group"><label class="kb-label">Display name</label><input type="text" name="display_name" class="kb-input" value="{$displayName}" placeholder="How their name appears publicly"></div>
							<div class="kb-form-group"><label class="kb-label">{$passwordLabel}</label><input type="password" name="password" class="kb-input" minlength="8"{$passwordRequired} placeholder="Minimum 8 characters"></div>
						</div>
					</div>
				</div>
				<div class="kb-stack">
					<div class="kb-card">
						<div class="kb-card-header"><h3>Role</h3></div>
						<div class="kb-card-body">
							<div class="kb-form-group"><label class="kb-label">User role</label><select name="role" class="kb-select">{$roleOptions}</select></div>
							<div style="display:flex;gap:8px;margin-top:12px;"><button type="submit" class="kb-btn kb-btn-primary">Save</button><a href="/kb-admin/users" class="kb-btn kb-btn-outline">Cancel</a></div>
						</div>
					</div>
				</div>
			</div>
		</form>
HTML;

		return $layout->render($content);
	}

	public function delete(int $id): string
	{
		$currentUserId = (int) $this->app->auth()->currentUser()->id;
		if ($id === $currentUserId) { header('Location: /kb-admin/users'); exit; }
		$users = new \Kreblu\Core\Auth\UserManager($this->app->db(), $this->app->auth());
		$users->delete($id);
		header('Location: /kb-admin/users');
		exit;
	}

	public function profile(AdminLayout $layout): string
	{
		$layout->setTitle('Your profile');
		$layout->setActivePage('profile');

		$db = $this->app->db();
		$e = fn(string $s): string => $this->e($s);
		$users = new \Kreblu\Core\Auth\UserManager($db, $this->app->auth());

		$currentUser = $this->app->auth()->currentUser();
		$id = (int) $currentUser->id;
		$user = $users->findById($id);

		if ($this->request->method() === 'POST') {
			try {
				$updateData = ['email' => $this->request->input('email', ''), 'display_name' => $this->request->input('display_name', '')];
				$newPassword = $this->request->input('password', '');
				if ($newPassword !== '') { $updateData['password'] = $newPassword; }
				$users->update($id, $updateData);
				$user = $users->findById($id);
				$layout->addNotice('success', 'Profile updated.');
			} catch (\Throwable $ex) { $layout->addNotice('error', $ex->getMessage()); }
		}

		$username = $e($user->username ?? '');
		$email = $e($user->email ?? '');
		$displayName = $e($user->display_name ?? '');
		$roleLabel = $e(\Kreblu\Core\Auth\RoleManager::getRoleLabel($user->role));

		$content = <<<HTML
		<div class="kb-grid-2">
			<div class="kb-stack">
				<div class="kb-card">
					<div class="kb-card-header"><h3>Your profile</h3></div>
					<div class="kb-card-body">
						<form method="POST" action="/kb-admin/profile">
							<div class="kb-form-group"><label class="kb-label">Username</label><input type="text" class="kb-input" value="{$username}" disabled style="opacity:0.6;"><span style="font-size:11px;color:var(--kb-text-hint);margin-top:2px;display:block;">Username cannot be changed.</span></div>
							<div class="kb-form-group"><label class="kb-label">Email</label><input type="email" name="email" class="kb-input" value="{$email}" required></div>
							<div class="kb-form-group"><label class="kb-label">Display name</label><input type="text" name="display_name" class="kb-input" value="{$displayName}"></div>
							<div class="kb-form-group"><label class="kb-label">New password <span style="font-weight:400;color:var(--kb-text-hint);">(leave blank to keep current)</span></label><input type="password" name="password" class="kb-input" minlength="8" placeholder="Minimum 8 characters"></div>
							<button type="submit" class="kb-btn kb-btn-primary">Update profile</button>
						</form>
					</div>
				</div>
			</div>
			<div class="kb-stack">
				<div class="kb-card">
					<div class="kb-card-header"><h3>Account info</h3></div>
					<div class="kb-card-body" style="font-size:13px;">
						<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--kb-border);"><span style="color:var(--kb-text-secondary);">Role</span><span class="kb-badge kb-badge-{$e($user->role)}">{$roleLabel}</span></div>
						<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--kb-border);"><span style="color:var(--kb-text-secondary);">Member since</span><span>{$e($user->created_at)}</span></div>
						<div style="display:flex;justify-content:space-between;padding:8px 0;"><span style="color:var(--kb-text-secondary);">Last login</span><span>{$e($user->last_login ?? 'Never')}</span></div>
					</div>
				</div>
			</div>
		</div>
HTML;

		return $layout->render($content);
	}
}
