<?php
/**
 * Surface 5: the login form's error message. UNCONDITIONAL.
 *
 * THE CENTRAL ASSERTION IS AN EQUALITY BETWEEN TWO BRANCHES, not a property of either one. An
 * unknown username and a wrong password must produce the same code AND the same message. Asserting
 * only that the message no longer says "not registered" would pass against a build that answered
 * with two different new messages, which is the same oracle wearing new words.
 *
 * WHY THERE IS NOTHING TO SWITCH OFF. This is the sharpest of the five leaks — a username oracle
 * needing no REST access, no permalink structure and no author archive, just the form every site
 * has. What flattening costs is that a user who mistypes their USERNAME gets a less specific
 * message; they are told the pair is wrong rather than which half. That is real and it is small, and
 * it is smaller than the leak. An off switch here would be a switch whose only function is to
 * restore the oracle.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

function wpuo_test_login(WP_Error|string|null $error): mixed {
	wpuo_test_reset();

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

// The message still has to be usable by the person who simply mistyped something, which is the cost
// this surface actually imposes and the reason it is worth stating plainly.
wpuo_assert_contains('username or password', $unknown->get_error_message(), 'while still telling an honest user which pair to check');

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
	wpuo_assert_same('a message that names no account', wpuo_test_login($other)->get_error_message(), sprintf('%s keeps its own message', $code));
}

// --- a successful login is not an error -----------------------------------

wpuo_assert_same(null, wpuo_test_login(null), 'a null authenticate result is passed through');
wpuo_assert_same('a user object', wpuo_test_login('a user object'), 'a resolved user is passed through untouched');

// --- the shake codes ------------------------------------------------------
//
// Cosmetic, and its absence is not: core shakes for invalid_username and incorrect_password, so a
// replacement code core does not know would make every flattened failure render unlike every other
// failure on the form.

wpuo_test_reset();
wpuo_assert_true(in_array(WPUO_LOGIN_CODE, wpuo_login_shake_codes(['invalid_username']), true), 'the flattened code shakes the form like core\'s do');
wpuo_assert_true(in_array('invalid_username', wpuo_login_shake_codes(['invalid_username']), true), 'and core\'s own codes are kept, not replaced');
wpuo_assert_same(null, wpuo_login_shake_codes(null), 'a non-array from another plugin is returned untouched and does not raise');

// --- the flattening does not depend on anything ----------------------------
//
// No option — `get_option()` throws for the whole of this process — and no host constant either.
// This is the structural half of "unconditional": the assertions above would still pass if a
// condition existed and happened to be true, and the condition is what used to leak.

$code = wpuo_test_source('src/login-errors.php');

foreach (['wpuo_setting', 'wpuo_obscuring', 'WP_USER_OBSCURE', 'get_option'] as $needle) {
	wpuo_assert_not_contains($needle, $code, sprintf('the login surface consults nothing (%s)', $needle));
}

// --- and it does not depend on who is asking -------------------------------

wpuo_test_reset(['list_users', 'edit_posts', 'manage_options']);
wpuo_test_set('is_admin', true);
wpuo_assert_same(
	WPUO_LOGIN_CODE,
	wpuo_login_normalize_error(wpuo_test_unknown_user())->get_error_code(),
	'a failed login is flattened whatever state the request is in'
);

wpuo_test_done('login-errors-test');
