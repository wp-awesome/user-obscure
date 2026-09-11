<?php
/**
 * A WordPress-shaped test harness with no WordPress in it.
 *
 * Anything the package calls that this file does not define is a fatal error, which is the point: it
 * is how "loading this package touches no database" is proved rather than asserted. The surface
 * stubbed below is the complete list of what the package is allowed to depend on.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

/**
 * @param array<string, mixed> $options
 * @param string[]             $capabilities
 */
function wpuo_test_reset(array $options = [], array $capabilities = []): void {
	$GLOBALS['wpuo_test'] = [
		'options'         => $options,
		'capabilities'    => $capabilities,
		'is_admin'        => false,
		'filters'         => [],
		'actions'         => [],
		'settings'        => [],
		'settings_errors' => [],
		'headers'         => [],
		'nocache'         => 0,
		'option_guard'    => false,
	];

	$GLOBALS['wp_query'] = new WPUO_Test_Query();
	unset($GLOBALS['wpuo_author_404']);
}

function wpuo_test_set(string $key, mixed $value): void {
	$GLOBALS['wpuo_test'][$key] = $value;
}

function wpuo_test_get(string $key): mixed {
	return $GLOBALS['wpuo_test'][$key];
}

/**
 * The hook names registered so far, in registration order.
 *
 * @return string[]
 */
function wpuo_test_hooked(): array {
	return array_column(array_merge($GLOBALS['wpuo_test']['filters'], $GLOBALS['wpuo_test']['actions']), 'hook');
}

wpuo_test_reset();

// ---------------------------------------------------------------------------
// WordPress surface
// ---------------------------------------------------------------------------

class WP_Error {
	/** @var array<string, string[]> */
	private array $errors = [];

	/** @var array<string, mixed> */
	public array $error_data = [];

	public function __construct(string $code = '', string $message = '', mixed $data = '') {
		if ('' !== $code) {
			$this->errors[$code][] = $message;

			if ('' !== $data) {
				$this->error_data[$code] = $data;
			}
		}
	}

	public function add(string $code, string $message): void {
		$this->errors[$code][] = $message;
	}

	/** @return string[] */
	public function get_error_codes(): array {
		return array_keys($this->errors);
	}

	public function get_error_code(): string {
		return (string) (array_key_first($this->errors) ?? '');
	}

	public function get_error_message(string $code = ''): string {
		$code = '' === $code ? $this->get_error_code() : $code;

		return $this->errors[$code][0] ?? '';
	}

	public function get_error_data(string $code = ''): mixed {
		$code = '' === $code ? $this->get_error_code() : $code;

		return $this->error_data[$code] ?? null;
	}
}

function is_wp_error(mixed $thing): bool {
	return $thing instanceof WP_Error;
}

/**
 * The main query, stubbed down to the one method this package calls on it.
 */
class WPUO_Test_Query {
	public bool $is_404 = false;

	public function set_404(): void {
		$this->is_404 = true;
	}
}

/**
 * A REST request, stubbed down to the one method this package calls on it.
 */
class WPUO_Test_Request {
	public function __construct(private mixed $route = '/') {
	}

	public function get_route(): mixed {
		return $this->route;
	}
}

function get_option(string $name, mixed $default = false): mixed {
	if (true === $GLOBALS['wpuo_test']['option_guard']) {
		throw new RuntimeException(sprintf('get_option(%s) was called while the database was fenced off', $name));
	}

	return array_key_exists($name, $GLOBALS['wpuo_test']['options'])
		? $GLOBALS['wpuo_test']['options'][$name]
		: $default;
}

function update_option(string $name, mixed $value): bool {
	if (true === $GLOBALS['wpuo_test']['option_guard']) {
		throw new RuntimeException(sprintf('update_option(%s) was called while the database was fenced off', $name));
	}

	$GLOBALS['wpuo_test']['options'][$name] = $value;

	return true;
}

function is_admin(): bool {
	return (bool) $GLOBALS['wpuo_test']['is_admin'];
}

function current_user_can(string $capability): bool {
	return in_array($capability, $GLOBALS['wpuo_test']['capabilities'], true);
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['wpuo_test']['actions'][] = compact('hook', 'callback', 'priority');
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['wpuo_test']['filters'][] = compact('hook', 'callback', 'priority');
}

function __return_false(): bool {
	return false;
}

function status_header(int $code): void {
	$GLOBALS['wpuo_test']['headers'][] = $code;
}

function nocache_headers(): void {
	$GLOBALS['wpuo_test']['nocache']++;
}

function add_options_page(string $page_title, string $menu_title, string $capability, string $slug, callable $callback): void {
	$GLOBALS['wpuo_test']['settings']['page'] = compact('page_title', 'menu_title', 'capability', 'slug');
}

/** @param array<string, mixed> $args */
function register_setting(string $group, string $option, array $args = []): void {
	$GLOBALS['wpuo_test']['settings']['options'][$option] = ['group' => $group, 'args' => $args];
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void {
	$GLOBALS['wpuo_test']['settings_errors'][] = compact('setting', 'code', 'message');
}

function settings_errors(string $setting = ''): void {
}

function settings_fields(string $group): void {
}

function submit_button(): void {
	echo '<button type="submit">Save</button>';
}

function checked(mixed $checked, mixed $current = true, bool $display = true): string {
	return $checked === $current ? " checked='checked'" : '';
}

function esc_html(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

function wpuo_assert_same(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(sprintf(
			"%s\n    expected: %s\n    actual:   %s",
			$message,
			var_export($expected, true),
			var_export($actual, true)
		));
	}
}

function wpuo_assert_true(bool $actual, string $message): void {
	wpuo_assert_same(true, $actual, $message);
}

function wpuo_assert_false(bool $actual, string $message): void {
	wpuo_assert_same(false, $actual, $message);
}

function wpuo_assert_contains(string $needle, string $haystack, string $message): void {
	if (! str_contains($haystack, $needle)) {
		throw new RuntimeException(sprintf("%s\n    missing: %s", $message, $needle));
	}
}

function wpuo_assert_not_contains(string $needle, string $haystack, string $message): void {
	if (str_contains($haystack, $needle)) {
		throw new RuntimeException(sprintf("%s\n    unexpectedly present: %s", $message, $needle));
	}
}

function wpuo_test_done(string $name): void {
	echo $name . ": ok\n";
}
