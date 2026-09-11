<?php
/**
 * Surface 1, on the one kind of site that must keep it: a front end that reads `/wp/v2/users`
 * anonymously.
 *
 * WHY THIS IS A DECLARATION AND NOT A TOGGLE. A headless front end, or a JS theme that renders
 * bylines from the REST API, fetches the user collection without a cookie. That is a fact about how
 * the site was BUILT — the same category as whether the theme links to author archives — and it is
 * answerable only by somebody who has read the front end's code. It is not a preference, and there
 * is nothing an operator could usefully learn by ticking it and watching.
 *
 * THE EXACT LITERAL, AND NOTHING ELSE, EXPOSES. `used` is spelled here in full. Every near miss and
 * every malformed value resolves the other way, and that direction is proved in its own process in
 * the two files beside this one, because a PHP constant cannot be redefined.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_REST_USERS = 'used';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param string[] $capabilities
 */
function wpuo_test_rest(string $route, array $capabilities = []): string {
	wpuo_test_reset($capabilities);

	return wpuo_rest_users_verdict($route);
}

// --- the declaration ------------------------------------------------------

wpuo_assert_same(WPUO_REST_USERS_USED, wpuo_rest_users(), 'the host declared the REST user listing in use');
wpuo_assert_false(wpuo_obscuring_rest_users(), 'so the surface contributes nothing');
wpuo_assert_false(wpuo_report()['rest_users'], 'and the report says so');

// --- nothing is touched, whoever is asking --------------------------------

foreach ([[], ['edit_posts'], ['list_users']] as $caps) {
	foreach (['/wp/v2/users', '/wp/v2/users/16', '/wp/v2/users/me'] as $route) {
		wpuo_assert_same(
			'declared-used',
			wpuo_test_rest($route, $caps),
			sprintf('a declared-in-use listing leaves %s untouched', $route)
		);

		wpuo_test_reset($caps);
		wpuo_assert_same(
			null,
			wpuo_rest_users_pre_dispatch(null, null, new WPUO_Test_Request($route)),
			sprintf('and dispatch of %s is not short-circuited', $route)
		);
	}
}

// --- the declaration is read from code, never from a database -------------

wpuo_test_reset();
wpuo_assert_same(WPUO_REST_USERS_USED, wpuo_rest_users(), 'a site whose database is unreachable still knows what it declared');

// --- NOT FAIL-OPEN: the rest of the package is still doing its job ---------
//
// Without this, a build in which every surface silently stopped working would pass the assertions
// above without complaint. The two unconditional surfaces are the witnesses, because nothing in this
// process can switch them off.

wpuo_test_reset();
wpuo_assert_false(
	array_key_exists('author_name', wpuo_oembed_strip_author(['author_name' => 'Andrea Lam'])),
	'the oEmbed byline is still stripped'
);
wpuo_assert_same(
	WPUO_LOGIN_CODE,
	wpuo_login_normalize_error(new WP_Error('invalid_username', 'not registered'))->get_error_code(),
	'and the login error is still flattened'
);
wpuo_assert_same('collection', wpuo_rest_users_route('/wp/v2/users'), 'and this surface still recognises its own route');

wpuo_test_done('rest-users-used-test');
