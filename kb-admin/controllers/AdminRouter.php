<?php declare(strict_types=1);

/**
 * K Hub Admin Router
 *
 * Thin dispatcher — maps /kb-admin/* routes to controller methods.
 * All page logic lives in individual controller files.
 */

namespace Kreblu\Admin;

use Kreblu\Core\App;
use Kreblu\Core\Http\Request;

final class AdminRouter
{
	private readonly App $app;
	private readonly Request $request;

	public function __construct(App $app, Request $request)
	{
		$this->app = $app;
		$this->request = $request;
	}

	public function handle(): string
	{
		$path = $this->request->path();
		$route = trim(str_replace('/kb-admin', '', $path), '/');
		if ($route === '') {
			$route = 'dashboard';
		}

		$layout = new AdminLayout($this->app, $this->request);

		$dashboard  = new DashboardController($this->app, $this->request);
		$post       = new PostController($this->app, $this->request);
		$comment    = new CommentController($this->app, $this->request);
		$category   = new CategoryController($this->app, $this->request);
		$media      = new MediaController($this->app, $this->request);
		$user       = new UserController($this->app, $this->request);
		$settings   = new SettingsController($this->app, $this->request);
		$menu       = new MenuController($this->app, $this->request);
		$template   = new TemplateController($this->app, $this->request);

		$content = match (true) {
			// Dashboard
			$route === 'dashboard' => $dashboard->index($layout),

			// Posts
			$route === 'posts'          => $post->list($layout, 'post'),
			$route === 'posts/new'      => $post->editor($layout, 'post'),
			str_starts_with($route, 'posts/edit/')   => $post->editor($layout, 'post', (int) str_replace('posts/edit/', '', $route)),
			str_starts_with($route, 'posts/delete/')  => $post->delete((int) str_replace('posts/delete/', '', $route), 'post'),
			str_starts_with($route, 'posts/trash/')   => $post->trash((int) str_replace('posts/trash/', '', $route), 'post'),
			str_starts_with($route, 'posts/restore/') => $post->restore((int) str_replace('posts/restore/', '', $route), 'post'),

			// Pages
			$route === 'pages'          => $post->list($layout, 'page'),
			$route === 'pages/new'      => $post->editor($layout, 'page'),
			str_starts_with($route, 'pages/edit/')    => $post->editor($layout, 'page', (int) str_replace('pages/edit/', '', $route)),
			str_starts_with($route, 'pages/delete/')  => $post->delete((int) str_replace('pages/delete/', '', $route), 'page'),
			str_starts_with($route, 'pages/trash/')   => $post->trash((int) str_replace('pages/trash/', '', $route), 'page'),
			str_starts_with($route, 'pages/restore/') => $post->restore((int) str_replace('pages/restore/', '', $route), 'page'),

			// Comments
			$route === 'comments' => $comment->list($layout),

			// Categories
			$route === 'categories' => $category->list($layout),

			// Media
			$route === 'media' => $media->index($layout),

			// Users
			$route === 'users'              => $user->list($layout),
			$route === 'users/new'          => $user->editor($layout),
			str_starts_with($route, 'users/edit/') => $user->editor($layout, (int) str_replace('users/edit/', '', $route)),
			str_starts_with($route, 'users/delete/') => $user->delete((int) str_replace('users/delete/', '', $route)),
			$route === 'profile'            => $user->profile($layout),

			// Settings
			$route === 'settings' => $settings->index($layout),

			// Menus
			$route === 'menus' => $menu->index($layout),

			// Templates
			$route === 'templates' => $template->index($layout),

			// Kaps (coming soon)
			$route === 'kaps' => $this->comingSoon($layout, 'Kaps', 'kaps'),

			// Logout
			$route === 'logout' => $this->logout(),

			default => $this->notFound($layout),
		};

		return $content;
	}

	private function comingSoon(AdminLayout $layout, string $title, string $page): string
	{
		$layout->setTitle($title);
		$layout->setActivePage($page);
		return $layout->render('<div class="kb-card"><div class="kb-card-body"><div class="kb-empty"><h3>' . $title . '</h3><p>' . $title . ' management is coming soon.</p></div></div></div>');
	}

	private function notFound(AdminLayout $layout): string
	{
		$layout->setTitle('Not Found');
		return $layout->render('<div class="kb-card"><div class="kb-card-body"><div class="kb-empty"><h3>Page not found</h3><p>The admin page you requested doesn\'t exist.</p><a href="/kb-admin/" class="kb-btn kb-btn-primary">Go to dashboard</a></div></div></div>');
	}

	private function logout(): string
	{
		if (session_status() === PHP_SESSION_NONE) { session_start(); }
		$_SESSION = [];
		session_destroy();
		setcookie('kb_session', '', ['expires' => time() - 3600, 'path' => '/']);
		header('Location: /kb-admin/');
		exit;
	}
}
