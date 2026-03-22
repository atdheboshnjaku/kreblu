<?php declare(strict_types=1);

namespace Kreblu\Core\Api;

use Kreblu\Core\App;
use Kreblu\Core\Http\Router;
use Kreblu\Core\Http\Request;
use Kreblu\Core\Http\Response;
use Kreblu\Core\Api\Endpoints\PostsEndpoint;
use Kreblu\Core\Api\Endpoints\TermsEndpoint;
use Kreblu\Core\Api\Endpoints\OptionsEndpoint;
use Kreblu\Core\Content\PostManager;
use Kreblu\Core\Content\TaxonomyManager;

/**
 * API Route Registration
 *
 * Registers all /api/v1/* routes. Called during the application boot process.
 *
 * All API routes pass through the apiAuth middleware which validates
 * the Bearer token and attaches the authenticated context to the request.
 *
 * Read endpoints (GET) require 'read' scope.
 * Write endpoints (POST, PUT, DELETE) require 'write' scope.
 * Admin endpoints (user management, options) require 'admin' scope.
 */
final class ApiRoutes
{
	/**
	 * Register all API routes on the given router.
	 */
	public static function register(Router $router, App $app): void
	{
		$router->group('/api/v1', function (Router $api) use ($app): void {
			// ----------------------------------------------------------
			// Posts
			// ----------------------------------------------------------
			$postsEndpoint = new PostsEndpoint(new PostManager($app->db()));

			$api->get('/posts', function (Request $req) use ($postsEndpoint): Response {
				return $postsEndpoint->index($req);
			});

			$api->get('/posts/{id}', function (Request $req, array $params) use ($postsEndpoint): Response {
				return $postsEndpoint->show($req, $params);
			});

			$api->post('/posts', function (Request $req) use ($postsEndpoint): Response {
				return $postsEndpoint->store($req);
			});

			$api->put('/posts/{id}', function (Request $req, array $params) use ($postsEndpoint): Response {
				return $postsEndpoint->update($req, $params);
			});

			$api->delete('/posts/{id}', function (Request $req, array $params) use ($postsEndpoint): Response {
				return $postsEndpoint->destroy($req, $params);
			});

			// ----------------------------------------------------------
			// Terms (categories, tags, custom taxonomies)
			// ----------------------------------------------------------
			$termsEndpoint = new TermsEndpoint(new TaxonomyManager($app->db()));

			$api->get('/terms', function (Request $req) use ($termsEndpoint): Response {
				return $termsEndpoint->index($req);
			});

			$api->get('/terms/{id}', function (Request $req, array $params) use ($termsEndpoint): Response {
				return $termsEndpoint->show($req, $params);
			});

			$api->post('/terms', function (Request $req) use ($termsEndpoint): Response {
				return $termsEndpoint->store($req);
			});

			$api->put('/terms/{id}', function (Request $req, array $params) use ($termsEndpoint): Response {
				return $termsEndpoint->update($req, $params);
			});

			$api->delete('/terms/{id}', function (Request $req, array $params) use ($termsEndpoint): Response {
				return $termsEndpoint->destroy($req, $params);
			});

			// ----------------------------------------------------------
			// Options (site settings — admin scope)
			// ----------------------------------------------------------
			$optionsEndpoint = new OptionsEndpoint($app->db());

			$api->get('/options', function (Request $req) use ($optionsEndpoint): Response {
				return $optionsEndpoint->index($req);
			});

			$api->get('/options/{key}', function (Request $req, array $params) use ($optionsEndpoint): Response {
				return $optionsEndpoint->show($req, $params);
			});

			$api->put('/options/{key}', function (Request $req, array $params) use ($optionsEndpoint): Response {
				return $optionsEndpoint->update($req, $params);
			});

			$api->delete('/options/{key}', function (Request $req, array $params) use ($optionsEndpoint): Response {
				return $optionsEndpoint->destroy($req, $params);
			});
		});
	}
}
