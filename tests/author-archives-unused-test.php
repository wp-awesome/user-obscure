<?php
/**
 * Surface 3, on a site that has DECLARED it does not use author archives.
 *
 * WHY THIS IS A CONSTANT AND NOT A CHECKBOX. `/author/site_admin/` answering 200 while
 * `/author/nosuchuser/` answers 404 is a valid-username oracle, and closing it means 404ing a URL
 * shape. A site whose theme links to author archives cannot do that — the pages exist, are linked
 * and may be indexed — and nobody can tell which kind of site it is from the dashboard. It takes
 * reading the templates, so it is declared in code by the person who read them.
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
function wpuo_test_parse(array $query_vars, mixed $probe = '0', bool $admin = false): string {
	wpuo_test_reset(['wp_user_obscure_author_probe' => $probe]);
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

// --- the declaration -------------------------------------------------------

wpuo_assert_same(WPUO_ARCHIVES_UNUSED, wpuo_author_archives(), 'the host declared author archives unused');
wpuo_assert_true(wpuo_obscuring_author_archives(), 'so the archive surface is obscured');

// --- every archive 404s, and they all 404 the same way ---------------------
//
// A real slug and an invented one must be indistinguishable. That sameness IS the fix; a 404 for one
// and a 200 for the other is the oracle.

foreach (['site_admin', 'jane_editor', 'nosuchuser', 'admin'] as $slug) {
	wpuo_assert_same('archive', wpuo_test_parse(['author_name' => $slug]), sprintf('/author/%s/ is refused', $slug));
	wpuo_assert_true(wpuo_test_was_404(), sprintf('/author/%s/ answers a real 404', $slug));
}

// --- the probe is a separate decision and is switched off here -------------

wpuo_assert_same('', wpuo_test_parse(['author' => '1']), '?author=1 is untouched, because that is its own setting');
wpuo_assert_false(wpuo_test_was_404(), 'and sends no 404');

wpuo_assert_same('archive', wpuo_test_parse(['author_name' => 'site_admin'], '1'), 'and the two close independently');
wpuo_assert_same('probe', wpuo_test_parse(['author' => '1'], '1'), 'in either order');

// --- what is not an archive request ---------------------------------------

wpuo_assert_same('', wpuo_test_parse([]), 'an ordinary request is untouched');
wpuo_assert_same('', wpuo_test_parse(['author_name' => '']), 'an empty author_name is not an archive request');
wpuo_assert_same('', wpuo_test_parse(['author_name' => ['site_admin']]), 'an array author_name is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['author_name' => new stdClass()]), 'an object author_name is left alone and does not raise');
wpuo_assert_same('', wpuo_test_parse(['rest_route' => '/oembed/1.0/embed', 'author_name' => 'x']), 'a REST request is answered elsewhere');
wpuo_assert_same('', wpuo_test_parse(['author_name' => 'site_admin'], '0', true), 'the dashboard is never turned into a 404');

// --- no database was consulted for this decision ---------------------------
//
// The declaration is code. A site whose database is unreachable still 404s its author archives.

wpuo_test_reset();
wpuo_test_set('option_guard', true);
wpuo_assert_same(WPUO_ARCHIVES_UNUSED, wpuo_author_archives(), 'the declaration is read from code, never from the database');
wpuo_assert_true(wpuo_obscuring_author_archives(), 'and so is the decision that follows from it');

wpuo_test_done('author-archives-unused-test');
