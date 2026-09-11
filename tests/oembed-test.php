<?php
/**
 * Surface 4: the oEmbed discovery response.
 *
 * The surface people forget. It is served by a different controller under a different namespace, so
 * it survives every `/wp/v2/users` recipe — measured on a site whose REST users route had already
 * been thought about, `/wp-json/oembed/1.0/embed?url=/` still returned `author_name` and an
 * `author_url` containing the login slug.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

const WPUO_OEMBED_KEY = 'wp_user_obscure_oembed';

/**
 * @return array<string, mixed>
 */
function wpuo_test_oembed_data(): array {
	return [
		'version'       => '1.0',
		'provider_name' => 'Example',
		'author_name'   => 'Andrea Lam',
		'author_url'    => 'https://example.test/author/jane_editor/',
		'title'         => 'Hello',
		'html'          => '<iframe></iframe>',
	];
}

function wpuo_test_oembed(mixed $stored): mixed {
	wpuo_test_reset([WPUO_OEMBED_KEY => $stored]);

	return wpuo_oembed_strip_author(wpuo_test_oembed_data());
}

// --- switched on: both fields are gone, everything else survives -----------

$stripped = wpuo_test_oembed('1');

wpuo_assert_false(array_key_exists('author_name', $stripped), 'author_name is removed');
wpuo_assert_false(array_key_exists('author_url', $stripped), 'author_url, which carries the slug, is removed');
wpuo_assert_same(
	['version', 'provider_name', 'title', 'html'],
	array_keys($stripped),
	'and nothing else in the response is touched'
);

// Unset, not blanked: oEmbed makes both fields optional, so an absent field is a shape every consumer
// already handles, while an empty string renders as an empty byline.
wpuo_assert_not_contains('author_name', (string) json_encode($stripped), 'the field is absent from the payload, not present and empty');

// --- switched off: the response is returned exactly as it arrived ----------

wpuo_assert_same(wpuo_test_oembed_data(), wpuo_test_oembed('0'), 'an unticked setting leaves the response untouched');

// --- a payload this package did not expect ---------------------------------

wpuo_test_reset([WPUO_OEMBED_KEY => '1']);
wpuo_assert_same(null, wpuo_oembed_strip_author(null), 'a null payload is returned untouched and does not raise');
wpuo_assert_same('not an array', wpuo_oembed_strip_author('not an array'), 'a string payload is returned untouched');
wpuo_assert_same([], wpuo_oembed_strip_author([]), 'an empty payload is returned untouched');
wpuo_assert_same(
	['title' => 'Hello'],
	wpuo_oembed_strip_author(['title' => 'Hello']),
	'a payload another plugin already stripped is returned untouched'
);

// --- a malformed setting contributes nothing, both directions asserted -----

foreach (['', '0', 'on', 'true', ['1'], new stdClass()] as $bad) {
	$result = wpuo_test_oembed($bad);

	wpuo_assert_same(wpuo_test_oembed_data(), $result, 'a malformed setting leaves the oEmbed response intact');
	wpuo_assert_true(array_key_exists('author_name', $result), 'nothing was half-stripped');

	// Not fail-open: the mechanism is still live.
	wpuo_assert_false(
		array_key_exists('author_name', wpuo_test_oembed('1')),
		'and the strip still works when the setting is valid'
	);
}

wpuo_test_done('oembed-test');
