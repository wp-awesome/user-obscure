<?php
/**
 * Host configuration.
 *
 * Two kinds of configuration live in this package, and the split is deliberate. Site-SHAPE facts —
 * things only somebody who has read the theme's templates can answer — enter through a constant the
 * HOST defines. Operator DECISIONS — things the person who runs the site should be able to change
 * and immediately see the effect of — live in the database behind the settings screen. The README
 * argues the split surface by surface; this file only implements the reading of the first kind.
 *
 * Every constant is optional and every default is neutral, so a host that defines nothing gets a
 * working plugin that obscures nothing until somebody asks it to.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Resolves one of the four option keys the host may rename.
 *
 * Keys are injectable for the same reason the sibling package makes them injectable: a site that
 * already stores one of these toggles under its own key should not need a migration to adopt this.
 * Unlike the sibling there is no legacy schema to come from, so most hosts should define none of
 * these and take the defaults.
 *
 * An unknown name returns an empty string, and every caller treats an empty key as "no setting",
 * which resolves to "do not obscure". A typo therefore cannot enable a surface.
 */
function wpuo_option_key(string $name): string {
	$constants = [
		'rest_users'   => ['WP_USER_OBSCURE_OPTION_REST_USERS', 'wp_user_obscure_rest_users'],
		'author_probe' => ['WP_USER_OBSCURE_OPTION_AUTHOR_PROBE', 'wp_user_obscure_author_probe'],
		'oembed'       => ['WP_USER_OBSCURE_OPTION_OEMBED', 'wp_user_obscure_oembed'],
		'login_errors' => ['WP_USER_OBSCURE_OPTION_LOGIN_ERRORS', 'wp_user_obscure_login_errors'],
	];

	if (! isset($constants[$name])) {
		return '';
	}

	[$constant, $default] = $constants[$name];

	return trim(wpuo_setting($constant, $default));
}

/**
 * Reads a host string constant, falling back to a neutral default.
 *
 * TOTAL BY CONSTRUCTION. A PHP constant can hold an array, and casting an array to string emits a
 * warning while casting an object that has no `__toString()` is a fatal Error. This package runs on
 * every request of a site it is not allowed to take down, so a constant whose value is not a scalar
 * is treated as undeclared rather than cast. The malformed direction is the neutral default, always.
 */
function wpuo_setting(string $constant, string $default = ''): string {
	if (! defined($constant)) {
		return $default;
	}

	$value = constant($constant);

	return is_scalar($value) ? (string) $value : $default;
}
