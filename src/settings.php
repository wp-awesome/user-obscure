<?php
/**
 * One screen, four checkboxes, and one thing the screen states but cannot change.
 *
 * THE SCREEN MUST MEAN WHAT IT SAYS. The sibling package shipped a constant that made its gate inert
 * on production while its settings page went on offering three modes; it was removed for lying to
 * the operator, and nothing equivalent exists here. A ticked box on this screen obscures that
 * surface, in every environment, with no constant able to quietly countermand it.
 *
 * The author-archive declaration is rendered as a STATEMENT OF FACT, never as a control. Showing it
 * as a disabled checkbox would be the same lie in a different shape — an operator would read it as
 * something they could change if only they had permission, when what it actually needs is a
 * developer editing a constant.
 *
 * A stored value the checkbox cannot produce is surfaced as a warning rather than silently resolved.
 * "Off because nobody ticked it" and "off because the row holds something unreadable" are different
 * states, and a screen that renders them identically is how a control stays broken for months.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPUO_SETTINGS_GROUP = 'wp_user_obscure';
const WPUO_SETTINGS_SLUG  = 'wp-user-obscure';

function wpuo_settings_bootstrap(): bool {
	add_action('admin_menu', 'wpuo_settings_menu');
	add_action('admin_init', 'wpuo_settings_register');

	return true;
}

function wpuo_settings_menu(): void {
	add_options_page(
		'User Obscure',
		'User Obscure',
		'manage_options',
		WPUO_SETTINGS_SLUG,
		'wpuo_settings_render_page'
	);
}

function wpuo_settings_register(): void {
	foreach (wpuo_surfaces() as $surface) {
		$key = wpuo_option_key($surface);

		if ('' === $key) {
			continue;
		}

		register_setting(WPUO_SETTINGS_GROUP, $key, [
			'type'              => 'string',
			'sanitize_callback' => 'wpuo_sanitize_toggle',
			'default'           => '0',
		]);
	}
}

/**
 * An unticked checkbox posts nothing at all, so the absent case must store '0' rather than be left
 * alone — otherwise a surface could never be switched back off from this screen.
 *
 * @param mixed $value
 */
function wpuo_sanitize_toggle(mixed $value = null): string {
	return is_scalar($value) && '1' === (string) $value ? '1' : '0';
}

/**
 * The raw stored value, so the screen can show what is actually in the database rather than hiding
 * corruption behind the resolved answer.
 *
 * @return mixed
 */
function wpuo_stored(string $surface): mixed {
	$key = wpuo_option_key($surface);

	return '' === $key ? '' : get_option($key, '');
}

/**
 * Whether the stored value is one the control could have written. Anything else is reported.
 */
function wpuo_stored_is_readable(string $surface): bool {
	$stored = wpuo_stored($surface);

	return is_scalar($stored) && in_array((string) $stored, ['', '0', '1'], true);
}

/**
 * @return array<string, array{label: string, description: string}>
 */
function wpuo_settings_fields(): array {
	return [
		'rest_users'   => [
			'label'       => 'REST user listing',
			'description' => 'Answer <code>/wp-json/wp/v2/users</code> with 401 unless the requester can list users or create content. The route stays registered, so the block editor keeps working.',
		],
		'author_probe' => [
			'label'       => 'Author id probing',
			'description' => 'Answer <code>/?author=1</code> with 404 instead of redirecting to the author\'s archive, which hands over the login slug.',
		],
		'oembed'       => [
			'label'       => 'oEmbed author fields',
			'description' => 'Remove <code>author_name</code> and <code>author_url</code> from <code>/wp-json/oembed/1.0/embed</code>.',
		],
		'login_errors' => [
			'label'       => 'Login error messages',
			'description' => 'Answer an unknown username and a wrong password identically, so the form cannot be used to confirm that an account exists.',
		],
	];
}

function wpuo_settings_render_page(): void {
	if (! current_user_can('manage_options')) {
		return;
	}

	echo '<div class="wrap"><h1>User Obscure</h1>';

	echo '<p>Each surface below leaks account names to anybody who asks. None of this stops a password'
		. ' attack &mdash; rate limiting and two-factor authentication do that. This removes the free'
		. ' reconnaissance step that comes before one.</p>';

	settings_errors(WPUO_SETTINGS_GROUP);

	echo '<form action="options.php" method="post">';
	settings_fields(WPUO_SETTINGS_GROUP);
	echo '<table class="form-table" role="presentation"><tbody>';

	foreach (wpuo_settings_fields() as $surface => $field) {
		$key = wpuo_option_key($surface);

		if ('' === $key) {
			continue;
		}

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1"%s> Obscure this surface</label>',
			esc_html($field['label']),
			esc_attr($key),
			checked(wpuo_obscuring($surface), true, false)
		);

		printf('<p class="description">%s</p>', $field['description']);

		if (! wpuo_stored_is_readable($surface)) {
			printf(
				'<p class="description" style="color:#b32d2e"><strong>This setting is stored as something this'
				. ' screen cannot have written, so it is being ignored and the surface is NOT obscured.</strong>'
				. ' Option <code>%s</code>. Tick and save to repair it.</p>',
				esc_html($key)
			);
		}

		echo '</td></tr>';
	}

	printf(
		'<tr><th scope="row">Author archives</th><td><p><strong>%s</strong></p><p class="description">%s</p></td></tr>',
		WPUO_ARCHIVES_UNUSED === wpuo_author_archives()
			? 'Declared unused &mdash; <code>/author/&lt;name&gt;/</code> answers 404.'
			: 'Declared in use &mdash; author archives are served normally.',
		'This is not a setting. Whether the theme links to author archives is a fact about the site\'s'
			. ' templates and about URLs that may already be published and indexed, so it is declared in code:'
			. ' <code>WP_USER_OBSCURE_AUTHOR_ARCHIVES</code>. Ask a developer to change it.'
	);

	echo '</tbody></table>';
	submit_button();
	echo '</form></div>';
}
