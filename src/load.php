<?php
/**
 * Loads the package without running it.
 *
 * Separate from `user-obscure.php` so a test can exercise one decision at a time without registering
 * a single hook, and so a host that needs an unusual load order has somewhere to hook in.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/surfaces.php';
require_once __DIR__ . '/rest-users.php';
require_once __DIR__ . '/author-requests.php';
require_once __DIR__ . '/oembed.php';
require_once __DIR__ . '/login-errors.php';
