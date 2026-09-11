<?php
/**
 * Surface 1: the REST user listing.
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

const WPUO_REST_KEY = 'wp_user_obscure_rest_users';

/**
 * @param string[] $capabilities
 */
function wpuo_test_rest(string $route, array $capabilities = [], mixed $stored = '1'): string {
	wpuo_test_reset([WPUO_REST_KEY => $stored], $capabilities);

	return wpuo_rest_users_verdict($route);
}

/**
 * @param string[] $capabilities
 */
function wpuo_test_dispatch(string $route, array $capabilities = [], mixed $stored = '1'): mixed {
	wpuo_test_reset([WPUO_REST_KEY => $stored], $capabilities);

	return wpuo_rest_users_pre_dispatch(null, null, new WPUO_Test_Request($route));
}

// --- which routes are even in scope ---------------------------------------

wpuo_assert_same('collection', wpuo_rest_users_route('/wp/v2/users'), 'the collection is the enumeration surface');
wpuo_assert_same('collection', wpuo_rest_users_route('/wp/v2/users/'), 'a trailing slash is the same route');
wpuo_assert_same('single', wpuo_rest_users_route('/wp/v2/users/16'), 'a single user by id is an enumeration surface too');
wpuo_assert_same('me', wpuo_rest_users_route('/wp/v2/users/me'), 'a user reading their own profile is its own case');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/posts'), 'other routes are not this package\'s business');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/users/16/application-passwords'), 'sub-routes are left to core\'s own per-user check');
wpuo_assert_same('', wpuo_rest_users_route('/wp/v2/users/me/application-passwords'), 'including under /me');
wpuo_assert_same('', wpuo_rest_users_route('/acme/v1/users'), 'another namespace is not the core users route');

// --- switched off: nothing is touched, whoever is asking -------------------

foreach ([[], ['edit_posts'], ['list_users']] as $caps) {
	foreach (['/wp/v2/users', '/wp/v2/users/16', '/wp/v2/users/me'] as $route) {
		wpuo_assert_same(
			'off',
			wpuo_test_rest($route, $caps, '0'),
			sprintf('an unticked setting leaves %s untouched', $route)
		);
		wpuo_assert_same(
			null,
			wpuo_test_dispatch($route, $caps, '0'),
			sprintf('and dispatch of %s is not short-circuited', $route)
		);
	}
}

// --- switched on: anonymous enumeration is refused -------------------------

wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users'), 'an anonymous listing is refused');
wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users/16'), 'an anonymous single-user read is refused');

$error = wpuo_test_dispatch('/wp/v2/users');
wpuo_assert_true(is_wp_error($error), 'the refusal short-circuits dispatch with an error');
wpuo_assert_same('rest_user_cannot_view', $error->get_error_code(), 'under the code core uses for the same refusal');
wpuo_assert_same(['status' => 401], $error->get_error_data(), 'as a 401, not an empty 200 that some client will cache');

// --- switched on: the editor keeps working --------------------------------
//
// `list_users` is an administrator capability. An Editor does not have it, and gating on it alone
// would take the block editor's author panel away from every Editor on the site.

wpuo_assert_same('capability', wpuo_test_rest('/wp/v2/users', ['list_users']), 'an administrator gets normal results');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users', ['list_users']), 'and their request is not short-circuited at all');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users/16', ['list_users']), 'including a single-user read');

wpuo_assert_same('editorial', wpuo_test_rest('/wp/v2/users', ['edit_posts']), 'an Editor without list_users still gets the author list');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users', ['edit_posts']), 'and their request is not short-circuited');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users/16', ['edit_posts']), 'including a single-user read');

wpuo_assert_same('own-profile', wpuo_test_rest('/wp/v2/users/me', []), 'reading your own profile enumerates nobody and is never refused');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users/me', []), 'so a Subscriber keeps the dashboard and the editor');

wpuo_assert_same('other-route', wpuo_test_rest('/wp/v2/posts'), 'every other route is passed through');
wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/posts'), 'with no response of this package\'s making');

// --- the route is never removed -------------------------------------------
//
// Only the answer is gated. Nothing in this package touches `rest_endpoints`, which is the common
// recipe and the one that breaks Gutenberg.

// The literal with its quotes, so this matches a hook REGISTRATION and not the prose above that
// explains why there is none.
foreach (['user-obscure.php', 'src/rest-users.php'] as $source) {
	wpuo_assert_not_contains(
		"'rest_endpoints'",
		(string) file_get_contents(dirname(__DIR__) . '/' . $source),
		sprintf('%s gates the answer and never unregisters the route', $source)
	);
}

// --- another plugin answered first ----------------------------------------

wpuo_test_reset([WPUO_REST_KEY => '1']);
$existing = new WP_Error('someone_elses_error', 'handled upstream');
wpuo_assert_same(
	$existing,
	wpuo_rest_users_pre_dispatch($existing, null, new WPUO_Test_Request('/wp/v2/users')),
	'a response another plugin already produced is returned untouched, never replaced'
);

// --- a request object this package does not recognise ----------------------

wpuo_test_reset([WPUO_REST_KEY => '1']);
wpuo_assert_same(null, wpuo_rest_users_pre_dispatch(null, null, null), 'a missing request contributes nothing and does not raise');
wpuo_assert_same(null, wpuo_rest_users_pre_dispatch(null, null, 'not an object'), 'a request that is not an object contributes nothing');
wpuo_assert_same(null, wpuo_rest_users_pre_dispatch(null, null, new stdClass()), 'an object with no get_route() contributes nothing');
wpuo_assert_same(null, wpuo_rest_users_pre_dispatch(null, null, new WPUO_Test_Request(['/wp/v2/users'])), 'a route that is not a string contributes nothing');

// --- a malformed setting contributes nothing, both directions asserted -----

foreach ([null, '', '0', 'on', 'true', ['1'], new stdClass()] as $bad) {
	$stored = null === $bad ? '' : $bad;

	wpuo_assert_same('off', wpuo_test_rest('/wp/v2/users', [], $stored), 'a malformed setting leaves anonymous listing alone');
	wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users', [], $stored), 'and produces no refusal');
	wpuo_assert_same(null, wpuo_test_dispatch('/wp/v2/users', ['edit_posts'], $stored), 'and leaves the editor working');

	// Not fail-open: the mechanism is still live and still refuses when it is actually switched on.
	wpuo_assert_same('deny', wpuo_test_rest('/wp/v2/users'), 'and the refusal still works when the setting is valid');
}

wpuo_test_done('rest-users-test');
