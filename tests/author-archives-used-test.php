<?php
/**
 * Surface 3, on a site whose declaration is a NEAR MISS.
 *
 * `'Unused'` is the typo a developer actually makes, and this file pins which way it falls: towards
 * the site working. Author archives keep serving, and the operator sees "declared in use" on the
 * settings screen rather than a site quietly 404ing pages that exist.
 *
 * This is also the reason the declaration is a two-value STRING rather than the boolean the sibling
 * package's `wpaw_flag()` would have read. `(bool) 'Unused'` is `true`, and `(bool) 'unused'` is
 * `true` as well — a boolean-shaped constant resolves BOTH near misses to whichever direction `true`
 * happens to mean, and there is no way to arrange it so that both fall safe. An exact comparison
 * against one spelling has exactly one failure direction, and it is this one.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_AUTHOR_ARCHIVES = 'Unused';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param array<string, mixed> $query_vars
 */
function wpuo_test_parse(array $query_vars, mixed $probe = '1'): string {
	wpuo_test_reset(['wp_user_obscure_author_probe' => $probe]);

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
wpuo_assert_false(wpuo_obscuring_author_archives(), 'so the archive surface contributes nothing');

foreach (['site_admin', 'nosuchuser'] as $slug) {
	wpuo_assert_same('', wpuo_test_parse(['author_name' => $slug]), sprintf('/author/%s/ is served normally', $slug));
	wpuo_assert_false(wpuo_test_was_404(), sprintf('/author/%s/ gets no 404 of this package\'s making', $slug));
}

// --- NOT FAIL-OPEN: the rest of the package is still doing its job ---------
//
// Without this, a build in which every surface silently stopped working would pass the assertions
// above without complaint.

wpuo_assert_same('probe', wpuo_test_parse(['author' => '1']), '?author=1 is still closed by its own setting');
wpuo_assert_true(wpuo_test_was_404(), 'with a real 404');

wpuo_test_done('author-archives-used-test');
