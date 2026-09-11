<?php
/**
 * Surface 1, dispatched through a filter chain that contains a plugin which forgets to return.
 *
 * THIS FILE EXISTS BECAUSE A REGISTERED CALLBACK IS NOT A CONTROL. Measured on production, by a
 * consuming project, on a site running ACF Pro:
 *
 *     callback attached                         prio=10 args=3  (correct)
 *     wpuo_rest_users_pre_dispatch(...) direct  WP_Error(rest_user_cannot_view)
 *     apply_filters('rest_pre_dispatch', ...)   NULL
 *     live GET /wp-json/wp/v2/users             200, all 16 slugs
 *
 * Every assertion this suite had about surface 1 held on that site. The callback was attached, at
 * the right priority, with the right argument count, and calling it built the right `WP_Error`. The
 * next callback in the chain returned `null` and the error was gone. So the harness now runs the
 * CHAIN, with the careless callbacks in it, and asserts on what a request actually gets.
 *
 * THE DEFECT IS THE FILTER CONTRACT, NOT ANY ONE PLUGIN. `rest_pre_dispatch` is a filter, and a
 * filter callback that forgets to return discards whatever the previous one produced. Two named
 * instances are reproduced below, from two different vendors, reached by two different routes. A
 * third — WPML — is reproduced beside them BECAUSE IT IS CORRECT, so that this file proves the
 * distinction rather than a superstition about the hook.
 *
 * None of them can reach the decision any more. A `permission_callback` has no return value for a
 * later callback to throw away.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/user-obscure.php';

// ---------------------------------------------------------------------------
// The chain, as three real plugins build it
// ---------------------------------------------------------------------------

/**
 * ACF Pro, `includes/rest-api/class-acf-rest-api.php`.
 *
 *     add_filter( 'rest_pre_dispatch', array( $this, 'initialize' ), 10, 3 );
 *
 *     public function initialize( $response, $handler, $request ) {
 *         if ( ! acf_get_setting( 'rest_api_enabled' ) ) { return; }
 *         // ...no return statement on any path
 *     }
 *
 * A filter hooked as if it were an action. PHP returns `null` implicitly, so whatever the previous
 * callback produced is discarded. THE EARLY RETURN IS BARE TOO, which is why turning ACF's REST
 * integration off is not a workaround: the disabled path clobbers exactly like the enabled one.
 *
 * Reproduced here without a return type, as the original is written.
 */
function wpuo_test_acf_initialize(mixed $response, mixed $handler = null, mixed $request = null) {
	// Deliberately nothing. This is the whole of the defect.
}

/**
 * Core's `rest_handle_options_request()`, which returns its FIRST argument unchanged when the
 * request method is not OPTIONS.
 */
function wpuo_test_handle_options_request(mixed $response, mixed $server = null, mixed $request = null): mixed {
	return $response;
}

/**
 * Gravity Forms, `class-gf-rest-authentication.php:840`.
 *
 *     return rest_handle_options_request( null, $server, $request );
 *
 * It passes `null` where core expects the value it was handed. On a GET that returns `null`, and the
 * previous callback's `WP_Error` is gone — ACF's failure reached by a completely different route,
 * from a completely different vendor. Narrower in practice: the path is only reached for a caller
 * authenticated with a Gravity Forms API key, so it is not an anonymous exposure. It is here because
 * it proves an adopter cannot screen for this by asking "do we run ACF".
 */
function wpuo_test_gravityforms_authentication(mixed $result, mixed $server = null, mixed $request = null): mixed {
	return wpuo_test_handle_options_request(null, $server, $request);
}

/**
 * WPML, `replace_shortcode_in_rest_request`, at `PHP_INT_MAX`. THE CONTRAST CASE, AND IT IS SAFE.
 *
 * One terminal `return $result`, with the body-rewriting branch mutating the request and falling
 * through to that same return. It registers for visitors and subscribers too, so it IS in the chain
 * of an anonymous request — and an error passed to it comes out the other side.
 *
 * Without this callback in the file, the assertions below would read as "anything on this filter is
 * dangerous", which is false and would send the next reader looking for the wrong thing. The defect
 * is a path with no return.
 */
function wpuo_test_wpml_replace_shortcode(mixed $result, mixed $server = null, mixed $request = null): mixed {
	return $result;
}

add_filter('rest_pre_dispatch', 'wpuo_test_acf_initialize', 10, 3);
add_filter('rest_pre_dispatch', 'wpuo_test_gravityforms_authentication', 10, 3);
add_filter('rest_pre_dispatch', 'wpuo_test_wpml_replace_shortcode', PHP_INT_MAX, 3);

// ---------------------------------------------------------------------------
// The chain really does clobber
// ---------------------------------------------------------------------------
//
// The reporter's own measurement, reproduced. Without this assertion the file could pass against a
// harness whose careless callbacks were never actually reached, and every denial below would prove
// nothing.

wpuo_test_caps([]);
wpuo_assert_same(
	null,
	apply_filters('rest_pre_dispatch', null, null, new WPUO_Test_Request('/wp/v2/users')),
	'nothing survives this chain: a WP_Error placed on rest_pre_dispatch is discarded before dispatch'
);

// And the pipeline is capable of serving, so a denial below is a decision rather than a harness that
// refuses everything.

wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/posts'), 'a route this package does not gate is served');

// ---------------------------------------------------------------------------
// The decision survives it anyway
// ---------------------------------------------------------------------------

$denied = wpuo_test_rest_dispatch('/wp/v2/users');
wpuo_assert_true(is_wp_error($denied), 'an anonymous listing is refused with a clobbering plugin in the chain');
wpuo_assert_same('rest_user_cannot_view', $denied->get_error_code(), 'under the code consumers pin on');
wpuo_assert_same(['status' => 401], $denied->get_error_data(), 'as a 401');

$single = wpuo_test_rest_dispatch('/wp/v2/users/16');
wpuo_assert_true(is_wp_error($single), 'and so is an anonymous single-user read, which the reporter confirmed too');
wpuo_assert_same('rest_user_cannot_view', $single->get_error_code(), 'under the same code');
wpuo_assert_same(['status' => 401], $single->get_error_data(), 'and the same status');

// --- and the exemptions still hold through the same chain ------------------
//
// Breaking the block editor for an Editor is the most likely real-world regression this package can
// cause, and moving the decision to a permission callback is exactly the kind of change that would
// cause it. Every exemption is re-proved at dispatch, not merely at the verdict.

wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users', ['edit_posts']), 'an Editor still gets the author list');
wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users/16', ['edit_posts']), 'including a single-user read');
wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users', ['list_users']), 'an administrator gets normal results');
wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users/16', ['list_users']), 'including a single-user read');
wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users/me'), 'a user reading their own profile is never refused');
wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users/16/application-passwords'), 'and a sub-route is left to core');

// ---------------------------------------------------------------------------
// What the report says, and whether it is true
// ---------------------------------------------------------------------------
//
// THE REPORTER'S REAL LESSON. On their site `wpuo_report()` said the REST listing was protected
// while the endpoint served sixteen slugs, because the report described what had been REGISTERED.
// Registration succeeded. Enforcement did not. A report that cannot tell those apart is the same
// false clean bill of health as a blocked-IP report that cannot read its own log.

wpuo_test_rest_server_up();
wpuo_assert_same('in-force', wpuo_report()['rest_users'], 'with a REST server present, the report reads the live route table');

wpuo_test_rest_server_down();
wpuo_assert_same('unknown', wpuo_report()['rest_users'], 'and outside a REST request it says it cannot tell, rather than claiming success');

// --- a report that can say no ----------------------------------------------
//
// The assertion above is worth nothing unless the check can FAIL. Another plugin replacing the
// permission callback on those routes is the one way left to make this package inert, so that is
// what is staged here — and the pairing is the point: the report must say `not-in-force` in exactly
// the state where the endpoint is genuinely being served.

function wpuo_test_hostile_endpoints(mixed $endpoints): mixed {
	if (empty($GLOBALS['wpuo_test_hostile']) || ! is_array($endpoints)) {
		return $endpoints;
	}

	foreach ($endpoints as $pattern => $handlers) {
		foreach ($handlers as $key => $handler) {
			if (is_numeric($key)) {
				$endpoints[$pattern][$key]['permission_callback'] = 'wpuo_test_core_users_permission';
			}
		}
	}

	return $endpoints;
}

add_filter('rest_endpoints', 'wpuo_test_hostile_endpoints', 20, 1);

$GLOBALS['wpuo_test_hostile'] = true;
wpuo_test_rest_server_up();

wpuo_assert_same('served', wpuo_test_rest_dispatch('/wp/v2/users'), 'a plugin that replaces the permission callback does expose the listing');
wpuo_assert_same('not-in-force', wpuo_report()['rest_users'], 'and the report says so, instead of reporting the registration it still holds');

$GLOBALS['wpuo_test_hostile'] = false;
wpuo_assert_same('in-force', wpuo_report()['rest_users'], 'the same report goes back to in-force when the wrapper is back');
wpuo_assert_true(is_wp_error(wpuo_test_rest_dispatch('/wp/v2/users')), 'and the listing is refused again');

wpuo_test_rest_server_down();

wpuo_test_done('rest-users-enforcement-test');
