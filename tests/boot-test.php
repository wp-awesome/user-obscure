<?php
/**
 * What loading this package does, what it does not do, and what it no longer contains.
 *
 * THE DATABASE IS FENCED OFF FOR THE WHOLE PROCESS, not merely for boot. `get_option()` throws
 * unconditionally in this suite, so every assertion below is also an assertion that the decision it
 * exercises was reached without a stored value. That fence used to be raised and lowered around the
 * load; it is permanent now, because this package has no options at all.
 *
 * THE STRUCTURAL ASSERTIONS AT THE END ARE THE POINT OF THIS FILE. Four operator toggles and the
 * screen that offered them were removed, and every one of them was a way to switch a surface off
 * silently. Behavioural tests prove the surfaces are on; these prove there is no longer a mechanism
 * capable of turning one off, which is a different claim and the one that actually holds over time.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

wpuo_test_reset();

// --- the fence is real -----------------------------------------------------
//
// Every "no database was consulted" claim in this suite is worth exactly as much as this assertion.
// A stub quietly softened to return null would make all of them vacuous and keep the suite green.

$fenced = false;

try {
	get_option('wp_user_obscure_rest_users', '');
} catch (RuntimeException $e) {
	$fenced = true;
	wpuo_assert_contains('reads no options', $e->getMessage(), 'and says why');
}

wpuo_assert_true($fenced, 'reading an option takes the suite down, so every decision below was reached without one');

require_once dirname(__DIR__) . '/user-obscure.php';

$report = wpuo_boot();

// Captured now, because every `wpuo_test_reset()` below clears the registration log and hooks are
// only ever registered once.
$registered = array_merge(wpuo_test_get('filters'), wpuo_test_get('actions'));

// --- every surface is wired ------------------------------------------------

wpuo_assert_same(
	[
		'rest_pre_dispatch',
		'parse_request',
		'oembed_response_data',
		'authenticate',
		'shake_error_codes',
	],
	$report['hooks'],
	'all five surfaces are registered'
);

foreach ($report['hooks'] as $hook) {
	wpuo_assert_true(in_array($hook, wpuo_test_hooked(), true), sprintf('%s is actually attached, not merely reported', $hook));
}

// --- the boot report states hooks and nothing else -------------------------
//
// It used to carry a `settings` key saying whether the screen had been wired. There is no screen, and
// a key that is false forever is a question whose answer stopped being interesting.

wpuo_assert_same(['hooks'], array_keys($report), 'the boot report states what was registered, and has no vestigial settings key');

// --- `template_redirect` is attached only by a request that matched --------

wpuo_assert_false(
	in_array('template_redirect', wpuo_test_hooked(), true),
	'template_redirect is attached from parse_request, only for a request that matched, never at load'
);

// --- the hook positions that are not arbitrary -----------------------------

$priorities = array_column($registered, 'priority', 'hook');

// After every core authenticator — 20 for username and email, 30 for cookies — and before
// `wp_authenticate_spam_check` at 99, whose error is not an identity leak and keeps its meaning.
wpuo_assert_same(40, $priorities['authenticate'], 'the login filter runs after core authenticates and before the spam check');

// --- booting twice is harmless ---------------------------------------------

$before = count(wpuo_test_hooked());
wpuo_assert_same($report, wpuo_boot(), 'the report is memoized');
wpuo_assert_same($before, count(wpuo_test_hooked()), 'and a second boot registers no second set of hooks');

// --- what is in force, for a consuming project ----------------------------
//
// This process declares nothing, so it is the shape a site gets from a bare install: the two
// unconditional surfaces on, REST users obscured because obscure is the default direction, and the
// author pair off because author archives are presumed in use until somebody says otherwise.

wpuo_test_reset();
wpuo_assert_same(
	[
		'rest_users'      => true,
		'author_probe'    => false,
		'author_archives' => 'used',
		'oembed'          => true,
		'login_errors'    => true,
	],
	wpuo_report(),
	'a host that declares nothing gets the default posture, read without touching a database'
);

// --- NOT FAIL-OPEN: the registered callbacks are the real ones -------------

wpuo_test_reset();

$checked = 0;

foreach ($registered as $entry) {
	if ('oembed_response_data' !== $entry['hook']) {
		continue;
	}

	$checked++;
	wpuo_assert_false(
		array_key_exists('author_name', ($entry['callback'])(['author_name' => 'Andrea Lam'])),
		'the callback attached to oembed_response_data is the one that actually strips'
	);
}

wpuo_assert_same(1, $checked, 'and that assertion ran against exactly one registered callback');

// ---------------------------------------------------------------------------
// What this package no longer contains
// ---------------------------------------------------------------------------

$sources = wpuo_test_sources();

wpuo_assert_same(
	[
		'src/author-requests.php',
		'src/config.php',
		'src/load.php',
		'src/login-errors.php',
		'src/oembed.php',
		'src/rest-users.php',
		'src/surfaces.php',
		'user-obscure.php',
	],
	$sources,
	'the file list is exactly these, and src/settings.php is not among them'
);

// --- nothing reads or writes an option ------------------------------------
//
// The fence above proves no code path REACHED an option during this run. This proves there is no
// such code path to reach, including in a branch no test happens to exercise.

foreach ($sources as $source) {
	$code = wpuo_test_source($source);

	wpuo_assert_not_contains('get_option', $code, sprintf('%s reads no option', $source));
	wpuo_assert_not_contains('update_option', $code, sprintf('%s writes no option', $source));
	wpuo_assert_not_contains('wp_user_obscure_', $code, sprintf('%s names none of the retired option keys', $source));
}

// --- there is no settings screen, and no half of one -----------------------
//
// Not a vestigial page saying "configured in code", either. A screen whose only function is to
// disappoint is worse than no screen: it is a place an operator goes looking for a control.

foreach ($sources as $source) {
	$code = wpuo_test_source($source);

	foreach (['add_options_page', 'register_setting', 'add_menu_page', 'admin_menu', 'admin_init', 'sanitize_callback'] as $needle) {
		wpuo_assert_not_contains($needle, $code, sprintf('%s registers no settings screen (%s)', $source, $needle));
	}
}

foreach (['wpuo_settings_bootstrap', 'wpuo_settings_register', 'wpuo_settings_menu', 'wpuo_settings_render_page', 'wpuo_sanitize_toggle', 'wpuo_stored', 'wpuo_stored_is_readable', 'wpuo_surfaces', 'wpuo_obscuring', 'wpuo_option_key'] as $gone) {
	wpuo_assert_false(function_exists($gone), sprintf('%s() no longer exists', $gone));
}

// --- there are exactly two host inputs, and this is the list ---------------
//
// THIS IS WHAT MAKES THE INCOHERENT AUTHOR STATE UNREACHABLE RATHER THAN MERELY UNTESTED. The probe
// and the author archive were separately controllable, and the combination that shipped as the
// default was a 301 pointing at a 404. The probe now has no input of its own — not an option, not a
// constant, nothing — and this assertion is how that stays true. A future `WP_USER_OBSCURE_AUTHOR_
// PROBE`, however well meant, fails here by name.

$inputs = [];

foreach ($sources as $source) {
	preg_match_all('/WP_USER_OBSCURE_[A-Z_]+/', wpuo_test_source($source), $matches);
	$inputs = array_merge($inputs, $matches[0]);
}

$inputs = array_values(array_unique($inputs));
sort($inputs);

wpuo_assert_same(
	[
		'WP_USER_OBSCURE_AUTHOR_ARCHIVES',
		'WP_USER_OBSCURE_REST_USERS',
	],
	$inputs,
	'this package has exactly two host inputs, both of them site-shape declarations'
);

// --- no environment override exists ----------------------------------------
//
// The sibling's `WP_AWESOME_GATE_PRODUCTION_NOOP` was removed for making a settings page lie. There
// is no page to lie to now, but the rule it stood for outlives the page: nothing may make this
// package inert behind the operator's back.

foreach ($sources as $source) {
	$code = wpuo_test_source($source);

	wpuo_assert_not_contains('wp_get_environment_type', $code, sprintf('%s consults no environment', $source));
	wpuo_assert_not_contains('WP_ENVIRONMENT_TYPE', $code, sprintf('%s consults no environment constant', $source));
	wpuo_assert_not_contains('NOOP', $code, sprintf('%s has no way to be made inert behind the operator\'s back', $source));
}

wpuo_test_done('boot-test');
