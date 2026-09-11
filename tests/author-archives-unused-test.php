<?php
/**
 * Surfaces 2 and 3, on a site that has DECLARED it does not use author archives.
 *
 * WHY THIS IS A DECLARATION AND NOT A CHECKBOX. `/author/site_admin/` answering 200 while
 * `/author/nosuchuser/` answers 404 is a valid-username oracle, and closing it means 404ing a URL
 * shape. A site whose theme links to author archives cannot do that — the pages exist, are linked
 * and may be indexed — and nobody can tell which kind of site it is from the dashboard. It takes
 * reading the templates, so it is declared in code by the person who read them.
 *
 * AND WHY THE PROBE FOLLOWS IT. With archives unused, `?author=N` has nothing left to redirect to;
 * its 301 would point at a 404. The two are one decision, and this file is the half where both are
 * closed. The other half — both open — is `tests/author-probe-test.php`.
 *
 * The declaration is a PHP constant, and a constant cannot be redefined, which is why this decision
 * has one test file per declared value. `tests/run.php` runs each file in its own process for
 * exactly this.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_AUTHOR_ARCHIVES = 'unused';

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

// --- the declaration -------------------------------------------------------

wpuo_assert_same(WPUO_ARCHIVES_UNUSED, wpuo_author_archives(), 'the host declared author archives unused');

// --- LOCKSTEP, the other direction ----------------------------------------

wpuo_assert_true(wpuo_obscuring_author_archives(), 'so the archive surface is obscured');
wpuo_assert_true(wpuo_obscuring_author_probe(), 'and the probe with it, because its redirect would now point at a 404');
wpuo_assert_same(
	wpuo_obscuring_author_archives(),
	wpuo_obscuring_author_probe(),
	'the probe and the archive move together, and nothing in this process can pull them apart'
);

$report = wpuo_report();
wpuo_assert_same(
	'unused' === $report['author_archives'],
	$report['author_probe'],
	'the report cannot describe a 404ed archive that is still reachable by id'
);

// --- every archive 404s, and they all 404 the same way ---------------------
//
// A real slug and an invented one must be indistinguishable. That sameness IS the fix; a 404 for one
// and a 200 for the other is the oracle.

foreach (['site_admin', 'jane_editor', 'nosuchuser', 'admin'] as $slug) {
	wpuo_assert_same('archive', wpuo_test_parse(['author_name' => $slug]), sprintf('/author/%s/ is refused', $slug));
	wpuo_assert_true(wpuo_test_was_404(), sprintf('/author/%s/ answers a real 404', $slug));
}

// --- and the probe 404s beside it, without redirecting ---------------------
//
// The 301 IS the leak, and it is issued by `redirect_canonical()`. A 404 alone would not stop it:
// core answers a 404 by trying `redirect_guess_404_permalink()`. Both halves are asserted.

foreach (['1', '16', '0', '-1', 1, 16] as $id) {
	wpuo_assert_same('probe', wpuo_test_parse(['author' => $id]), sprintf('?author=%s is a probe', (string) $id));
	wpuo_assert_true(wpuo_test_was_404(), sprintf('?author=%s answers a real 404', (string) $id));
	wpuo_assert_true(wpuo_test_canonical_suppressed(), sprintf('?author=%s never reaches the canonical redirect', (string) $id));
}

// --- what is neither ------------------------------------------------------

wpuo_assert_same('', wpuo_test_parse([]), 'an ordinary request is untouched');
wpuo_assert_same('', wpuo_test_parse(['author_name' => '']), 'an empty author_name is not an archive request');
wpuo_assert_same('', wpuo_test_parse(['author' => '']), 'an empty author is not a probe');
wpuo_assert_same('', wpuo_test_parse(['author' => 'site_admin']), 'a non-numeric author cannot resolve to an account and is left alone');
wpuo_assert_same('', wpuo_test_parse(['author_name' => ['site_admin']]), 'an array author_name is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['author_name' => new stdClass()]), 'an object author_name is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['author' => ['1']]), 'an array author is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['author' => new stdClass()]), 'an object author is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['rest_route' => '/oembed/1.0/embed', 'author_name' => 'x']), 'a REST request is answered elsewhere');
wpuo_assert_same('', wpuo_test_parse(['rest_route' => '/wp/v2/users', 'author' => '1']), 'including one carrying an author id');

// --- the dashboard is exempt ----------------------------------------------
//
// `wp-admin/edit.php?author=5` is how an administrator filters the posts list, and 404ing it would
// break a screen that has nothing to do with this.

wpuo_assert_same('', wpuo_test_parse(['author' => '5'], true), 'the dashboard filter-by-author is never turned into a 404');
wpuo_assert_false(wpuo_test_was_404(), 'and the dashboard gets no 404 headers');
wpuo_assert_same('', wpuo_test_parse(['author_name' => 'site_admin'], true), 'nor is an archive query in the dashboard');

// --- a replaced main query costs the effect, never the site -----------------

wpuo_test_reset();
$GLOBALS['wp_query']        = new stdClass();
$GLOBALS['wpuo_author_404'] = 'probe';
wpuo_author_send_404();
wpuo_assert_true(in_array(404, wpuo_test_get('headers'), true), 'a $wp_query without set_404() still gets the status header, and does not raise');

// --- no database was consulted for any of this -----------------------------
//
// The declaration is code. A site whose database is unreachable still 404s its author archives, and
// no stored value can countermand it, because `get_option()` throws for the whole of this process.

wpuo_test_reset();
wpuo_assert_same(WPUO_ARCHIVES_UNUSED, wpuo_author_archives(), 'the declaration is read from code, never from the database');
wpuo_assert_true(wpuo_obscuring_author_archives(), 'and so is the decision that follows from it');
wpuo_assert_true(wpuo_obscuring_author_probe(), 'and the one derived from that');

wpuo_test_done('author-archives-unused-test');
