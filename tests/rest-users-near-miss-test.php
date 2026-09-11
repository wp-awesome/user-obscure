<?php
/**
 * Surface 1, on a site whose declaration is a NEAR MISS.
 *
 * `'Used'` is the typo a developer actually makes, and this file pins which way it falls. For author
 * archives the safe direction was "keep serving"; here it is the opposite — an unrecognised value
 * OBSCURES — because the failure modes are not symmetric. A wrongly-404ed author archive is a
 * published URL breaking quietly, weeks before anybody notices. A wrongly-401ed REST user listing is
 * a headless front end failing loudly, in front of the developer who just deployed it, with the
 * constant they mistyped named in the README.
 *
 * THIS IS ALSO WHY NEITHER DECLARATION IS A BOOLEAN. `(bool) 'Used'` is `true` and `(bool) 'used'`
 * is `true`, so a boolean-shaped constant resolves both spellings to whichever direction `true`
 * happens to mean, and no arrangement makes both fall safe. An exact comparison against one spelling
 * has exactly one failure direction, and each surface chooses which one it can afford.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_REST_USERS = 'Used';

require_once dirname(__DIR__) . '/src/load.php';

// --- the near miss resolves towards obscuring ------------------------------

wpuo_assert_same(WPUO_REST_USERS_UNUSED, wpuo_rest_users(), 'a near-miss declaration is read as "not in use"');
wpuo_assert_true(wpuo_obscuring_rest_users(), 'so the REST user listing stays obscured');

wpuo_test_reset();
wpuo_assert_same('deny', wpuo_rest_users_verdict('/wp/v2/users'), 'and an anonymous listing is still refused');

// --- the editor is still exempt, whatever the declaration says -------------
//
// The capability gate sits inside the obscured branch. A file that only asserted the refusal would
// pass against a build that denied everybody.

wpuo_test_reset(['edit_posts']);
wpuo_assert_same('editorial', wpuo_rest_users_verdict('/wp/v2/users'), 'an Editor keeps the author list');

wpuo_test_reset(['list_users']);
wpuo_assert_same('capability', wpuo_rest_users_verdict('/wp/v2/users'), 'and an administrator keeps normal results');

wpuo_test_reset();
wpuo_assert_same('own-profile', wpuo_rest_users_verdict('/wp/v2/users/me'), 'and a user keeps their own profile');

wpuo_test_done('rest-users-near-miss-test');
