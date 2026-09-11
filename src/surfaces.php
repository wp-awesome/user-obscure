<?php
/**
 * Which surfaces are obscured, and how the two that can vary are answered.
 *
 * THREE SURFACES HAVE NO SWITCH. The oEmbed byline and the login-error message are obscured on every
 * site, unconditionally; `?author=N` is derived, below, from the author-archive declaration. Nothing
 * here reads a stored value, so nothing a database holds — including four rows this package once
 * wrote and no longer looks at — can change what any surface does.
 *
 * TWO DECLARATIONS REMAIN, AND EACH FAILS IN THE DIRECTION IT CAN AFFORD. Both are read with an
 * exact comparison against one spelling, never with a boolean cast, because `(bool) 'unused'` and
 * `(bool) 'Unused'` are both `true`: a boolean-shaped constant resolves the likeliest typo to
 * whichever direction `true` happens to mean, and there is no arrangement in which both spellings
 * fall safe. An exact comparison has exactly one failure direction, so the spelling that is matched
 * exactly is the one whose failure the surface can survive.
 *
 * For author archives that is `unused`: anything else means the archives keep serving, because
 * 404ing published, linked, possibly indexed URLs is diffuse damage nobody notices for weeks. For
 * the REST user collection it is the other way: `used` must be spelled exactly to expose the
 * listing, and every other value obscures it. A headless front end that breaks fails loudly, in
 * front of the developer who built it, within a minute of deploying.
 *
 * FAIL CLOSED BY CONTRIBUTING NOTHING, NEVER BY THROWING. Nothing below can raise: no array is cast
 * to string and no undeclared constant is read.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPUO_ARCHIVES_USED   = 'used';
const WPUO_ARCHIVES_UNUSED = 'unused';

const WPUO_REST_USERS_USED   = 'used';
const WPUO_REST_USERS_UNUSED = 'unused';

/**
 * Whether this site uses author archives — a DECLARATION, not a setting.
 *
 * It is a constant because the answer is a fact about the theme's templates and about URLs that are
 * already published and indexed, and nobody can verify it from the dashboard. Declaring `unused`
 * wrongly 404s pages that exist, and that damage is diffuse and slow to notice. See the README.
 *
 * Anything other than the exact string `unused` resolves to `used`, which contributes nothing.
 */
function wpuo_author_archives(): string {
	$declared = wpuo_setting('WP_USER_OBSCURE_AUTHOR_ARCHIVES', WPUO_ARCHIVES_USED);

	return WPUO_ARCHIVES_UNUSED === $declared ? WPUO_ARCHIVES_UNUSED : WPUO_ARCHIVES_USED;
}

/**
 * Whether author archives are to be 404ed.
 */
function wpuo_obscuring_author_archives(): bool {
	return WPUO_ARCHIVES_UNUSED === wpuo_author_archives();
}

/**
 * Whether `?author=N` is to be 404ed. DERIVED, and derived is the whole of the reasoning.
 *
 * This was a separate control once, and the two were independently settable, which made an
 * incoherent state reachable — and shipped it as the default. Measured on a real site with archives
 * declared unused and the probe left alone:
 *
 *     ?author=1            301 -> /author/site_admin/
 *     /author/site_admin/  404
 *
 * A redirect handing over the login slug and then pointing at nothing. The two are not two
 * questions. If archives are unused there is nowhere for the probe to resolve to and it must 404; if
 * archives are used, redirecting is correct core behaviour and 404ing it breaks an entry point core
 * itself relies on. One reads off the other, so the broken pair can no longer be expressed.
 */
function wpuo_obscuring_author_probe(): bool {
	return wpuo_obscuring_author_archives();
}

/**
 * Whether this site's front end reads the REST user collection anonymously — a DECLARATION.
 *
 * The same kind of question as author archives, with the same kind of answer: a headless front end,
 * or a JS theme rendering bylines from `/wp/v2/users` without a cookie, is a fact about how the site
 * was built. It is not an operator preference, and nothing useful is learned by ticking it and
 * watching.
 *
 * The default is `unused`, so a site that declares nothing is obscured. Only the exact string `used`
 * exposes the collection.
 */
function wpuo_rest_users(): string {
	$declared = wpuo_setting('WP_USER_OBSCURE_REST_USERS', WPUO_REST_USERS_UNUSED);

	return WPUO_REST_USERS_USED === $declared ? WPUO_REST_USERS_USED : WPUO_REST_USERS_UNUSED;
}

/**
 * Whether the REST user collection is to be gated.
 */
function wpuo_obscuring_rest_users(): bool {
	return WPUO_REST_USERS_UNUSED === wpuo_rest_users();
}

/**
 * What is in force, for a consuming project's own test suite.
 *
 * REGISTERED IS NOT IN FORCE, AND THIS REPORT ONCE CONFLATED THEM. `rest_users` was a boolean
 * meaning "this package intends to obscure the listing". On a consuming project's production site it
 * was `true` while `/wp-json/wp/v2/users` served sixteen login slugs, because a third-party filter
 * discarded the refusal after it was built. The report was not wrong about anything it actually
 * knew. It reported an intention and was read as a guarantee — the same false clean bill of health
 * as a blocked-IP report that cannot read its own log.
 *
 * So `rest_users` no longer answers "did we register". It answers "is this package's decision on the
 * route the REST server will serve", by reading the route table back after every other plugin has
 * filtered it — or `unknown` when there is no REST server in this request to read, which is the
 * honest answer outside one and must not be read as either success or failure.
 *
 * THE OTHER KEYS ARE NOT ENFORCEMENT CLAIMS AND MUST NOT BE READ AS ONE. `author_archives` and
 * `author_probe` are DECLARATIONS: what this site said about its own shape. `oembed` and
 * `login_errors` say only that the callback is attached right now, which catches a `remove_filter()`
 * by something else and catches a package that never booted — it cannot catch a later callback
 * throwing the result away, because a filter chain's future behaviour is not inspectable the way a
 * route table is. The README says how to check those two from outside.
 *
 * @return array{rest_users: string, author_probe: bool, author_archives: string, oembed: bool, login_errors: bool}
 */
function wpuo_report(): array {
	return [
		'rest_users'      => wpuo_rest_users_enforcement(),
		'author_probe'    => wpuo_obscuring_author_probe(),
		'author_archives' => wpuo_author_archives(),
		'oembed'          => has_filter('oembed_response_data', 'wpuo_oembed_strip_author'),
		'login_errors'    => has_filter('authenticate', 'wpuo_login_normalize_error'),
	];
}
