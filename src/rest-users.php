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
 * GATED UNLESS THE HOST DECLARES OTHERWISE. The one site that legitimately needs an anonymous answer
 * here is a headless or JS front end fetching bylines, which is a fact about how the site was built
 * rather than a preference somebody should be flipping from a screen. That declaration lives in
 * `src/surfaces.php`, defaults to gated, and takes an exact spelling to switch off.
 *
 * WHY THE DECISION IS A PERMISSION CALLBACK AND NOT `rest_pre_dispatch`. It was `rest_pre_dispatch`
 * until 3.0.0, and on a production site running ACF Pro it was completely inert. ACF hooks that
 * filter and treats it as an action:
 *
 *     add_filter( 'rest_pre_dispatch', array( $this, 'initialize' ), 10, 3 );
 *     public function initialize( $response, $handler, $request ) {
 *         if ( ! acf_get_setting( 'rest_api_enabled' ) ) { return; }   // bare, still null
 *         // ...no return statement on any path
 *     }
 *
 * PHP returns `null` implicitly, so the `WP_Error` this package had just built was discarded one
 * callback later. Measured, on their production site:
 *
 *     callback attached                         prio=10 args=3  (correct)
 *     wpuo_rest_users_pre_dispatch(...) direct  WP_Error(rest_user_cannot_view)
 *     apply_filters('rest_pre_dispatch', ...)   NULL
 *     live GET /wp-json/wp/v2/users             200, all 16 slugs
 *
 * REGISTERING LATER WOULD NOT HAVE FIXED IT, it would have won a race. The defect is the filter
 * contract itself: any callback that forgets to return discards its predecessor's value, and an
 * ecosystem cannot be audited for that. A second consuming project found the same shape in Gravity
 * Forms — `return rest_handle_options_request( null, $server, $request )`, which hands core a `null`
 * where core returns its first argument unchanged for a non-OPTIONS request. Different vendor,
 * different route, same discard.
 *
 * A `permission_callback` has no return value for a careless third party to discard. It is asked,
 * per request, and the answer it gives is the answer. That removes the class, rather than this
 * week's instance of it. The cost is that the callback can be REPLACED by another `rest_endpoints`
 * filter, which is why `wpuo_rest_users_enforcement()` exists and reports that state instead of
 * asserting success from the fact that a hook was registered.
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
 * @return 'declared-used'|'other-route'|'own-profile'|'capability'|'editorial'|'deny'
 */
function wpuo_rest_users_verdict(string $route): string {
	if (! wpuo_obscuring_rest_users()) {
		return 'declared-used';
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
 * Classifies a REGISTERED route key, which is a regular expression and not a path.
 *
 * `rest_endpoints` is keyed by the pattern core registered — `/wp/v2/users/(?P<id>[\d]+)`, not
 * `/wp/v2/users/16`. Matching the group name exactly would be the same mistake as keying the
 * capability gate on `who=authors`: core has renamed such things before, and a pattern that stops
 * matching would put this package back where it started, silently. So the shape is matched instead —
 * the users route plus exactly one more segment, whatever that segment is spelled as.
 *
 * Over-matching is cheap and under-matching is not. A route wrapped that should not have been is
 * re-examined per request by `wpuo_rest_users_verdict()`, which answers `other-route` and permits.
 * A route left unwrapped is an open listing.
 *
 * `/wp/v2/users/me` is excluded here, so it is never wrapped at all.
 *
 * @return ''|'collection'|'single'|'me'
 */
function wpuo_rest_users_pattern(string $pattern): string {
	$pattern = '/' . trim($pattern, '/');

	if ('/wp/v2/users' === $pattern) {
		return 'collection';
	}

	if ('/wp/v2/users/me' === $pattern) {
		return 'me';
	}

	if (! str_starts_with($pattern, '/wp/v2/users/')) {
		return '';
	}

	$tail = substr($pattern, strlen('/wp/v2/users/'));

	// A deeper route — `/wp/v2/users/(?P<user_id>[\d]+)/application-passwords` among them — is left to
	// core's own per-user `edit_user` check, which is not an enumeration.
	return str_contains($tail, '/') ? '' : 'single';
}

/**
 * The decision, wrapped around whatever permission callback the route already had.
 *
 * WRAPS, NEVER REPLACES, AND ASKS FIRST. Another plugin's rule on these routes may be stricter than
 * this one, and a wrapper that ignored it would loosen a control somebody else installed. So the
 * inner callback is asked, its refusal is returned exactly as it gave it — `WP_Error`, `false` or
 * `null`, each of which core reads as a denial — and only a request it permitted reaches the gate
 * here. A route that had no callback at all is treated as permitted, and then gated.
 *
 * The inner value is returned unchanged when this package permits, so a callback that answers with
 * something other than `true` keeps whatever meaning core gives it.
 *
 * By the time a permission callback runs, the REST server has resolved authentication, so
 * `current_user_can()` is reliable here — the one property of `rest_pre_dispatch` that mattered, and
 * it is unchanged.
 *
 * 401 rather than an empty 200: an empty list is a lie that some client will cache, and the status
 * core itself returns when a user may not read a user is an authentication error.
 */
function wpuo_rest_users_permit(mixed $inner, mixed $request): mixed {
	$permitted = true;

	if (null !== $inner && is_callable($inner)) {
		$permitted = ($inner)($request);

		// Core's own reading of a permission callback's answer. A denial is returned untouched.
		if (is_wp_error($permitted) || false === $permitted || null === $permitted) {
			return $permitted;
		}
	}

	if (! is_object($request) || ! method_exists($request, 'get_route')) {
		return $permitted;
	}

	$route = $request->get_route();

	if (! is_string($route) || 'deny' !== wpuo_rest_users_verdict($route)) {
		return $permitted;
	}

	return new WP_Error(
		'rest_user_cannot_view',
		'Sorry, you are not allowed to list users.',
		['status' => 401]
	);
}

/**
 * The wrapper itself, as an object rather than a closure.
 *
 * An object because two things need to RECOGNISE it later: `wpuo_rest_users_endpoints()`, so that a
 * second pass cannot nest wrappers, and `wpuo_rest_users_enforcement()`, so that the report can
 * state whether this package's decision is still on the route the server will serve. A closure is
 * anonymous and would leave both of those guessing.
 *
 * `$inner` is public and readonly so a test can assert that the previous callback was kept rather
 * than discarded, which is a claim no behavioural assertion makes as directly.
 */
final class WPUO_Rest_Users_Permission {
	public function __construct(public readonly mixed $inner) {
	}

	public function __invoke(mixed $request): mixed {
		return wpuo_rest_users_permit($this->inner, $request);
	}
}

/**
 * The `rest_endpoints` callback. IT REGISTERS NO ROUTE AND REMOVES NONE.
 *
 * Unregistering `/wp/v2/users` is the common recipe and it breaks the block editor's author panel.
 * Nothing here touches the route table's shape: the same keys go out as came in, the same handlers,
 * the same callbacks, the same arguments. One value changes, on two routes, and it is the one core
 * provides for deciding whether a request may be answered.
 *
 * Total by construction. A route table that is not an array, a handler that is not an array, a route
 * option mistaken for a handler — none of them raise, and none of them are modified.
 */
function wpuo_rest_users_endpoints(mixed $endpoints): mixed {
	if (! is_array($endpoints) || ! wpuo_obscuring_rest_users()) {
		return $endpoints;
	}

	foreach ($endpoints as $pattern => $handlers) {
		if (! is_string($pattern) || ! is_array($handlers)) {
			continue;
		}

		$kind = wpuo_rest_users_pattern($pattern);

		if ('collection' !== $kind && 'single' !== $kind) {
			continue;
		}

		foreach ($handlers as $key => $handler) {
			// String keys are route options, not handlers. Core draws the same line the same way.
			if (! is_numeric($key) || ! is_array($handler)) {
				continue;
			}

			$inner = $handler['permission_callback'] ?? null;

			// Already wrapped. `get_routes()` re-applies this filter to a fresh copy of the route table
			// on every call, so this cannot normally happen — and if some host arranges that it does,
			// wrappers must not nest.
			if ($inner instanceof WPUO_Rest_Users_Permission) {
				continue;
			}

			$endpoints[$pattern][$key]['permission_callback'] = new WPUO_Rest_Users_Permission($inner);
		}
	}

	return $endpoints;
}

/**
 * Whether the decision is actually ON THE ROUTES THE SERVER WILL SERVE, or whether that cannot be
 * told from here.
 *
 * THIS IS THE LESSON FROM THE ACF SITE, AND IT OUTLIVES ACF. That site's report said the REST
 * listing was protected while the endpoint served sixteen login slugs, because the report described
 * a registration. Registration had succeeded. Enforcement had not. Anything that reports success on
 * the strength of having called `add_filter()` can produce exactly that, and a control which cannot
 * distinguish the two is indistinguishable from one that is not there.
 *
 * So this reads the route table back out of the server, AFTER every plugin's `rest_endpoints`
 * callback has had it, and looks for this package's own wrapper on every handler of every users
 * route. Anything else is `not-in-force`.
 *
 * IT NEVER BUILDS A SERVER TO FIND OUT. `rest_get_server()` would instantiate one and fire
 * `rest_api_init`, which is a side effect a report has no business causing on an ordinary page load.
 * Outside a REST request there is nothing to inspect and the honest answer is `unknown` — which is
 * not a failure and not a success, and must not be read as either.
 *
 * @return 'declared-used'|'in-force'|'not-in-force'|'unknown'
 */
function wpuo_rest_users_enforcement(): string {
	if (! wpuo_obscuring_rest_users()) {
		return 'declared-used';
	}

	$server = $GLOBALS['wp_rest_server'] ?? null;

	if (! is_object($server) || ! method_exists($server, 'get_routes')) {
		return 'unknown';
	}

	$routes = $server->get_routes();

	if (! is_array($routes)) {
		return 'unknown';
	}

	$examined = 0;

	foreach ($routes as $pattern => $handlers) {
		if (! is_string($pattern) || ! is_array($handlers)) {
			continue;
		}

		$kind = wpuo_rest_users_pattern($pattern);

		if ('collection' !== $kind && 'single' !== $kind) {
			continue;
		}

		foreach ($handlers as $key => $handler) {
			if (! is_numeric($key) || ! is_array($handler)) {
				continue;
			}

			$examined++;

			if (! (($handler['permission_callback'] ?? null) instanceof WPUO_Rest_Users_Permission)) {
				return 'not-in-force';
			}
		}
	}

	// No users route in the table at all. Something else removed it, and this package cannot claim a
	// decision it was never asked for.
	return 0 === $examined ? 'unknown' : 'in-force';
}
