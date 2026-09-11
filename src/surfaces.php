<?php
/**
 * Which surfaces are being obscured, and how that question is answered.
 *
 * THE RULE THAT MATTERS, AND IT IS THE OPPOSITE OF THE SIBLING PACKAGE'S. A value this file cannot
 * read resolves to "do not obscure". `site-access` resolves an unreadable value to its most
 * restrictive scope, because the thing it protects — unreleased content behind a gate — is worth a
 * dark site. This package protects a reconnaissance step, not a secret: WordPress treats usernames
 * as non-secret by design and the controls that actually stop credential stuffing are rate limiting
 * and 2FA. Turning a surface ON after a corrupt restore would 401 an integration or 404 a published
 * URL in exchange for a marginal benefit. So this one fails towards the site working, and that
 * asymmetry is a decision, not an oversight.
 *
 * FAIL CLOSED BY CONTRIBUTING NOTHING, NEVER BY THROWING. Nothing below can raise: no array is cast
 * to string, no undeclared constant is read, no option value is trusted to be a string.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPUO_ARCHIVES_USED   = 'used';
const WPUO_ARCHIVES_UNUSED = 'unused';

/**
 * The four surfaces the operator controls from the settings screen.
 *
 * @return string[]
 */
function wpuo_surfaces(): array {
	return ['rest_users', 'author_probe', 'oembed', 'login_errors'];
}

/**
 * Whether one operator-controlled surface is being obscured right now.
 *
 * Read on demand inside a hook callback, never at load. Two consequences, both wanted: this package
 * can be loaded at any point in the boot sequence without the load order changing what it does, and
 * a settings change takes effect on the next request without anything having to be re-registered.
 *
 * ONLY THE LITERAL '1' IS ON. Missing, empty, '0', 'on', 'true', 'yes', ' 1 ', an array, an object
 * and null are all the same case: off. The checkbox cannot produce any of them, so a value that
 * arrives here in one of those shapes came from a restore, a hand-edited row or another plugin, and
 * the safe reading of a value nobody chose is "nothing was asked for".
 */
function wpuo_obscuring(string $surface): bool {
	if (! in_array($surface, wpuo_surfaces(), true)) {
		return false;
	}

	$key = wpuo_option_key($surface);

	if ('' === $key) {
		return false;
	}

	$stored = get_option($key, '');

	// is_scalar() first, and there is no else branch that casts. An array option would warn and an
	// object option would fatal.
	return is_scalar($stored) && '1' === (string) $stored;
}

/**
 * Whether this site uses author archives — a DECLARATION, not a setting.
 *
 * It is a constant because the answer is a fact about the theme's templates and about URLs that are
 * already published and indexed, and nobody can verify it from the dashboard. Declaring `unused`
 * wrongly 404s pages that exist, and that damage is diffuse and slow to notice. See the README.
 *
 * Anything other than the exact string `unused` resolves to `used`, which contributes nothing. That
 * includes the near-misses a developer actually makes — `'Unused'`, `'no'`, `false`, an array — and
 * it is why this is not read with a boolean cast: `(bool) 'unused'` is `true`, so a boolean-shaped
 * constant would resolve the most likely typo in the direction that breaks the site.
 */
function wpuo_author_archives(): string {
	$declared = wpuo_setting('WP_USER_OBSCURE_AUTHOR_ARCHIVES', WPUO_ARCHIVES_USED);

	return WPUO_ARCHIVES_UNUSED === $declared ? WPUO_ARCHIVES_UNUSED : WPUO_ARCHIVES_USED;
}

/**
 * Whether author archives are to be 404ed. Separate from the declaration above so the settings
 * screen can show the operator what was declared even when nothing is being obscured.
 */
function wpuo_obscuring_author_archives(): bool {
	return WPUO_ARCHIVES_UNUSED === wpuo_author_archives();
}

/**
 * What is in force, for a consuming project's own test suite and for the settings screen.
 *
 * @return array{rest_users: bool, author_probe: bool, author_archives: string, oembed: bool, login_errors: bool}
 */
function wpuo_report(): array {
	return [
		'rest_users'      => wpuo_obscuring('rest_users'),
		'author_probe'    => wpuo_obscuring('author_probe'),
		'author_archives' => wpuo_author_archives(),
		'oembed'          => wpuo_obscuring('oembed'),
		'login_errors'    => wpuo_obscuring('login_errors'),
	];
}
