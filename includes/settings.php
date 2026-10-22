<?php
/**
 * The plugin's settings (Plugins → Eizport → Settings): a page
 * built with @wordpress/build (routes/settings-*), booted by core's
 *
 * @wordpress/boot, with DataViews and DataForm — the Copies list and the
 * General settings (copies, the Export screen, saved defaults, linked
 * settings). It reads and saves through the eizport/get-settings and
 * eizport/update-settings abilities; this file keeps the settings
 * themselves.
 *
 * @package Eizport
 */

namespace Eizport\Settings;

defined( 'ABSPATH' ) || exit;

const OPTION = 'eizport_settings';
const GROUP  = 'eizport_settings';

/**
 * The page's menu slug, and its render callback (both from @wordpress/build).
 */
const SLUG   = 'eizport-settings-wp-admin';
const RENDER = 'eizport_eizport_settings_wp_admin_render_page';

/**
 * Lifetimes a copy can have, in seconds; 0 keeps it until it is deleted.
 *
 * @return array<int, string> Seconds => label.
 */
function lifetime_choices() {
	return array(
		HOUR_IN_SECONDS      => __( '1 hour', 'eizport' ),
		12 * HOUR_IN_SECONDS => __( '12 hours', 'eizport' ),
		DAY_IN_SECONDS       => __( '1 day', 'eizport' ),
		WEEK_IN_SECONDS      => __( '1 week', 'eizport' ),
		0                    => __( 'Until deleted', 'eizport' ),
	);
}

/**
 * Registers the hooks.
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\register' );
	add_action( 'admin_menu', __NAMESPACE__ . '\add_page' );
	add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/eizport.php' ), __NAMESPACE__ . '\action_links' );
}

/**
 * Who saved export defaults belong to.
 *
 * @return array<string, string> Value => label.
 */
function defaults_scope_choices() {
	return array(
		'user' => __( 'Each user', 'eizport' ),
		'site' => __( 'The whole site', 'eizport' ),
	);
}

/**
 * When settings that point to exported items (homepage, posts page, shop…)
 * are added.
 *
 * @return array<string, string> Value => label.
 */
function linked_options_choices() {
	return array(
		'both'   => __( 'When items are checked, and on export', 'eizport' ),
		'check'  => __( 'When items are checked', 'eizport' ),
		'export' => __( 'On export', 'eizport' ),
		'off'    => __( 'Never', 'eizport' ),
	);
}

/**
 * Where a plugin exported "as plugin" keeps the bundle.
 *
 * @return array<string, string> Value => label.
 */
function plugin_content_choices() {
	return array(
		'wxrz'   => __( 'One .wxrz file', 'eizport' ),
		'root'   => __( 'Its files, next to the plugin\'s', 'eizport' ),
		'folder' => __( 'Its files, in a bundle/ folder', 'eizport' ),
	);
}

/**
 * The settings' defaults.
 *
 * @return array{copy_lifetime: int, replace_export: bool, classic_ui: bool, style_import: bool, import_direct: bool, content_files: bool, attached_only: bool, bundle_zip: bool, plugin_content: string, plugin_import_again: bool, defaults_scope: string, linked_options: string, css_markers: bool}
 */
function defaults() {
	return array(
		'copy_lifetime'       => DAY_IN_SECONDS,
		'replace_export'      => true,
		'classic_ui'          => false,
		'style_import'        => true,
		'import_direct'       => true,
		'content_files'       => true,
		'attached_only'       => false,
		'bundle_zip'          => false,
		'plugin_content'      => 'wxrz',
		'plugin_import_again' => false,
		'defaults_scope'      => 'user',
		'linked_options'      => 'both',
		'css_markers'         => true,
	);
}

/**
 * The settings, with defaults.
 *
 * @return array{copy_lifetime: int, replace_export: bool, classic_ui: bool, style_import: bool, import_direct: bool, content_files: bool, attached_only: bool, bundle_zip: bool, plugin_content: string, plugin_import_again: bool, defaults_scope: string, linked_options: string, css_markers: bool}
 */
function get() {
	return sanitize( get_option( OPTION, array() ) );
}

/**
 * Sanitizes the settings: each one from its choices, else its default.
 * Keys not known are dropped.
 *
 * @param mixed $value Settings sent or stored.
 * @return array{copy_lifetime: int, replace_export: bool, classic_ui: bool, style_import: bool, import_direct: bool, content_files: bool, attached_only: bool, bundle_zip: bool, plugin_content: string, plugin_import_again: bool, defaults_scope: string, linked_options: string, css_markers: bool}
 */
function sanitize( $value ) {
	$value    = is_array( $value ) ? $value : array();
	$defaults = defaults();
	$lifetime = isset( $value['copy_lifetime'] ) ? (int) $value['copy_lifetime'] : $defaults['copy_lifetime'];
	$scope    = isset( $value['defaults_scope'] ) ? (string) $value['defaults_scope'] : '';
	$linked   = isset( $value['linked_options'] ) ? (string) $value['linked_options'] : '';
	$content  = isset( $value['plugin_content'] ) ? (string) $value['plugin_content'] : '';

	return array(
		'copy_lifetime'       => isset( lifetime_choices()[ $lifetime ] ) ? $lifetime : $defaults['copy_lifetime'],
		'replace_export'      => array_key_exists( 'replace_export', $value ) ? ! empty( $value['replace_export'] ) : $defaults['replace_export'],
		'classic_ui'          => ! empty( $value['classic_ui'] ),
		'style_import'        => array_key_exists( 'style_import', $value ) ? ! empty( $value['style_import'] ) : $defaults['style_import'],
		'import_direct'       => array_key_exists( 'import_direct', $value ) ? ! empty( $value['import_direct'] ) : $defaults['import_direct'],
		'content_files'       => array_key_exists( 'content_files', $value ) ? ! empty( $value['content_files'] ) : $defaults['content_files'],
		'attached_only'       => ! empty( $value['attached_only'] ),
		'bundle_zip'          => ! empty( $value['bundle_zip'] ),
		'plugin_content'      => isset( plugin_content_choices()[ $content ] ) ? $content : $defaults['plugin_content'],
		'plugin_import_again' => ! empty( $value['plugin_import_again'] ),
		'defaults_scope'      => isset( defaults_scope_choices()[ $scope ] ) ? $scope : $defaults['defaults_scope'],
		'linked_options'      => isset( linked_options_choices()[ $linked ] ) ? $linked : $defaults['linked_options'],
		'css_markers'         => array_key_exists( 'css_markers', $value ) ? ! empty( $value['css_markers'] ) : $defaults['css_markers'],
	);
}

/**
 * Saves settings: the ones given replace the stored ones, the rest stay.
 *
 * @param array $changes Settings to change.
 * @return array{copy_lifetime: int, replace_export: bool, classic_ui: bool, style_import: bool, import_direct: bool, content_files: bool, attached_only: bool, bundle_zip: bool, plugin_content: string, plugin_import_again: bool, defaults_scope: string, linked_options: string, css_markers: bool} The settings saved.
 */
function update( $changes ) {
	$settings = sanitize( array_merge( get(), array_intersect_key( (array) $changes, defaults() ) ) );
	update_option( OPTION, $settings );

	return get();
}

/**
 * How long a copy lasts, in seconds, as chosen.
 *
 * @return int
 */
function copy_lifetime() {
	return get()['copy_lifetime'];
}

/**
 * Registers the option, sanitized.
 */
function register() {
	register_setting(
		GROUP,
		OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'Eizport\Settings\sanitize', // Each field checked against its allowed values.
			'default'           => defaults(),
		)
	);
}

/**
 * URL of the page, on a route.
 *
 * @param string $path Route: "/copies" or "/general".
 * @return string
 */
function page_url( $path = '/general' ) {
	return add_query_arg(
		array(
			'page' => SLUG,
			'p'    => rawurlencode( $path ),
		),
		admin_url( 'plugins.php' )
	);
}

/**
 * Registers the page, when it is built, without a menu item: it opens from
 * the plugin's "Settings" link in Plugins (action_links()). Registered
 * under Plugins, which the admin menu highlights while it is open.
 */
function add_page() {
	if ( ! function_exists( RENDER ) ) {
		return;
	}
	add_plugins_page(
		__( 'Eizport', 'eizport' ),
		__( 'Eizport', 'eizport' ),
		'export',
		SLUG,
		RENDER
	);
	remove_submenu_page( 'plugins.php', SLUG );
	add_filter( 'submenu_file', __NAMESPACE__ . '\submenu_file' );
}

/**
 * On the settings page, the admin menu highlights Plugins → Installed Plugins.
 *
 * @param string|null $submenu_file The submenu item highlighted.
 * @return string|null
 */
function submenu_file( $submenu_file ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the menu item.
	return isset( $_GET['page'] ) && SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ? 'plugins.php' : $submenu_file;
}

/**
 * Adds a "Settings" link to the plugin's row in Plugins.
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function action_links( $links ) {
	if ( current_user_can( 'export' ) ) {
		array_unshift( $links, '<a href="' . esc_url( page_url() ) . '">' . esc_html__( 'Settings', 'eizport' ) . '</a>' );
	}

	return $links;
}
