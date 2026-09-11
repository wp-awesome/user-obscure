<?php
/**
 * The settings screen: what it saves, and what it refuses to pretend it can change.
 *
 * THE SCREEN MUST MEAN WHAT IT SAYS. The sibling package shipped a constant that made its gate inert
 * on production while its settings page went on offering three modes; it was removed for lying to
 * the operator. Nothing equivalent exists here, and the assertions below are what keeps it that way:
 * a ticked box obscures its surface, and the one thing this screen cannot change is rendered as a
 * statement of fact rather than as a disabled control.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_OPTION_REST_USERS   = 'test_rest';
const WP_USER_OBSCURE_OPTION_AUTHOR_PROBE = 'test_probe';
const WP_USER_OBSCURE_OPTION_OEMBED       = 'test_oembed';
const WP_USER_OBSCURE_OPTION_LOGIN_ERRORS = 'test_login';

require_once dirname(__DIR__) . '/src/load.php';

function wpuo_test_render(): string {
	ob_start();
	wpuo_settings_render_page();

	return (string) ob_get_clean();
}

// --- registration ----------------------------------------------------------

wpuo_test_reset([], ['manage_options']);
wpuo_settings_register();

$registered = wpuo_test_get('settings')['options'];

wpuo_assert_same(
	['test_rest', 'test_probe', 'test_oembed', 'test_login'],
	array_keys($registered),
	'all four surfaces are registered, under the host\'s own option keys'
);
wpuo_assert_same(
	1,
	count(array_unique(array_column($registered, 'group'))),
	'every control belongs to the same settings group, so one Save button saves the screen'
);

wpuo_settings_menu();
wpuo_assert_same('manage_options', wpuo_test_get('settings')['page']['capability'], 'the screen requires manage_options');

wpuo_test_reset([], ['edit_posts']);
wpuo_assert_same('', wpuo_test_render(), 'and renders nothing to a user who does not have it');

// --- the checkbox ----------------------------------------------------------
//
// An unticked checkbox posts nothing at all, so the absent case must store '0' rather than be left
// alone — otherwise a surface could never be switched back off from this screen.

wpuo_assert_same('1', wpuo_sanitize_toggle('1'), 'a ticked box stores 1');
wpuo_assert_same('0', wpuo_sanitize_toggle(null), 'an unticked box stores 0 rather than nothing');
wpuo_assert_same('0', wpuo_sanitize_toggle('on'), 'a browser default of "on" is not 1 and is stored as 0');
wpuo_assert_same('0', wpuo_sanitize_toggle(''), 'an empty submission stores 0');
wpuo_assert_same('0', wpuo_sanitize_toggle(['1']), 'an array submission stores 0 and does not raise');
wpuo_assert_same('0', wpuo_sanitize_toggle(new stdClass()), 'an object submission stores 0 and does not raise');

// What it stores must be what the resolver reads back as ON. The two halves are written in different
// files and nothing but this assertion connects them.
wpuo_test_reset(['test_rest' => wpuo_sanitize_toggle('1')]);
wpuo_assert_true(wpuo_obscuring('rest_users'), 'what the screen saves is what the resolver reads as obscured');
wpuo_test_reset(['test_rest' => wpuo_sanitize_toggle(null)]);
wpuo_assert_false(wpuo_obscuring('rest_users'), 'and unticking it switches the surface back off');

// --- the screen shows the state it is actually in --------------------------

wpuo_test_reset(['test_rest' => '1', 'test_probe' => '0', 'test_oembed' => '0', 'test_login' => '0'], ['manage_options']);
$html = wpuo_test_render();

wpuo_assert_contains('name="test_rest" value="1" checked=', $html, 'an obscured surface renders ticked');
wpuo_assert_contains('name="test_probe" value="1">', $html, 'and one that is not renders unticked');
wpuo_assert_not_contains('cannot have written', $html, 'and a readable option raises no warning');

// --- a stored value the screen cannot have written is reported -------------
//
// "Off because nobody ticked it" and "off because the row holds something unreadable" are different
// states. A screen that renders them identically is how a control stays broken for months.

foreach ([['nonsense from a restore'], 'on', 'true', new stdClass()] as $corrupt) {
	wpuo_test_reset(['test_rest' => $corrupt], ['manage_options']);

	wpuo_assert_false(wpuo_stored_is_readable('rest_users'), 'a value outside "", 0 and 1 is reported as unreadable');
	wpuo_assert_false(wpuo_obscuring('rest_users'), 'and contributes nothing');

	$html = wpuo_test_render();
	wpuo_assert_contains('cannot have written', $html, 'the screen says so, in the row it applies to');
	wpuo_assert_contains('NOT obscured', $html, 'and says which way it resolved');
}

foreach (['', '0', '1'] as $fine) {
	wpuo_test_reset(['test_rest' => $fine], ['manage_options']);
	wpuo_assert_true(wpuo_stored_is_readable('rest_users'), sprintf('%s is a value this screen can have written', var_export($fine, true)));
	wpuo_assert_not_contains('cannot have written', wpuo_test_render(), 'so no warning is shown');
}

// --- the author-archive declaration is stated, never offered ---------------

wpuo_test_reset([], ['manage_options']);
$html = wpuo_test_render();

wpuo_assert_contains('Declared in use', $html, 'the declaration is shown to the operator as a fact');
wpuo_assert_contains('WP_USER_OBSCURE_AUTHOR_ARCHIVES', $html, 'named, so a developer can be asked for the right thing');
wpuo_assert_not_contains('name="wp_user_obscure_author_archives"', $html, 'and never rendered as a form control this screen cannot honour');
wpuo_assert_not_contains('disabled', $html, 'not even a disabled one, which reads as "you lack permission" rather than "this is code"');

// --- the screen is honest about what the package is worth ------------------

wpuo_assert_contains('rate limiting', $html, 'the screen states what actually stops a password attack');
wpuo_assert_contains('two-factor', $html, 'both of them');

// --- no environment override exists ----------------------------------------
//
// The sibling's `WP_AWESOME_GATE_PRODUCTION_NOOP` was removed for making a settings page lie.
// Reintroducing an equivalent here fails this by name.

foreach (['user-obscure.php', 'src/config.php', 'src/surfaces.php', 'src/settings.php'] as $source) {
	$code = (string) file_get_contents(dirname(__DIR__) . '/' . $source);

	wpuo_assert_not_contains('wp_get_environment_type', $code, sprintf('%s consults no environment', $source));
	wpuo_assert_not_contains('WP_ENVIRONMENT_TYPE', $code, sprintf('%s consults no environment constant', $source));
	wpuo_assert_not_contains('NOOP', $code, sprintf('%s has no way to be made inert behind the operator\'s back', $source));
}

wpuo_test_done('settings-test');
