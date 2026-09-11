<?php
/**
 * Surface 2: `?author=N`, and the fact that it is no longer a decision of its own.
 *
 * THE DEFECT THIS FILE EXISTS TO KEEP CLOSED. The probe and the author archive used to be separately
 * controllable, and the combination that shipped as the DEFAULT was incoherent. Measured on a real
 * preview site, with archives declared unused and the probe left unticked:
 *
 *     ?author=1            301 -> /author/site_admin/
 *     /author/site_admin/  404
 *
 * A redirect pointing at a 404. It leaked the slug and then served nothing, which is the worst of
 * both arrangements. The two are not two decisions: if archives are unused, `?author=N` has nothing
 * to redirect TO and must 404; if archives are used, the redirect is correct core behaviour and
 * 404ing it breaks a real entry point. So the probe is DERIVED from the archive declaration, and the
 * incoherent state is now unreachable rather than merely untested.
 *
 * This process declares nothing. That is the bare-install shape: archives presumed in use, so the
 * probe resolves and this package contributes nothing to either.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param array<string, mixed> $query_vars
 */
function wpuo_test_parse(array $query_vars, bool $admin = false): string {
	wpuo_test_reset();
	wpuo_test_set('is_admin', $admin);

	$wp             = new stdClass();
	$wp->query_vars = $query_vars;

	wpuo_author_parse_request($wp);

	// Whatever `parse_request` decided, run the `template_redirect` half as core would.
	wpuo_author_send_404();

	return (string) ($GLOBALS['wpuo_author_404'] ?? '');
}

function wpuo_test_was_404(): bool {
	return $GLOBALS['wp_query']->is_404
		&& in_array(404, wpuo_test_get('headers'), true)
		&& wpuo_test_get('nocache') > 0;
}

// --- the declaration this file runs under ---------------------------------

wpuo_assert_false(defined('WP_USER_OBSCURE_AUTHOR_ARCHIVES'), 'this file runs on a site that declared nothing');
wpuo_assert_same(WPUO_ARCHIVES_USED, wpuo_author_archives(), 'which means author archives are presumed in use');

// --- LOCKSTEP, the whole point --------------------------------------------

wpuo_assert_false(wpuo_obscuring_author_archives(), 'so archives are served');
wpuo_assert_false(wpuo_obscuring_author_probe(), 'and the probe resolves, because there is somewhere for it to resolve to');
wpuo_assert_same(
	wpuo_obscuring_author_archives(),
	wpuo_obscuring_author_probe(),
	'the probe and the archive move together, and nothing in this process can pull them apart'
);

$report = wpuo_report();
wpuo_assert_same(
	'used' === $report['author_archives'],
	! $report['author_probe'],
	'the report cannot describe a redirect that points at a 404'
);

// --- neither surface is touched -------------------------------------------

foreach (['1', '16', '0', '-1', 1, 16] as $id) {
	wpuo_assert_same('', wpuo_test_parse(['author' => $id]), sprintf('?author=%s resolves as core intends', (string) $id));
	wpuo_assert_false(wpuo_test_was_404(), sprintf('?author=%s gets no 404 of this package\'s making', (string) $id));
}

foreach (['site_admin', 'nosuchuser'] as $slug) {
	wpuo_assert_same('', wpuo_test_parse(['author_name' => $slug]), sprintf('/author/%s/ is served normally', $slug));
	wpuo_assert_false(wpuo_test_was_404(), sprintf('/author/%s/ gets no 404 either', $slug));
}

// --- the canonical redirect is left alone ---------------------------------
//
// Suppressing it here would be the mirror-image defect: a `?author=N` that neither redirects nor
// 404s is a third state, and nobody asked for one.

wpuo_test_parse(['author' => '1']);

foreach (wpuo_test_get('filters') as $filter) {
	wpuo_assert_same(
		true,
		'redirect_canonical' !== $filter['hook'],
		'nothing suppresses the redirect core would issue'
	);
}

// --- the 404 half refuses to fire on its own -------------------------------

wpuo_test_reset();
wpuo_author_send_404();
wpuo_assert_false(wpuo_test_was_404(), 'the template_redirect half does nothing unless parse_request flagged the request');

// --- a request object core did not hand us ---------------------------------

wpuo_test_reset();
wpuo_author_parse_request(null);
wpuo_assert_same('', (string) ($GLOBALS['wpuo_author_404'] ?? ''), 'a missing $wp contributes nothing and does not raise');

wpuo_test_reset();
$wp             = new stdClass();
$wp->query_vars = 'not an array';
wpuo_author_parse_request($wp);
wpuo_assert_same('', (string) ($GLOBALS['wpuo_author_404'] ?? ''), 'query vars that are not an array contribute nothing and do not raise');

// --- the verdict is pure, and says so -------------------------------------

wpuo_assert_same('', wpuo_author_request_verdict([]), 'an ordinary request is neither');
wpuo_assert_same('', wpuo_author_request_verdict(['rest_route' => '/wp/v2/users', 'author' => '1']), 'a REST request is answered elsewhere in this package');

// --- NOT FAIL-OPEN: the package is live in this process --------------------
//
// Every assertion above is an absence, and absences pass just as happily against a build that does
// nothing at all. The two unconditional surfaces are the witnesses.

wpuo_test_reset();
wpuo_assert_false(
	array_key_exists('author_url', wpuo_oembed_strip_author(['author_url' => 'https://example.test/author/jane_editor/'])),
	'the oEmbed byline is still stripped'
);
wpuo_assert_same(
	WPUO_LOGIN_CODE,
	wpuo_login_normalize_error(new WP_Error('invalid_username', 'not registered'))->get_error_code(),
	'and the login error is still flattened'
);
wpuo_assert_true(wpuo_obscuring_rest_users(), 'and the REST user listing is obscured by default');

// --- no stored value can reach any of this --------------------------------
//
// `get_option()` throws for the whole of this process. The probe once had an option of its own, set
// to '1' in at least one production database; it is inert, and it is inert by construction rather
// than by a branch that happens to ignore it.

wpuo_assert_false(wpuo_obscuring_author_probe(), 'the probe decision is reached with the database fenced off');

wpuo_test_done('author-probe-test');
