<?php
/**
 * Surface 1: the REST user listing, on a site that has declared nothing.
 *
 * OBSCURE IS THE DEFAULT DIRECTION HERE, AND IT IS THE ONLY SURFACE WHERE THE DEFAULT POINTS THAT
 * WAY. Author archives default to `used` because 404ing a published URL is diffuse damage nobody
 * notices; the REST user listing defaults to `unused` because the site that genuinely reads it
 * anonymously is a headless or JS front end whose developer knows they built one, and whose failure
 * is a 401 they will see within a minute of deploying.
 *
 * THE ASSERTIONS THAT MATTER MOST ARE THE ONES ABOUT WHAT IS *NOT* DENIED. Breaking the block editor
 * for an Editor is the most likely real-world regression this package can cause, and it is silent
 * from the server's side: the author panel simply stops populating. Every capability that core would
 * have answered is asserted here by name, and so is the request a subscriber makes for their own
 * profile.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param string[] $capabilities
 */
function wpuo_test_rest(string $route, array $capabilities = []): string {
	wpuo_test_reset($capabilities);

	return wpuo_rest_users_verdict($route);
}

/**
 * The decision as the route's permission callback answers it, with no callback underneath.
 *
 * `true` is what a permitted request gets — not `null`, which core reads as a denial.
 *
 * @param string[] $capabilities
 */
function wpuo_test_permission(string $route, array $capabilities = [], mixed $inner = null): mixed {
	wpuo_test_reset($capabilities);

	return wpuo_rest_users_permit($inner, new WPUO_Test_Request($route));
}

// --- the declaration this file runs under ---------------------------------
//
// Nothing is defined. That is the shape of a bare install, and it is obscured.

wpuo_assert_false(defined('WP_USER_OBSCURE_REST_USERS'), 'this file runs on a site that declared nothing');
wpuo_assert_same(WPUO_REST_USERS_UNUSED, wpuo_rest_users(), 'an absent declaration resolves to unused');
wpuo_assert_true(wpuo_obscuring_rest_users(), 'so the REST user listing is obscured without anybody asking');
// AND THE REPORT DOES NOT CLAIM MORE THAN IT KNOWS. This process has no REST server, so there is no
// route table to read the decision off, and the honest answer is that it cannot tell. It used to
// answer `true` here — meaning "we registered something" — which is what a production site's report
// said while the endpoint served sixteen login slugs.
wpuo_assert_same('unknown', wpuo_report()['rest_users'], 'and the report declines to claim enforcement it cannot see');

// --- which routes are even in scope ---------------------------------------

wpuo_assert_same('collection', wpuo_rest_users_route('/wp/v2/users'), 'the collection is the enumeration surface');
wpuo_assert_same('collection', wpuo_rest_users_route('/wp/v2/users/'), 'a trailing slash is the same route');
wpuo_assert_same('single', wpuo_rest_users_route('/wp/v2/users/16'), 'a single user by id is an enumeration surface too');
wpuo_assert_same('me', wpuo_rest_users_route('/wp/v2/users/me'), 'a user reading their own profile is its own case');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/posts'), 'other routes are not this package\'s business');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/users/16/application-passwords'), 'sub-routes are left to core\'s own per-user check');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/users/me/application-passwords'), 'including under /me');
wpuo_assert_same('', wpuo_rest_users_route('/acme/v1/users'), 'another namespace is not the core users route');

// --- anonymous enumeration is refused -------------------------------------

wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users'), 'an anonymous listing is refused');
wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users/16'), 'an anonymous single-user read is refused');

$error = wpuo_test_permission('/wp/v2/users');
wpuo_assert_true(is_wp_error($error), 'the refusal is the permission callback\'s own answer');
wpuo_assert_same('rest_user_cannot_view', $error->get_error_code(), 'under the code core uses for the same refusal');
wpuo_assert_same(['status' => 401], $error->get_error_data(), 'as a 401, not an empty 200 that some client will cache');

// --- the editor keeps working ---------------------------------------------
//
// `list_users` is an administrator capability. An Editor does not have it, and gating on it alone
// would take the block editor's author panel away from every Editor on the site. This was the single
// most load-bearing exemption when the surface was optional, and it matters more now that nobody can
// switch the surface off to get their editor back.

wpuo_assert_same('capability', wpuo_test_rest('/wp/v2/users', ['list_users']), 'an administrator gets normal results');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/users', ['list_users']), 'and their request is permitted');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/users/16', ['list_users']), 'including a single-user read');

wpuo_assert_same('editorial', wpuo_test_rest('/wp/v2/users', ['edit_posts']), 'an Editor without list_users still gets the author list');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/users', ['edit_posts']), 'and their request is permitted');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/users/16', ['edit_posts']), 'including a single-user read');

wpuo_assert_same('own-profile', wpuo_test_rest('/wp/v2/users/me', []), 'reading your own profile enumerates nobody and is never refused');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/users/me', []), 'so a Subscriber keeps the dashboard and the editor');

wpuo_assert_same('other-route', wpuo_test_rest('/wp/v2/posts'), 'every other route is passed through');
wpuo_assert_same(true, wpuo_test_permission('/wp/v2/posts'), 'with no refusal of this package\'s making');

// --- which registered routes are wrapped, and what is left alone -----------
//
// `rest_endpoints` is the hook the common recipe uses to REMOVE `/wp/v2/users`, which is what breaks
// the block editor's author panel. This package uses the same hook to change one value on two
// routes. The difference is not a matter of intent, so it is asserted: same route keys, same
// handlers, same endpoint callbacks, same arguments, in the same order.

wpuo_test_reset();
$before = wpuo_test_core_endpoints();
$after  = wpuo_rest_users_endpoints($before);

wpuo_assert_same(array_keys($before), array_keys($after), 'every route core registered is still registered, in the same order');

$wrapped = [];

foreach ($after as $pattern => $handlers) {
	foreach ($handlers as $key => $handler) {
		if (! is_numeric($key)) {
			wpuo_assert_same($before[$pattern][$key], $handler, sprintf('%s keeps its route options untouched', $pattern));
			continue;
		}

		wpuo_assert_same($before[$pattern][$key]['callback'], $handler['callback'], sprintf('%s keeps its endpoint callback', $pattern));
		wpuo_assert_same($before[$pattern][$key]['methods'], $handler['methods'], sprintf('%s keeps its methods', $pattern));
		wpuo_assert_same($before[$pattern][$key]['args'], $handler['args'], sprintf('%s keeps its arguments', $pattern));

		if ($handler['permission_callback'] instanceof WPUO_Rest_Users_Permission) {
			$wrapped[] = $pattern;
			continue;
		}

		wpuo_assert_same(
			$before[$pattern][$key]['permission_callback'],
			$handler['permission_callback'],
			sprintf('%s keeps the permission callback it had', $pattern)
		);
	}
}

wpuo_assert_same(
	['/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)'],
	$wrapped,
	'exactly the collection and the single user are wrapped: not /me, not a sub-route, not another route'
);

// The registered key is a REGULAR EXPRESSION, not a path, and the group name in it has no promise
// attached. Matching the shape rather than the spelling is what keeps a core rename from making this
// package silently inert — the same failure this release exists to remove.

wpuo_assert_same('collection', wpuo_rest_users_pattern('/wp/v2/users'), 'the collection pattern is recognised');
wpuo_assert_same('single', wpuo_rest_users_pattern('/wp/v2/users/(?P<id>[\d]+)'), 'so is the single-user pattern core registers today');
wpuo_assert_same('single', wpuo_rest_users_pattern('/wp/v2/users/(?P<user_id>[\d]+)'), 'and so is the same route with the group renamed');
wpuo_assert_same('me', wpuo_rest_users_pattern('/wp/v2/users/me'), '/me is recognised and never wrapped');
wpuo_assert_same('', wpuo_rest_users_pattern('/wp/v2/users/(?P<user_id>[\d]+)/application-passwords'), 'a deeper route is left to core');
wpuo_assert_same('', wpuo_rest_users_pattern('/wp/v2/posts'), 'another route is not this package\'s business');

// --- a route table this package does not recognise -------------------------

wpuo_assert_same('not an array', wpuo_rest_users_endpoints('not an array'), 'a route table that is not an array is returned untouched');
wpuo_assert_same([], wpuo_rest_users_endpoints([]), 'an empty one too');
wpuo_assert_same(
	['/wp/v2/users' => 'nonsense'],
	wpuo_rest_users_endpoints(['/wp/v2/users' => 'nonsense']),
	'and a handler list that is not a list is left alone rather than raising'
);

// --- WRAPS, NEVER REPLACES -------------------------------------------------
//
// Another plugin's rule on these routes may be STRICTER than this one. A wrapper that ignored it
// would loosen a control somebody else installed, which is a worse defect than the one this release
// fixes. So the inner callback is asked first, and its refusal is returned exactly as it gave it.

$strict  = new WP_Error('someone_elses_error', 'handled upstream');
$wrapper = new WPUO_Rest_Users_Permission(static fn (mixed $request): mixed => $strict);

wpuo_test_reset(['list_users']);
wpuo_assert_same(
	$strict,
	($wrapper)(new WPUO_Test_Request('/wp/v2/users')),
	'another plugin\'s refusal is returned untouched, even for an administrator this package would permit'
);

wpuo_assert_same(
	false,
	wpuo_test_permission('/wp/v2/users', ['list_users'], static fn (mixed $request): bool => false),
	'a plain false denial is returned untouched too'
);

wpuo_assert_same(
	null,
	wpuo_test_permission('/wp/v2/users', ['list_users'], static fn (mixed $request): mixed => null),
	'and so is a null, which core reads as a denial'
);

// A permitted request reaches this package's gate, and a permitted request it does not deny keeps
// the inner answer exactly as it was given.

wpuo_assert_true(
	is_wp_error(wpuo_test_permission('/wp/v2/users', [], static fn (mixed $request): bool => true)),
	'a request the inner callback permitted is still refused to an anonymous caller'
);

wpuo_assert_same(
	1,
	wpuo_test_permission('/wp/v2/users', ['edit_posts'], static fn (mixed $request): mixed => 1),
	'and when this package permits, the inner callback\'s own answer is what is returned'
);

$inner = static fn (mixed $request): mixed => $strict;
wpuo_assert_same(
	$inner,
	(new WPUO_Rest_Users_Permission($inner))->inner,
	'the wrapper KEEPS the callback it wrapped, rather than discarding it'
);

// --- a request object this package does not recognise ----------------------
//
// Only routes this package chose to wrap reach here, so this is defence in depth rather than a
// reachable state. It fails the way the rest of the package does: by contributing nothing.

wpuo_test_reset();
wpuo_assert_same(true, wpuo_rest_users_permit(null, null), 'a missing request contributes nothing and does not raise');
wpuo_assert_same(true, wpuo_rest_users_permit(null, 'not an object'), 'a request that is not an object contributes nothing');
wpuo_assert_same(true, wpuo_rest_users_permit(null, new stdClass()), 'an object with no get_route() contributes nothing');
wpuo_assert_same(true, wpuo_rest_users_permit(null, new WPUO_Test_Request(['/wp/v2/users'])), 'a route that is not a string contributes nothing');
wpuo_assert_same(true, wpuo_rest_users_permit('no_such_function_anywhere', new WPUO_Test_Request('/wp/v2/posts')), 'a permission callback that is not callable is not called');

// --- the retired hook is GONE, not merely unused ---------------------------
//
// A consuming project pinned `remove_filter('rest_pre_dispatch', 'wpuo_rest_users_pre_dispatch')` as
// its workaround. That call is now a no-op, and a no-op is exactly what this release must not be
// quiet about. The function is removed rather than left in place unhooked, so `function_exists()`
// answers honestly and nothing can re-attach a decision that no longer works.

wpuo_assert_false(function_exists('wpuo_rest_users_pre_dispatch'), 'the rest_pre_dispatch callback no longer exists');

foreach (['user-obscure.php', 'src/rest-users.php'] as $source) {
	wpuo_assert_not_contains(
		"add_filter('rest_pre_dispatch'",
		wpuo_test_source($source),
		sprintf('%s registers nothing on rest_pre_dispatch', $source)
	);
}

// --- no stored value can reach this decision -------------------------------
//
// Four options named `wp_user_obscure_*` exist in at least one production database, all set to '1'.
// They are inert. `get_option()` throws for the whole of this process, so every assertion above was
// reached without one — and a build that started consulting a stored value would fail here rather
// than quietly obey a row nobody remembers setting.

wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users'), 'the refusal is reached with the database fenced off');

wpuo_test_done('rest-users-test');
