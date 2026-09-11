<?php
/**
 * Surface 2: `?author=N`.
 *
 * THE 301 IS THE LEAK, not the page it points at. `GET /?author=1` answering
 * `301 -> /author/site_admin/` hands over a login slug for an account id, and it does so before the
 * archive is ever fetched. So this file asserts the absence of the redirect as hard as it asserts
 * the presence of the 404: suppressing `redirect_canonical()` is half the mechanism, and a 404 that
 * core then answers with `redirect_guess_404_permalink()` would leak anyway.
 *
 * This file runs with author archives at their default declaration — IN USE — which is how it proves
 * the two surfaces are genuinely independent: the probe is closed while `/author/<slug>/` is not.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

const WPUO_PROBE_KEY = 'wp_user_obscure_author_probe';

/**
 * @param array<string, mixed> $query_vars
 */
function wpuo_test_parse(array $query_vars, mixed $stored = '1', bool $admin = false): string {
	wpuo_test_reset([WPUO_PROBE_KEY => $stored]);
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

function wpuo_test_canonical_suppressed(): bool {
	foreach (wpuo_test_get('filters') as $filter) {
		if ('redirect_canonical' === $filter['hook'] && false === ($filter['callback'])()) {
			return true;
		}
	}

	return false;
}

// --- the declaration this file runs under ---------------------------------

wpuo_assert_same(WPUO_ARCHIVES_USED, wpuo_author_archives(), 'this file runs on a site that uses author archives');

// --- switched on: the probe 404s and does not redirect ---------------------

foreach (['1', '16', '0', '-1', 1, 16] as $id) {
	wpuo_assert_same('probe', wpuo_test_parse(['author' => $id]), sprintf('?author=%s is a probe', (string) $id));
	wpuo_assert_true(wpuo_test_was_404(), sprintf('?author=%s answers a real 404', (string) $id));
	wpuo_assert_true(wpuo_test_canonical_suppressed(), sprintf('?author=%s never reaches the canonical redirect', (string) $id));
}

// --- and the archive beside it is untouched, because it was not declared unused

wpuo_assert_same('', wpuo_test_parse(['author_name' => 'site_admin']), 'an author archive is untouched while it is declared in use');
wpuo_assert_false(wpuo_test_was_404(), 'so no 404 is sent');
wpuo_assert_false(wpuo_test_canonical_suppressed(), 'and the canonical redirect is left alone');

// --- switched off: nothing is touched --------------------------------------

wpuo_assert_same('', wpuo_test_parse(['author' => '1'], '0'), 'an unticked setting leaves ?author=1 resolving');
wpuo_assert_false(wpuo_test_was_404(), 'no 404 is sent');
wpuo_assert_false(wpuo_test_canonical_suppressed(), 'and the redirect core would issue is not suppressed');

// --- what is not a probe ---------------------------------------------------

wpuo_assert_same('', wpuo_test_parse([]), 'an ordinary request is not a probe');
wpuo_assert_same('', wpuo_test_parse(['author' => '']), 'an empty author is not a probe');
wpuo_assert_same('', wpuo_test_parse(['author' => 'site_admin']), 'a non-numeric author cannot resolve to an account and is left alone');
wpuo_assert_same('', wpuo_test_parse(['author' => ['1']]), 'an array author is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['author' => new stdClass()]), 'an object author is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['rest_route' => '/wp/v2/users', 'author' => '1']), 'a REST request is answered elsewhere in this package');

// --- the dashboard is exempt ----------------------------------------------
//
// `wp-admin/edit.php?author=5` is how an administrator filters the posts list. 404ing it would break
// the screen these settings live next to.

wpuo_assert_same('', wpuo_test_parse(['author' => '5'], '1', true), 'the dashboard filter-by-author is never turned into a 404');
wpuo_assert_false(wpuo_test_was_404(), 'and the dashboard gets no 404 headers');

// --- a request object core did not hand us ---------------------------------

wpuo_test_reset([WPUO_PROBE_KEY => '1']);
wpuo_author_parse_request(null);
wpuo_assert_same('', (string) ($GLOBALS['wpuo_author_404'] ?? ''), 'a missing $wp contributes nothing and does not raise');

wpuo_test_reset([WPUO_PROBE_KEY => '1']);
$wp             = new stdClass();
$wp->query_vars = 'not an array';
wpuo_author_parse_request($wp);
wpuo_assert_same('', (string) ($GLOBALS['wpuo_author_404'] ?? ''), 'query vars that are not an array contribute nothing and do not raise');

// --- the 404 half refuses to fire on its own -------------------------------

wpuo_test_reset();
wpuo_author_send_404();
wpuo_assert_false(wpuo_test_was_404(), 'the template_redirect half does nothing unless parse_request flagged the request');

// --- a replaced main query costs the effect, never the site -----------------

wpuo_test_reset([WPUO_PROBE_KEY => '1']);
$GLOBALS['wp_query']        = new stdClass();
$GLOBALS['wpuo_author_404'] = 'probe';
wpuo_author_send_404();
wpuo_assert_true(in_array(404, wpuo_test_get('headers'), true), 'a $wp_query without set_404() still gets the status header, and does not raise');

// --- a malformed setting contributes nothing, both directions asserted -----

foreach (['', '0', 'on', 'true', ['1'], new stdClass()] as $bad) {
	wpuo_assert_same('', wpuo_test_parse(['author' => '1'], $bad), 'a malformed setting leaves ?author=1 resolving');
	wpuo_assert_false(wpuo_test_was_404(), 'and sends no 404');
	wpuo_assert_false(wpuo_test_canonical_suppressed(), 'and suppresses no redirect');

	// Not fail-open: the mechanism is still live.
	wpuo_assert_same('probe', wpuo_test_parse(['author' => '1']), 'and the probe is still closed when the setting is valid');
	wpuo_assert_true(wpuo_test_was_404(), 'with a real 404');
}

wpuo_test_done('author-probe-test');
