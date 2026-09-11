<?php
/**
 * A WordPress-shaped test harness with no WordPress in it.
 *
 * Anything the package calls that this file does not define is a fatal error, which is the point: it
 * is how "this package touches no database" is proved rather than asserted. The surface stubbed
 * below is the complete list of what the package is allowed to depend on.
 *
 * THERE IS NO OPTIONS TABLE HERE AT ALL, AND THAT IS THE CENTRAL ASSERTION OF THE SUITE. This
 * package once read four option rows. It reads none now, and the way that is proved is not a test
 * somebody has to remember to write: `get_option()` and `update_option()` throw unconditionally, in
 * every process, for the whole run. A stored value cannot silently disable a surface if no code path
 * can reach a stored value without taking the suite down.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

/**
 * @param string[] $capabilities
 */
function wpuo_test_reset(array $capabilities = []): void {
	$GLOBALS['wpuo_test'] = [
		'capabilities' => $capabilities,
		'is_admin'     => false,
		'filters'      => [],
		'actions'      => [],
		'headers'      => [],
		'nocache'      => 0,
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

/**
 * Defined only so that reading an option fails BY NAME rather than as "undefined function".
 *
 * The fence is permanent and unconditional. This package has no options, and a future change that
 * reintroduces one cannot pass the suite by accident.
 */
function get_option(string $name, mixed $default = false): mixed {
	throw new RuntimeException(sprintf(
		'get_option(%s): this package reads no options, and a stored value must never be able to change what it does',
		$name
	));
}

function update_option(string $name, mixed $value): bool {
	throw new RuntimeException(sprintf('update_option(%s): this package writes no options', $name));
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

/**
 * The source of one of this package's own files, for the structural assertions.
 */
function wpuo_test_source(string $file): string {
	return (string) file_get_contents(dirname(__DIR__) . '/' . $file);
}

/**
 * Every PHP file this package ships.
 *
 * Globbed rather than listed, so a file added later is covered by the structural assertions without
 * anybody having to remember to add it — which is exactly how `src/settings.php` would come back.
 *
 * @return string[]
 */
function wpuo_test_sources(): array {
	$files = array_merge(
		glob(dirname(__DIR__) . '/*.php') ?: [],
		glob(dirname(__DIR__) . '/src/*.php') ?: []
	);

	sort($files);

	return array_map(static fn (string $path): string => str_replace(dirname(__DIR__) . '/', '', $path), $files);
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
