<?php
/**
 * Surface 4: the oEmbed discovery response.
 *
 * MEASURED. `GET /wp-json/oembed/1.0/embed?url=/` returned `author_name` "Andrea Lam" and an
 * `author_url` containing the author archive — and therefore the login slug — on a site whose REST
 * users route had already been thought about. It is the surface people forget, because it is served
 * by a different controller under a different namespace and survives every `/wp/v2/users` recipe.
 *
 * The fields are UNSET rather than blanked. oEmbed makes both optional, so an absent field is a
 * valid response a consumer already handles, whereas an empty string is a value that renders as an
 * empty byline.
 *
 * Kept to the JSON discovery response on purpose. The embed IFRAME's own markup is theme output and
 * is named in the README as a surface this package does not cover.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * The `oembed_response_data` callback.
 *
 * `$data` is asserted to be an array rather than assumed: this filter has already passed through
 * every other plugin on the site by the time it arrives, and a non-array here must cost this package
 * its effect rather than raise.
 *
 * @param mixed $data
 *
 * @return mixed
 */
function wpuo_oembed_strip_author(mixed $data): mixed {
	if (! is_array($data)) {
		return $data;
	}

	if (! wpuo_obscuring('oembed')) {
		return $data;
	}

	unset($data['author_name'], $data['author_url']);

	return $data;
}
