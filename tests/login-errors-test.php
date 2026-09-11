<?php
/**
 * Surface 5: the login form's error message.
 *
 * THE CENTRAL ASSERTION IS AN EQUALITY BETWEEN TWO BRANCHES, not a property of either one. An
 * unknown username and a wrong password must produce the same code AND the same message. Asserting
 * only that the message no longer says "not registered" would pass against a build that answered
 * with two different new messages, which is the same oracle wearing new words.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

const WPUO_LOGIN_KEY = 'wp_user_obscure_login_errors';

function wpuo_test_login(WP_Error|string|null $error, mixed $stored = '1'): mixed {
	wpuo_test_reset([WPUO_LOGIN_KEY => $stored]);

	return wpuo_login_normalize_error($error);
}

function wpuo_test_unknown_user(): WP_Error {
	return new WP_Error('invalid_username', '<strong>Error:</strong> The username <strong>site_admin</strong> is not registered on this site.');
}

function wpuo_test_wrong_password(): WP_Error {
	return new WP_Error('incorrect_password', '<strong>Error:</strong> The password you entered for the username <strong>site_admin</strong> is incorrect.');
}

// --- the two branches are identical ---------------------------------------

$unknown = wpuo_test_login(wpuo_test_unknown_user());
$wrong   = wpuo_test_login(wpuo_test_wrong_password());

wpuo_assert_same($unknown->get_error_codes(), $wrong->get_error_codes(), 'an unknown user and a wrong password share one error code');
wpuo_assert_same($unknown->get_error_message(), $wrong->get_error_message(), 'and one message, byte for byte');
wpuo_assert_same(WPUO_LOGIN_CODE, $unknown->get_error_code(), 'the shared code is this package\'s own');

// The username the attacker typed is their own input, but it must not be echoed back inside an error
// that is supposed to carry no information.
wpuo_assert_not_contains('site_admin', $unknown->get_error_message(), 'the flattened message names no account');
wpuo_assert_not_contains('not registered', $unknown->get_error_message(), 'and does not say the account is unknown');
wpuo_assert_not_contains('password you entered', $wrong->get_error_message(), 'and does not say the password was wrong');

// --- an email address is the same leak ------------------------------------

$email = wpuo_test_login(new WP_Error('invalid_email', 'Unknown email address. Check again or try your username.'));
wpuo_assert_same($unknown->get_error_message(), $email->get_error_message(), 'logging in by email is flattened to the same message');

// --- every code is replaced when one of them reveals identity --------------
//
// Leaving a second code attached would restore the difference: the SET of codes would still say
// which branch the request took.

$multi = new WP_Error('incorrect_password', 'wrong password');
$multi->add('some_plugin_hint', 'you last logged in from Shanghai');
$flattened = wpuo_test_login($multi);
wpuo_assert_same([WPUO_LOGIN_CODE], $flattened->get_error_codes(), 'a second code attached alongside is replaced too');

// --- errors that reveal nothing keep their meaning -------------------------
//
// A login form that answers everything with one sentence is a form nobody can debug.

foreach (['empty_username', 'empty_password', 'expired_session', 'spammer_account', 'test_cookie'] as $code) {
	$other = new WP_Error($code, 'a message that names no account');
	wpuo_assert_same($code, wpuo_test_login($other)->get_error_code(), sprintf('%s is not an identity leak and is left alone', $code));
}

// --- a successful login is not an error -----------------------------------

wpuo_assert_same(null, wpuo_test_login(null), 'a null authenticate result is passed through');
wpuo_assert_same('a user object', wpuo_test_login('a user object'), 'a resolved user is passed through untouched');

// --- switched off: the leak is left exactly as core wrote it ---------------

$off_unknown = wpuo_test_login(wpuo_test_unknown_user(), '0');
$off_wrong   = wpuo_test_login(wpuo_test_wrong_password(), '0');
wpuo_assert_same('invalid_username', $off_unknown->get_error_code(), 'an unticked setting leaves core\'s own code');
wpuo_assert_contains('not registered', $off_unknown->get_error_message(), 'and core\'s own message');
wpuo_assert_false(
	$off_unknown->get_error_message() === $off_wrong->get_error_message(),
	'which is, measurably, the oracle this surface exists to close'
);

// --- the shake codes ------------------------------------------------------
//
// Cosmetic, and its absence is not: core shakes for invalid_username and incorrect_password, so a
// replacement code core does not know would make every flattened failure render unlike every other
// failure on the form.

wpuo_test_reset([WPUO_LOGIN_KEY => '1']);
wpuo_assert_true(in_array(WPUO_LOGIN_CODE, wpuo_login_shake_codes(['invalid_username']), true), 'the flattened code shakes the form like core\'s do');
wpuo_test_reset([WPUO_LOGIN_KEY => '0']);
wpuo_assert_same(['invalid_username'], wpuo_login_shake_codes(['invalid_username']), 'and is not added when the surface is not obscured');
wpuo_test_reset([WPUO_LOGIN_KEY => '1']);
wpuo_assert_same(null, wpuo_login_shake_codes(null), 'a non-array from another plugin is returned untouched and does not raise');

// --- a malformed setting contributes nothing, both directions asserted -----

foreach (['', '0', 'on', 'true', ['1'], new stdClass()] as $bad) {
	$result = wpuo_test_login(wpuo_test_unknown_user(), $bad);

	wpuo_assert_same('invalid_username', $result->get_error_code(), 'a malformed setting leaves the login error as core wrote it');
	wpuo_assert_contains('not registered', $result->get_error_message(), 'message included');

	// Not fail-open: the mechanism is still live.
	wpuo_assert_same(
		WPUO_LOGIN_CODE,
		wpuo_test_login(wpuo_test_unknown_user())->get_error_code(),
		'and the flattening still works when the setting is valid'
	);
}

wpuo_test_done('login-errors-test');
