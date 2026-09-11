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
	unset($GLOBALS['wpuo_author_404'], $GLOBALS['wp_rest_server']);
}

/**
 * Sets the capabilities WITHOUT clearing the registration log.
 *
 * `wpuo_test_reset()` empties the hooks, which is what most files want. A file that dispatches a
 * request through the filter chain cannot use it between requests: the chain under test is the one
 * registered at boot, and boot is memoized, so clearing it would leave nothing to dispatch through.
 *
 * @param string[] $capabilities
 */
function wpuo_test_caps(array $capabilities): void {
	$GLOBALS['wpuo_test']['capabilities'] = $capabilities;
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

// ---------------------------------------------------------------------------
// The REST server, shaped like core's
// ---------------------------------------------------------------------------

/**
 * What core's own permission check answers for an anonymous read of the user collection.
 *
 * IT ANSWERS YES, AND THAT IS THE LEAK. `WP_REST_Users_Controller::get_items_permissions_check()`
 * requires `list_users` only for `context=edit`; a `context=view` listing is served to anybody. The
 * harness has to model that faithfully, because a stub that denied anonymous reads would let a
 * completely inert build pass every dispatch assertion below.
 */
function wpuo_test_core_users_permission(mixed $request): mixed {
	return true;
}

function wpuo_test_core_serve(mixed $request): string {
	return 'served';
}

/**
 * The routes core registers under `/wp/v2/users`, in the shape `WP_REST_Server` holds them.
 *
 * Numeric keys are handlers; string keys are route options. Both are present here because a filter
 * that mistakes a route option for a handler is a fatal on a real site.
 *
 * @return array<string, array<int|string, mixed>>
 */
function wpuo_test_core_endpoints(): array {
	$handler = [
		'methods'             => 'GET',
		'callback'            => 'wpuo_test_core_serve',
		'permission_callback' => 'wpuo_test_core_users_permission',
		'args'                => [],
	];

	return [
		'/wp/v2/users'                                          => ['namespace' => 'wp/v2', $handler],
		'/wp/v2/users/(?P<id>[\d]+)'                            => ['namespace' => 'wp/v2', $handler],
		'/wp/v2/users/me'                                       => ['namespace' => 'wp/v2', $handler],
		'/wp/v2/users/(?P<user_id>[\d]+)/application-passwords' => ['namespace' => 'wp/v2', $handler],
		'/wp/v2/posts'                                          => ['namespace' => 'wp/v2', $handler],
	];
}

/**
 * `WP_REST_Server`, stubbed down to the one method that matters here.
 *
 * `get_routes()` applies `rest_endpoints` to a fresh copy every time, as core does — which is why a
 * wrapper installed by that filter cannot accumulate across calls.
 */
class WPUO_Test_Server {
	/** @return array<string, array<int|string, mixed>> */
	public function get_routes(): array {
		return apply_filters('rest_endpoints', wpuo_test_core_endpoints());
	}
}

/**
 * Makes a REST server exist for this request, as `rest_get_server()` does on a real site.
 */
function wpuo_test_rest_server_up(): WPUO_Test_Server {
	$GLOBALS['wp_rest_server'] = new WPUO_Test_Server();

	return $GLOBALS['wp_rest_server'];
}

function wpuo_test_rest_server_down(): void {
	unset($GLOBALS['wp_rest_server']);
}

/**
 * Dispatches a request the way `WP_REST_Server::dispatch()` does, in the same order.
 *
 * `rest_pre_dispatch` first, where a non-null result short-circuits. Then the route table, filtered
 * through `rest_endpoints` and matched as core matches it. Then the handler's `permission_callback`,
 * read as core reads it: a `WP_Error` is the answer, and `false` or `null` becomes `rest_forbidden`.
 *
 * @param string[] $capabilities
 *
 * @return mixed 'served', or the WP_Error that stopped it
 */
function wpuo_test_rest_dispatch(string $route, array $capabilities = []): mixed {
	wpuo_test_caps($capabilities);

	$server  = $GLOBALS['wp_rest_server'] ?? new WPUO_Test_Server();
	$request = new WPUO_Test_Request($route);

	$pre = apply_filters('rest_pre_dispatch', null, $server, $request);

	if (null !== $pre) {
		return $pre;
	}

	foreach ($server->get_routes() as $pattern => $handlers) {
		if (1 !== preg_match('@^' . $pattern . '$@i', $route)) {
			continue;
		}

		foreach ($handlers as $key => $handler) {
			if (! is_numeric($key)) {
				continue;
			}

			$permission = $handler['permission_callback'] ?? null;

			if (null !== $permission) {
				$granted = ($permission)($request);

				if (is_wp_error($granted)) {
					return $granted;
				}

				if (false === $granted || null === $granted) {
					return new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 401]);
				}
			}

			return ($handler['callback'])($request);
		}
	}

	return new WP_Error('rest_no_route', 'No route was found matching the URL and request method.', ['status' => 404]);
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
	$GLOBALS['wpuo_test']['actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['wpuo_test']['filters'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

/**
 * A REAL filter chain, because the defect this suite now covers lives in the chain, not in a
 * callback.
 *
 * A harness whose `add_filter()` only logs can prove that a callback was attached and that it
 * returns the right thing when called directly. That is exactly the evidence a production site
 * produced while serving sixteen login slugs: attached, right priority, right argument count, right
 * `WP_Error` — and the next callback in the chain threw the error away. No assertion about a
 * callback can catch that. Only running the chain can.
 *
 * Priority order, then registration order within a priority, as WordPress does it.
 */
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
	$chain = [];

	foreach (array_merge($GLOBALS['wpuo_test']['filters'], $GLOBALS['wpuo_test']['actions']) as $index => $entry) {
		if ($hook !== $entry['hook']) {
			continue;
		}

		$chain[] = $entry + ['index' => $index];
	}

	usort($chain, static fn (array $a, array $b): int => [$a['priority'], $a['index']] <=> [$b['priority'], $b['index']]);

	foreach ($chain as $entry) {
		$accepted = max(1, (int) ($entry['accepted_args'] ?? 1));
		$passed   = array_slice(array_merge([$value], $args), 0, $accepted);
		$value    = ($entry['callback'])(...$passed);
	}

	return $value;
}

function has_filter(string $hook, callable|string $callback): bool {
	foreach (array_merge($GLOBALS['wpuo_test']['filters'], $GLOBALS['wpuo_test']['actions']) as $entry) {
		if ($hook === $entry['hook'] && $callback === $entry['callback']) {
			return true;
		}
	}

	return false;
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
