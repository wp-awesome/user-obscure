<?php
/**
 * Surface 1: `/wp-json/wp/v2/users`.
 *
 * MEASURED, NOT ASSUMED. On a site running SiteGround Security with `disable_usernames=1` — a
 * setting that only hooks `illegal_user_logins`, which blocks CREATING a user named "admin" and has
 * never touched a read surface — `GET /wp-json/wp/v2/users?per_page=100` returned sixteen accounts
 * with their login slugs. The REST `slug` field IS the login name.
 *
 * THE ROUTE IS NOT REMOVED, DELIBERATELY. Unregistering `/wp/v2/users` is the common recipe and it
 * breaks the block editor's author panel, several plugins, and anything else that reads the route
 * while logged in. What is gated here is the ANSWER, per request, on capability.
 *
 * WHY THE GATE IS `list_users` OR `edit_posts`, AND NOT `list_users` ALONE. `list_users` belongs to
 * administrators only; an Editor does not have it. The block editor's author selector is served to
 * anyone who can create content, and core permits it for them today through a request shape whose
 * parameter name has already changed twice — `who=authors`, then `has_published_posts`, then a
 * `capabilities` parameter. Keying on the parameter name would make the editor break silently on a
 * core upgrade. Keying on capability cannot. The cost is stated plainly: this does not hide accounts
 * from anybody who already holds an authoring account. It removes ANONYMOUS reconnaissance.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Classifies a REST route as a user-enumeration surface.
 *
 * Only the collection and a single numeric user are matched, both exactly. Anything deeper —
 * `/wp/v2/users/5/application-passwords` among them — is left to core, whose own permission check
 * there is a per-user `edit_user`, not an enumeration. Widening this to a prefix match would take
 * self-service application passwords away from every non-administrator.
 *
 * `/wp/v2/users/me` is its own case and is never denied: it is a user reading their own profile,
 * which the dashboard and the block editor both do, and which enumerates nobody.
 *
 * @return ''|'collection'|'single'|'me'
 */
function wpuo_rest_users_route(string $route): string {
	$route = '/' . trim($route, '/');

	if ('/wp/v2/users' === $route) {
		return 'collection';
	}

	if ('/wp/v2/users/me' === $route) {
		return 'me';
	}

	return 1 === preg_match('#^/wp/v2/users/\d+$#', $route) ? 'single' : '';
}

/**
 * Why this request is or is not answered.
 *
 * Returns WHICH reason matched rather than a boolean, so a test can prove that an Editor keeps the
 * route because of `edit_posts` and not merely because the test happened to grant `list_users`.
 * `deny` is the only verdict that changes the response.
 *
 * @return 'off'|'other-route'|'own-profile'|'capability'|'editorial'|'deny'
 */
function wpuo_rest_users_verdict(string $route): string {
	if (! wpuo_obscuring('rest_users')) {
		return 'off';
	}

	$kind = wpuo_rest_users_route($route);

	if ('' === $kind) {
		return 'other-route';
	}

	if ('me' === $kind) {
		return 'own-profile';
	}

	if (current_user_can('list_users')) {
		return 'capability';
	}

	if (current_user_can('edit_posts')) {
		return 'editorial';
	}

	return 'deny';
}

/**
 * The `rest_pre_dispatch` callback.
 *
 * Returning a non-null value short-circuits the dispatch, which is why this hook is used rather than
 * `rest_endpoints` (removes the route) or a response filter (runs after the query). By dispatch time
 * the REST server has already resolved authentication, so `current_user_can()` is reliable here.
 *
 * 401 rather than an empty 200: an empty list is a lie that some client will cache, and the status
 * core itself returns when a user may not read a user is an authentication error.
 *
 * @param mixed $result
 * @param mixed $server
 * @param mixed $request
 *
 * @return mixed
 */
function wpuo_rest_users_pre_dispatch(mixed $result, mixed $server = null, mixed $request = null): mixed {
	// Something earlier already answered. Never overwrite it — that would turn another plugin's
	// response into ours.
	if (null !== $result) {
		return $result;
	}

	if (! is_object($request) || ! method_exists($request, 'get_route')) {
		return $result;
	}

	$route = $request->get_route();

	if (! is_string($route)) {
		return $result;
	}

	if ('deny' !== wpuo_rest_users_verdict($route)) {
		return $result;
	}

	return new WP_Error(
		'rest_user_cannot_view',
		'Sorry, you are not allowed to list users.',
		['status' => 401]
	);
}
