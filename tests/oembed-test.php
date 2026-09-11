<?php
/**
 * Surface 4: the oEmbed discovery response. UNCONDITIONAL.
 *
 * The surface people forget. It is served by a different controller under a different namespace, so
 * it survives every `/wp/v2/users` recipe — measured on a site whose REST users route had already
 * been thought about, `/wp-json/oembed/1.0/embed?url=/` still returned `author_name` and an
 * `author_url` containing the login slug.
 *
 * WHY THERE IS NOTHING TO SWITCH OFF. What this costs is a byline in somebody else's embed card.
 * That is cosmetic, it is not a site-shape fact, and there is no site whose correct configuration is
 * "hand the slug to every consumer that asks". A toggle here would have exactly one useful position
 * and one position that silently leaks, which is not a choice worth offering.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/src/load.php';

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

// --- both fields are gone, everything else survives -----------------------

wpuo_test_reset();
$stripped = wpuo_oembed_strip_author(wpuo_test_oembed_data());

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

// --- a payload this package did not expect ---------------------------------

wpuo_test_reset();
wpuo_assert_same(null, wpuo_oembed_strip_author(null), 'a null payload is returned untouched and does not raise');
wpuo_assert_same('not an array', wpuo_oembed_strip_author('not an array'), 'a string payload is returned untouched');
wpuo_assert_same([], wpuo_oembed_strip_author([]), 'an empty payload is returned untouched');
wpuo_assert_same(
	['title' => 'Hello'],
	wpuo_oembed_strip_author(['title' => 'Hello']),
	'a payload another plugin already stripped is returned untouched'
);

// --- the strip does not depend on anything ---------------------------------
//
// No option — `get_option()` throws for the whole of this process — and no host constant either.
// This is the structural half of "unconditional": the behavioural assertions above would still pass
// if a condition existed and happened to be true, and the condition is what used to leak.

$code = wpuo_test_source('src/oembed.php');

foreach (['wpuo_setting', 'wpuo_obscuring', 'WP_USER_OBSCURE', 'get_option'] as $needle) {
	wpuo_assert_not_contains($needle, $code, sprintf('the oEmbed surface consults nothing (%s)', $needle));
}

// --- it is stripped no matter what state the request is in -----------------
//
// The dashboard, a REST request, a user with every capability: the byline goes either way. Nothing
// about the requester changes this surface, because the leak is in what is sent to a third party.

wpuo_test_reset(['list_users', 'edit_posts', 'manage_options']);
wpuo_test_set('is_admin', true);
wpuo_assert_false(
	array_key_exists('author_name', wpuo_oembed_strip_author(wpuo_test_oembed_data())),
	'an administrator in the dashboard gets the same stripped response'
);

wpuo_test_done('oembed-test');
