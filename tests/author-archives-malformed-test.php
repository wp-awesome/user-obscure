<?php
/**
 * Surface 3, on a site whose declaration is not a string at all.
 *
 * A PHP constant can hold an array. Casting one to string emits a warning, and on a site running
 * with a strict error handler — or with `WP_DEBUG` and a handler that promotes notices — that
 * warning becomes the fatal that takes the site down. An object constant with no `__toString()` is a
 * fatal on any configuration. This package runs on every request, so it reads constants with a
 * scalar check and no else branch.
 *
 * Third process for the same decision, for the same reason as the other two: a constant cannot be
 * redefined.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_AUTHOR_ARCHIVES = ['unused'];

require_once dirname(__DIR__) . '/src/load.php';

// --- the malformed declaration contributes nothing, and raises nothing ------

wpuo_test_reset();
wpuo_assert_same(WPUO_ARCHIVES_USED, wpuo_author_archives(), 'a declaration that is not a scalar is treated as undeclared');
wpuo_assert_false(wpuo_obscuring_author_archives(), 'so the archive surface contributes nothing');

wpuo_assert_same('fallback', wpuo_setting('WP_USER_OBSCURE_AUTHOR_ARCHIVES', 'fallback'), 'and the reader returns the default rather than casting an array to string');

// --- the same totality for every constant this package reads ---------------

wpuo_assert_same('default', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED', 'default'), 'an undefined constant is the default');
wpuo_assert_same('', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED'), 'with an empty default when none is given');

// --- an option key that came from a malformed constant ---------------------

wpuo_assert_same(
	'wp_user_obscure_rest_users',
	wpuo_option_key('rest_users'),
	'the option keys are unaffected by a malformed declaration elsewhere'
);

// --- NOT FAIL-OPEN --------------------------------------------------------

wpuo_test_reset(['wp_user_obscure_rest_users' => '1'], []);
wpuo_assert_same('deny', wpuo_rest_users_verdict('/wp/v2/users'), 'and the surfaces that are switched on still work');

wpuo_test_done('author-archives-malformed-test');
