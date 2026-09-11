<?php
/**
 * Surfaces 2 and 3, on a site whose declaration is not a string at all.
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

// --- LOCKSTEP over the malformed resolution -------------------------------

wpuo_assert_false(wpuo_obscuring_author_archives(), 'so the archive surface contributes nothing');
wpuo_assert_false(wpuo_obscuring_author_probe(), 'and neither does the probe derived from it');
wpuo_assert_same(
	wpuo_obscuring_author_archives(),
	wpuo_obscuring_author_probe(),
	'an unreadable declaration cannot separate the two either'
);

wpuo_assert_same('fallback', wpuo_setting('WP_USER_OBSCURE_AUTHOR_ARCHIVES', 'fallback'), 'and the reader returns the default rather than casting an array to string');

// --- the same totality for every constant this package reads ---------------

wpuo_assert_same('default', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED', 'default'), 'an undefined constant is the default');
wpuo_assert_same('', wpuo_setting('WP_USER_OBSCURE_NOT_DEFINED'), 'with an empty default when none is given');

// --- one malformed declaration does not disturb the other ------------------

wpuo_assert_same(WPUO_REST_USERS_UNUSED, wpuo_rest_users(), 'the REST declaration is unaffected and resolves on its own terms');

// --- NOT FAIL-OPEN --------------------------------------------------------

wpuo_test_reset();
wpuo_assert_same('deny', wpuo_rest_users_verdict('/wp/v2/users'), 'and the surfaces that are in force still work');
wpuo_assert_false(
	array_key_exists('author_name', wpuo_oembed_strip_author(['author_name' => 'Andrea Lam'])),
	'including the oEmbed strip'
);

wpuo_test_done('author-archives-malformed-test');
