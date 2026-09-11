<?php
/**
 * Surfaces 2 and 3, on a site whose declaration is a NEAR MISS.
 *
 * `'Unused'` is the typo a developer actually makes, and this file pins which way it falls: towards
 * the site working. Author archives keep serving, and `?author=N` keeps redirecting to them, which
 * is coherent — the wrong answer, but a coherent wrong answer that a developer can see and correct,
 * rather than a redirect pointing at a 404.
 *
 * This is also the reason the declaration is a two-value STRING rather than a boolean. `(bool)
 * 'Unused'` is `true`, and `(bool) 'unused'` is `true` as well — a boolean-shaped constant resolves
 * BOTH near misses to whichever direction `true` happens to mean, and there is no way to arrange it
 * so that both fall safe. An exact comparison against one spelling has exactly one failure
 * direction, and for this surface it is this one.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_AUTHOR_ARCHIVES = 'Unused';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param array<string, mixed> $query_vars
 */
function wpuo_test_parse(array $query_vars): string {
	wpuo_test_reset();

	$wp             = new stdClass();
	$wp->query_vars = $query_vars;

	wpuo_author_parse_request($wp);
	wpuo_author_send_404();

	return (string) ($GLOBALS['wpuo_author_404'] ?? '');
}

function wpuo_test_was_404(): bool {
	return $GLOBALS['wp_query']->is_404 && in_array(404, wpuo_test_get('headers'), true);
}

// --- the near miss resolves towards the site working ----------------------

wpuo_assert_same(WPUO_ARCHIVES_USED, wpuo_author_archives(), 'a near-miss declaration is read as "in use"');

// --- LOCKSTEP over the near-miss resolution -------------------------------

wpuo_assert_false(wpuo_obscuring_author_archives(), 'so the archive surface contributes nothing');
wpuo_assert_false(wpuo_obscuring_author_probe(), 'and neither does the probe, which still has somewhere to point');
wpuo_assert_same(
	wpuo_obscuring_author_archives(),
	wpuo_obscuring_author_probe(),
	'a typo cannot separate the two, any more than a correct spelling can'
);

foreach (['site_admin', 'nosuchuser'] as $slug) {
	wpuo_assert_same('', wpuo_test_parse(['author_name' => $slug]), sprintf('/author/%s/ is served normally', $slug));
	wpuo_assert_false(wpuo_test_was_404(), sprintf('/author/%s/ gets no 404 of this package\'s making', $slug));
}

wpuo_assert_same('', wpuo_test_parse(['author' => '1']), '?author=1 redirects as core intends');
wpuo_assert_false(wpuo_test_was_404(), 'and gets no 404 either');

// --- NOT FAIL-OPEN: the rest of the package is still doing its job ---------
//
// Without this, a build in which every surface silently stopped working would pass the assertions
// above without complaint. The surfaces that cannot be switched off are the witnesses.

wpuo_test_reset();
wpuo_assert_false(
	array_key_exists('author_name', wpuo_oembed_strip_author(['author_name' => 'Andrea Lam'])),
	'the oEmbed byline is still stripped'
);
wpuo_assert_same(
	WPUO_LOGIN_CODE,
	wpuo_login_normalize_error(new WP_Error('incorrect_password', 'the password you entered'))->get_error_code(),
	'and the login error is still flattened'
);
wpuo_assert_same('deny', wpuo_rest_users_verdict('/wp/v2/users'), 'and an anonymous REST listing is still refused');

wpuo_test_done('author-archives-used-test');
