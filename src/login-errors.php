<?php
/**
 * Surface 5: the login form's error message.
 *
 * MEASURED, AND THE SHARPEST OF THE FIVE. `POST /wp-login.php` with an account that does not exist
 * answers "not registered"; the same request with a real account and a wrong password answers "the
 * password you entered". That difference is a username oracle that needs no REST access, no author
 * archive and no permalink structure — just the login form every site has.
 *
 * WHERE IT IS NORMALISED, AND WHY NOT `login_errors`. The `login_errors` filter receives rendered
 * HTML from `wp-login.php` only. `authenticate` receives the WP_Error itself, before anything has
 * rendered, and it is the single path through which XML-RPC, the REST cookie flow and any other
 * consumer of `wp_authenticate()` also pass. Normalising the ERROR rather than the STRING flattens
 * all of them at once.
 *
 * PRIORITY 40: after every core authenticator (20 for username and email, 30 for cookies) and before
 * `wp_authenticate_spam_check` at 99, whose `spammer_account` error is not in the set below and is
 * left to mean what it says.
 *
 * WHAT IS NOT TOUCHED. `wp_login_failed` still fires, still carrying the attempted username, so a
 * rate limiter or a login-attempt log keeps working unchanged. That matters, because rate limiting
 * and 2FA are the controls that actually stop credential stuffing; this only removes a free
 * reconnaissance step, and it must not damage the controls that do the real work.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPUO_LOGIN_CODE    = 'wpuo_invalid_credentials';
const WPUO_LOGIN_MESSAGE = '<strong>Error:</strong> The username or password is not correct.';

/**
 * The error codes that differ depending on whether the account exists.
 *
 * Narrow on purpose. `empty_username`, `empty_password` and an expired session reveal nothing about
 * the account list and keep their own messages, because a login form that answers everything with
 * one sentence is a form nobody can debug.
 *
 * @return string[]
 */
function wpuo_login_identity_codes(): array {
	return ['invalid_username', 'invalid_email', 'incorrect_password'];
}

/**
 * The `authenticate` callback.
 *
 * Every code is replaced, not just the identity-revealing one, when an identity-revealing code is
 * present. Leaving a second code attached would restore the difference this exists to remove: the
 * SET of codes would then still say which branch the request took.
 *
 * @param mixed $user
 *
 * @return mixed
 */
function wpuo_login_normalize_error(mixed $user = null): mixed {
	if (! wpuo_obscuring('login_errors')) {
		return $user;
	}

	if (! is_wp_error($user)) {
		return $user;
	}

	$codes = $user->get_error_codes();

	if (! is_array($codes) || [] === array_intersect($codes, wpuo_login_identity_codes())) {
		return $user;
	}

	return new WP_Error(WPUO_LOGIN_CODE, WPUO_LOGIN_MESSAGE);
}

/**
 * Keeps the login form's shake animation.
 *
 * Cosmetic, and included because its absence is not cosmetic: core shakes for `invalid_username` and
 * `incorrect_password`, so a replacement code that core does not know would make every flattened
 * failure render differently from every other failure. Both flattened branches share one code, so
 * this adds no difference between them.
 *
 * @param mixed $codes
 *
 * @return mixed
 */
function wpuo_login_shake_codes(mixed $codes = null): mixed {
	if (! is_array($codes)) {
		return $codes;
	}

	if (! wpuo_obscuring('login_errors')) {
		return $codes;
	}

	$codes[] = WPUO_LOGIN_CODE;

	return $codes;
}
