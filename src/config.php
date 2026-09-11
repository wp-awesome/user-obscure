<?php
/**
 * Host configuration: two declarations, and the total reader that resolves them.
 *
 * THERE IS ONLY ONE KIND OF CONFIGURATION LEFT, AND THAT IS THE POINT. This package used to split
 * its decisions into site-SHAPE facts, declared in code, and operator DECISIONS, stored in the
 * database behind a screen of checkboxes. The split was borrowed from a sibling package that has
 * genuine operator modes to choose between. It did not transfer. Every one of the four checkboxes
 * had exactly one correct position, and every flip was towards exposure, silently, with no error —
 * a control whose only function is to degrade the thing it controls is an invitation, not a choice.
 *
 * So what remains are two declarations, and both are facts about how the site was BUILT rather than
 * preferences about how it should behave: whether the theme uses author archives, and whether the
 * front end reads the REST user collection anonymously. Neither is answerable from the dashboard.
 * Both take reading code, so both are written in code. The other three surfaces have no honest
 * question attached to them and are simply on.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Reads a host string constant, falling back to a neutral default.
 *
 * TOTAL BY CONSTRUCTION. A PHP constant can hold an array, and casting an array to string emits a
 * warning while casting an object that has no `__toString()` is a fatal Error. This package runs on
 * every request of a site it is not allowed to take down, so a constant whose value is not a scalar
 * is treated as undeclared rather than cast. The malformed direction is the declared default,
 * always, and each caller chooses which direction that is.
 */
function wpuo_setting(string $constant, string $default = ''): string {
	if (! defined($constant)) {
		return $default;
	}

	$value = constant($constant);

	return is_scalar($value) ? (string) $value : $default;
}
