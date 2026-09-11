<?php
/**
 * Plugin Name: User Obscure
 * Plugin URI:  https://github.com/wp-awesome/user-obscure
 * Description: Removes the five surfaces through which WordPress hands out account names: the REST user listing, ?author=N probing, author archives, oEmbed author fields, and the login form's error message.
 * Version:     2.0.0
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 *
 * ---------------------------------------------------------------------------------------------
 *
 * AN ORDINARY PLUGIN, DELIBERATELY, UNLIKE ITS SIBLING.
 *
 * `site-access` must be must-use: it denies a request before WordPress has finished booting, and a
 * gate that can be switched off from the dashboard it protects is not a gate. Nothing here has that
 * property. All five surfaces are answered on hooks — `rest_pre_dispatch`, `parse_request`,
 * `template_redirect`, `oembed_response_data`, `authenticate` — and every one of them fires long
 * after ordinary plugins have loaded. Must-use placement would buy no earlier position.
 *
 * It would cost something, though, and the cost points the other way. The realistic failure of this
 * package is that it 401s or 404s a surface some site legitimately uses. An ordinary plugin can be
 * deactivated on the Plugins screen by the person who noticed. A must-use plugin needs shell access.
 * When the likely failure is "I broke the editor", being deactivatable IS the safety property — and
 * it is the only off switch here, which is deliberate: it is visible, it is logged, and it is
 * obviously all-or-nothing rather than one surface quietly lapsing. So this ships as a plugin and
 * installs as a plugin:
 *
 *     git submodule add https://github.com/wp-awesome/user-obscure \
 *         wp-content/plugins/user-obscure
 *
 * No stub file, because WordPress DOES recurse into `wp-content/plugins/` subdirectories — the trap
 * that makes `site-access` need one applies to `mu-plugins/` alone.
 *
 * A host that wants it must-use anyway can `require` this file from a top-level mu-plugin stub. That
 * works and is supported: nothing here reads a stored value, calls `current_user_can()`, or consults
 * a filterable value at load. `wpuo_boot()` only registers callbacks, so the load position cannot
 * change what the package does. `tests/boot-test.php` proves it by fencing off the database for the
 * whole process and booting.
 *
 * THERE IS NOTHING TO CONFIGURE FROM THE DASHBOARD, AND NO SCREEN SAYING SO. Two site-shape facts
 * are declared as constants and documented in the README; the other three surfaces are simply on. A
 * page whose only function is to tell an operator that the controls are elsewhere is a page that
 * exists to disappoint, and a control whose every position but one degrades the thing it controls is
 * an invitation to reach that position.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/src/load.php';

/**
 * Registers every surface, and reports what it registered.
 *
 * The report is memoized and returned rather than kept private, for the same reason the sibling does
 * it: a consuming project needs something behavioural to assert in its own test suite, because a
 * plugin that failed to load is silent. Memoizing also makes a second `require` harmless instead of
 * a second set of hooks.
 *
 * It lists HOOKS, and nothing else. What is in force is a different question with a different
 * answer, and `wpuo_report()` answers it on demand from inside a request.
 *
 * @return array{hooks: string[]}
 */
function wpuo_boot(): array {
	static $report = null;

	if (null !== $report) {
		return $report;
	}

	// Surface 1. Before dispatch, so the route stays registered and only the answer is gated.
	add_filter('rest_pre_dispatch', 'wpuo_rest_users_pre_dispatch', 10, 3);

	// Surfaces 2 and 3. `parse_request` decides; `template_redirect` is attached from there, only for
	// a request that matched.
	add_action('parse_request', 'wpuo_author_parse_request', 10, 1);

	// Surface 4.
	add_filter('oembed_response_data', 'wpuo_oembed_strip_author', 10, 1);

	// Surface 5.
	add_filter('authenticate', 'wpuo_login_normalize_error', 40, 1);
	add_filter('shake_error_codes', 'wpuo_login_shake_codes', 10, 1);

	$report = [
		'hooks' => [
			'rest_pre_dispatch',
			'parse_request',
			'oembed_response_data',
			'authenticate',
			'shake_error_codes',
		],
	];

	return $report;
}

wpuo_boot();
