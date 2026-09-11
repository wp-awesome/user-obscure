<?php
/**
 * What loading this package does, and — more importantly — what it does not do.
 *
 * THE DATABASE IS FENCED OFF FOR THE WHOLE OF BOOT. `get_option()` and `update_option()` throw while
 * the fence is up, so this is not a claim that loading touches no database: it is the load failing
 * loudly if it ever starts to.
 *
 * That property is what makes the load position a free choice. The sibling package carries a
 * documented residual from the opposite arrangement — it derives the login path from `wp_login_url()`
 * at mu-plugin load, where a hide-login plugin's filter is not yet attached, so a relocated login
 * form stays reachable. Nothing here reads a filtered value, an option or a capability at load; every
 * one of those reads happens inside a callback, by which time the whole filter chain exists. There is
 * no equivalent residual, and this test is how that stays true.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

// --- booting reads nothing ------------------------------------------------

wpuo_test_reset();
wpuo_test_set('option_guard', true);

require_once dirname(__DIR__) . '/user-obscure.php';

$report = wpuo_boot();

wpuo_test_set('option_guard', false);

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

wpuo_assert_false($report['settings'], 'the settings screen is not wired on a front-end request');

foreach ($report['hooks'] as $hook) {
	wpuo_assert_true(in_array($hook, wpuo_test_hooked(), true), sprintf('%s is actually attached, not merely reported', $hook));
}

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

// --- the report is hooks, not settings -------------------------------------
//
// Stating the settings in the boot report would mean reading the database at load, which is the one
// thing the fence above exists to forbid. That question has its own function, answered on demand.

wpuo_assert_same(['hooks', 'settings'], array_keys($report), 'the boot report states what was registered, not what is switched on');

wpuo_test_reset(['wp_user_obscure_rest_users' => '1']);
wpuo_assert_true(wpuo_report()['rest_users'], 'and wpuo_report() answers the other question, from inside a request');

// --- NOT FAIL-OPEN: the registered callbacks are the real ones -------------

wpuo_test_reset(['wp_user_obscure_oembed' => '1']);

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

wpuo_test_done('boot-test');
