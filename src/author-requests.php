<?php
/**
 * Surfaces 2 and 3: `?author=N` probing, and the author archive itself.
 *
 * MEASURED. `GET /?author=1` answered `301 -> /author/site_admin/`, and `/author/site_admin/`
 * answered 200 while `/author/nosuchuser/` answered 404. Two separate leaks: the redirect hands over
 * the login slug for an account id, and the archive's 200-versus-404 difference is a valid-username
 * oracle for anybody who already has a candidate list.
 *
 * THEY ARE ONE DECISION, NOT TWO, AND MAKING THEM TWO WAS A DEFECT. They were separately
 * controllable once, and the pair that shipped as the default was `?author=1` redirecting to an
 * archive that answered 404 — the slug handed over, and nothing served. The probe is now derived
 * from the archive declaration: unused archives leave the probe nowhere to resolve to, so it 404s;
 * used archives make the redirect correct core behaviour, so it is left alone. Whether the site uses
 * author archives is a fact about the theme's templates and about URLs already published and
 * indexed, nobody can check it from the dashboard, and so it is declared in code. See the README.
 *
 * BOTH BRANCHES 404 UNIFORMLY. A 404 for a real slug and a 404 for an invented one are the same
 * response, which is what removes the oracle. A 403, or a redirect to the home page, would leave the
 * difference measurable and achieve nothing.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Why this request is being turned into a 404, or an empty string for every other request.
 *
 * Pure: it reads the parsed query variables and nothing else, so the decision can be tested without
 * a router, a database or a rewrite ruleset.
 *
 * `author` holds an id and only ever arrives from a `?author=` probe; `/author/<slug>/` parses to
 * `author_name`. An `author` value that is not an integer is left alone — it cannot resolve to an
 * account, so it is somebody else's query variable.
 *
 * @param array<string, mixed> $query_vars
 *
 * @return ''|'probe'|'archive'
 */
function wpuo_author_request_verdict(array $query_vars): string {
	// A REST request is routed through this same hook and is answered elsewhere in this package.
	if (isset($query_vars['rest_route'])) {
		return '';
	}

	if (isset($query_vars['author_name']) && wpuo_obscuring_author_archives()) {
		$name = $query_vars['author_name'];

		if (is_scalar($name) && '' !== (string) $name) {
			return 'archive';
		}
	}

	if (isset($query_vars['author']) && wpuo_obscuring_author_probe()) {
		$author = $query_vars['author'];

		if (is_scalar($author) && 1 === preg_match('/^-?\d+$/', (string) $author)) {
			return 'probe';
		}
	}

	return '';
}

/**
 * The `parse_request` callback.
 *
 * This hook, rather than `template_redirect`, because the query variables here are the router's own
 * and have not yet been rewritten by anything that runs during the main query.
 *
 * The dashboard is exempt: `wp-admin/edit.php?author=5` is how an administrator filters the posts
 * list, and 404ing it would break a screen that has nothing to do with this.
 *
 * @param mixed $wp
 */
function wpuo_author_parse_request(mixed $wp = null): void {
	if (is_admin()) {
		return;
	}

	$query_vars = is_object($wp) && isset($wp->query_vars) && is_array($wp->query_vars) ? $wp->query_vars : [];

	$verdict = wpuo_author_request_verdict($query_vars);

	if ('' === $verdict) {
		return;
	}

	$GLOBALS['wpuo_author_404'] = $verdict;

	// The 301 IS the leak, and it is issued by `redirect_canonical()` at `template_redirect`. A 404
	// alone would not stop it: core answers a 404 by trying `redirect_guess_404_permalink()`.
	// Returning false from this filter is the documented way to make that function stand down.
	add_filter('redirect_canonical', '__return_false');

	// Priority 0, ahead of `redirect_canonical()` at 10.
	add_action('template_redirect', 'wpuo_author_send_404', 0);
}

/**
 * Turns the already-parsed request into a genuine 404 — the real 404 template, the real status.
 *
 * Every core object it touches is checked first. This runs on a site it is not allowed to take down,
 * and a theme or plugin that has replaced `$wp_query` must cost this package its effect, not the
 * site its availability.
 */
function wpuo_author_send_404(): void {
	if (empty($GLOBALS['wpuo_author_404'])) {
		return;
	}

	$query = $GLOBALS['wp_query'] ?? null;

	if (is_object($query) && method_exists($query, 'set_404')) {
		$query->set_404();
	}

	status_header(404);
	nocache_headers();
}
