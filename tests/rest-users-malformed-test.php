<?php
/**
 * Surface 1, on a site whose declaration is not a string at all.
 *
 * A PHP constant can hold an array. Casting one to string emits a warning, and on a site running
 * with a strict error handler — or with `WP_DEBUG` and a handler that promotes notices — that
 * warning becomes the fatal that takes the site down. An object constant with no `__toString()` is a
 * fatal on any configuration. This package runs on every request, so it reads constants with a
 * scalar check and no else branch.
 *
 * A malformed declaration is treated as undeclared, and undeclared means obscure for this surface.
 * That is the safe direction here: the value nobody successfully chose must not expose an account
 * list.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_REST_USERS = ['used'];

require_once dirname(__DIR__) . '/src/load.php';

// --- the malformed declaration is treated as undeclared, and raises nothing -

wpuo_assert_same(WPUO_REST_USERS_UNUSED, wpuo_rest_users(), 'a declaration that is not a scalar is treated as undeclared');
wpuo_assert_true(wpuo_obscuring_rest_users(), 'and undeclared obscures this surface');

wpuo_assert_same(
	'fallback',
	wpuo_setting('WP_USER_OBSCURE_REST_USERS', 'fallback'),
	'the reader returns the default rather than casting an array to string'
);

// --- the same totality for every constant this package reads ---------------

wpuo_assert_same('default', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED', 'default'), 'an undefined constant is the default');
wpuo_assert_same('', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED'), 'with an empty default when none is given');

// --- one malformed declaration does not disturb the other ------------------

wpuo_assert_same(WPUO_ARCHIVES_USED, wpuo_author_archives(), 'the author-archive declaration is unaffected');
wpuo_assert_false(wpuo_obscuring_author_archives(), 'and resolves on its own terms');

// --- NOT FAIL-OPEN --------------------------------------------------------

wpuo_test_reset();
wpuo_assert_same('deny', wpuo_rest_users_verdict('/wp/v2/users'), 'and the surface it obscured is genuinely refusing');

wpuo_test_reset(['edit_posts']);
wpuo_assert_same('editorial', wpuo_rest_users_verdict('/wp/v2/users'), 'while the Editor exemption survives');

wpuo_test_done('rest-users-malformed-test');
