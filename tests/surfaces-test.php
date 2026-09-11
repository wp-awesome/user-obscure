<?php
/**
 * How a stored value becomes a decision.
 *
 * ONLY THE LITERAL '1' IS ON, and every other shape a database row can hold is off. This file is the
 * exhaustive list of those shapes, because the rule is worth nothing if it is only true for the
 * shapes somebody happened to think of.
 *
 * Each malformed shape is asserted in BOTH directions. "It contributes nothing" alone would pass
 * just as happily against a function that always returned false, which is the inversion that would
 * make the whole package inert while every test stayed green.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_USER_OBSCURE_OPTION_REST_USERS   = 'test_rest';
const WP_USER_OBSCURE_OPTION_AUTHOR_PROBE = 'test_probe';
const WP_USER_OBSCURE_OPTION_OEMBED       = 'test_oembed';
const WP_USER_OBSCURE_OPTION_LOGIN_ERRORS = 'test_login';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @return array<string, string>
 */
function wpuo_test_keys(): array {
	return [
		'rest_users'   => 'test_rest',
		'author_probe' => 'test_probe',
		'oembed'       => 'test_oembed',
		'login_errors' => 'test_login',
	];
}

function wpuo_test_store(string $surface, mixed $value): void {
	wpuo_test_reset([wpuo_test_keys()[$surface] => $value]);
}

// --- the host may rename every key, and a typo cannot enable anything -------

wpuo_assert_same('test_rest', wpuo_option_key('rest_users'), 'the host\'s own option key is used');
wpuo_assert_same('', wpuo_option_key('not_a_surface'), 'an unknown surface name has no key');
wpuo_assert_false(wpuo_obscuring('not_a_surface'), 'and an unknown surface name is never obscured');

// --- the shapes that mean ON ----------------------------------------------
//
// WordPress normalises a stored `true` and a stored `1` to the string '1' on the way out of the
// database, so all three are the same value and all three must mean the same thing.

foreach (wpuo_surfaces() as $surface) {
	foreach (['1', 1, 1.0, true] as $on) {
		wpuo_test_store($surface, $on);
		wpuo_assert_true(
			wpuo_obscuring($surface),
			sprintf('%s is obscured when its option holds %s', $surface, var_export($on, true))
		);
	}
}

// --- every other shape means OFF ------------------------------------------

$malformed = [
	'absent'            => null,
	'empty string'      => '',
	'zero string'       => '0',
	'zero int'          => 0,
	'false'             => false,
	'"on"'              => 'on',
	'"true"'            => 'true',
	'"yes"'             => 'yes',
	'padded'            => ' 1 ',
	'zero padded'       => '01',
	'decimal string'    => '1.0',
	'array'             => ['1'],
	'empty array'       => [],
	'object'            => new stdClass(),
];

foreach (wpuo_surfaces() as $surface) {
	foreach ($malformed as $label => $value) {
		if (null === $value) {
			wpuo_test_reset([]);
		} else {
			wpuo_test_store($surface, $value);
		}

		wpuo_assert_false(
			wpuo_obscuring($surface),
			sprintf('%s contributes nothing when its option holds %s', $surface, $label)
		);

		// THE INVERSION CHECK. Without this, a function hardwired to return false would satisfy
		// every assertion above and the package would protect nothing while the suite stayed green.
		wpuo_test_store($surface, '1');
		wpuo_assert_true(
			wpuo_obscuring($surface),
			sprintf('%s is still capable of being obscured after the %s case', $surface, $label)
		);
	}
}

// --- one corrupt surface does not poison the others ------------------------

wpuo_test_reset([
	'test_rest'   => ['nonsense from a restore'],
	'test_probe'  => '1',
	'test_oembed' => '1',
	'test_login'  => '1',
]);

wpuo_assert_false(wpuo_obscuring('rest_users'), 'a corrupt row switches its own surface off');
wpuo_assert_true(wpuo_obscuring('author_probe'), 'and leaves the surfaces beside it alone');
wpuo_assert_true(wpuo_obscuring('oembed'), 'and leaves the surfaces beside it alone');
wpuo_assert_true(wpuo_obscuring('login_errors'), 'and leaves the surfaces beside it alone');

// --- the author-archive declaration, at its default ------------------------

wpuo_assert_same(
	WPUO_ARCHIVES_USED,
	wpuo_author_archives(),
	'a host that declares nothing is treated as USING author archives, so nothing is obscured'
);
wpuo_assert_false(wpuo_obscuring_author_archives(), 'and the archive surface contributes nothing');

// --- the report ------------------------------------------------------------

wpuo_test_reset(['test_rest' => '1', 'test_probe' => '0', 'test_oembed' => 'on', 'test_login' => '1']);
wpuo_assert_same(
	[
		'rest_users'      => true,
		'author_probe'    => false,
		'author_archives' => 'used',
		'oembed'          => false,
		'login_errors'    => true,
	],
	wpuo_report(),
	'the report states what is actually in force, corrupt rows included'
);

wpuo_test_done('surfaces-test');
