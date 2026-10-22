<?php
/**
 * Saved export defaults: the choices "Save as default" keeps (content
 * types with their items or filters, the file's settings, options,
 * Playground), so the Export screens start with them. They belong to each
 * user (user meta) or to the whole site (an option), as Settings →
 * Eizport → Saved defaults says.
 *
 * Their shape is the new Export screen's state: `types` (post type => `ids`
 * or `filters`) and `settings`.
 *
 * @package Eizport
 */

namespace Eizport\Defaults;

defined( 'ABSPATH' ) || exit;

const KEY = 'eizport_export_defaults';

/**
 * Whether defaults are the site's, not each user's.
 *
 * @return bool
 */
function site_wide() {
	return 'site' === \Eizport\Settings\get()['defaults_scope'];
}

/**
 * Whether the current user may save or clear the defaults: anyone who
 * exports their own, administrators the site's.
 *
 * @return bool
 */
function can_save() {
	return current_user_can( site_wide() ? 'manage_options' : 'export' );
}

/**
 * The saved defaults, sanitized; empty when none.
 *
 * @return array{types?: array, settings?: array}
 */
function get() {
	$saved = site_wide() ? get_option( KEY, array() ) : user_defaults();

	return is_array( $saved ) && $saved ? sanitize( $saved ) : array();
}

/**
 * The current user's defaults on this site: a user option, one per site of
 * a network (users are shared, their defaults aren't). Defaults saved
 * before as user meta are moved there once, on a single site or the
 * network's main site only.
 *
 * @return mixed
 */
function user_defaults() {
	global $wpdb;

	$user = get_current_user_id();
	// Not get_user_option(): without this site's key it falls back to the unprefixed one, every site's.
	$saved = get_user_meta( $user, $wpdb->get_blog_prefix() . KEY, true );
	if ( ! $saved && is_main_site() ) {
		$old = get_user_meta( $user, KEY, true );
		if ( is_array( $old ) && $old ) {
			update_user_option( $user, KEY, $old );
			delete_user_meta( $user, KEY );
			$saved = $old;
		}
	}

	return $saved;
}

/**
 * Saves defaults (sanitized) for the user or the site.
 *
 * @param array $defaults `types` and `settings`.
 * @return array The defaults saved.
 */
function save( $defaults ) {
	$defaults = sanitize( (array) $defaults );
	if ( site_wide() ) {
		update_option( KEY, $defaults, false );
	} else {
		update_user_option( get_current_user_id(), KEY, $defaults );
	}

	return $defaults;
}

/**
 * Forgets the defaults of the user or the site.
 */
function clear() {
	if ( site_wide() ) {
		delete_option( KEY );
	} else {
		delete_user_option( get_current_user_id(), KEY );
	}
}

/**
 * Keeps what can be exported from what was sent: known content types with
 * valid items or filters, options that may be exported, installed themes
 * and plugins.
 *
 * @param array $defaults Defaults sent.
 * @return array{types: array, settings: array}
 */
function sanitize( $defaults ) {
	$types = \Eizport\Selection\specs_from_input(
		array_intersect_key( (array) ( $defaults['types'] ?? array() ), \Eizport\Selection\content_types() )
	);
	$in    = (array) ( $defaults['settings'] ?? array() );
	$names = function ( $key ) use ( $in ) {
		return array_values( array_unique( array_map( 'strval', array_filter( (array) ( $in[ $key ] ?? array() ), 'is_scalar' ) ) ) );
	};

	// Rows that bring what the items chosen suggest.
	$rows    = array_merge( array( '__options', '__reviews', '__menus', '__theme_mods', '__custom_css', '__global_styles', '__global_css', '__widgets' ), array_keys( \Eizport\Selection\content_types() ) );
	$suggest = array_values( array_intersect( $names( 'include_suggested' ), $rows ) );

	// Only the filters set: the export's schema refuses empty ones.
	$types = is_wp_error( $types ) ? array() : $types;
	foreach ( $types as $type => $spec ) {
		if ( isset( $spec['filters'] ) ) {
			$types[ $type ]['filters'] = (object) array_filter( $spec['filters'] );
		}
	}

	return array(
		'types'    => $types,
		'settings' => array(
			'format'            => 'playground' === ( $in['format'] ?? '' ) ? 'playground' : 'wxr',
			'filename'          => preg_replace( '/\.(xml|zip|wxrz)$/i', '', sanitize_file_name( (string) ( $in['filename'] ?? '' ) ) ),
			'media'             => ! array_key_exists( 'media', $in ) || ! empty( $in['media'] ),
			'option_all'        => ! empty( $in['option_all'] ),
			'option_names'      => array_values( array_filter( $names( 'option_names' ), fn( $name ) => ! \Eizport\Options\is_denied( $name ) ) ),
			'option_groups'     => array_values( array_intersect( $names( 'option_groups' ), array_keys( \Eizport\Options\groups() ) ) ),
			'include_suggested' => $suggest,
			'review_ids'        => array_values( array_unique( array_map( 'absint', array_filter( (array) ( $in['review_ids'] ?? array() ), 'is_numeric' ) ) ) ),
			'review_all'        => ! empty( $in['review_all'] ),
			'menu_ids'          => array_values( preg_grep( '/^[cn]:\d+$/', $names( 'menu_ids' ) ) ),
			'menu_all'          => ! empty( $in['menu_all'] ),
			'appearance'        => array_values( array_intersect( \Eizport\Appearance\KEYS, $names( 'appearance' ) ) ),
			'appearance_items'  => array_map(
				function ( $keys ) {
					return array_values( array_filter( array_map( 'strval', (array) $keys ), 'strlen' ) );
				},
				array_intersect_key( (array) ( $in['appearance_items'] ?? array() ), array_flip( \Eizport\Appearance\KEYS ) )
			),
			'css_mode'          => in_array( $in['css_mode'] ?? '', array( 'end', 'start', 'replace', 'replace_all' ), true ) ? $in['css_mode'] : 'end',
			'as_plugin'         => ! empty( $in['as_plugin'] ),
			'update_existing'   => ! empty( $in['update_existing'] ),
			'landing'           => in_array( $in['landing'] ?? '', array( 'home', 'admin', 'page', 'path' ), true ) ? $in['landing'] : 'home',
			'landing_page'      => absint( $in['landing_page'] ?? 0 ),
			'language'          => in_array( $in['language'] ?? '', array( 'site', 'user', 'en_US' ), true ) ? $in['language'] : 'site',
			'landing_path'      => '/' === substr( (string) ( $in['landing_path'] ?? '' ), 0, 1 ) ? sanitize_text_field( (string) $in['landing_path'] ) : '',
			'themes'            => array_values( array_intersect( $names( 'themes' ), array_keys( \Eizport\Playground\theme_choices() ) ) ),
			'plugins'           => array_values( array_intersect( $names( 'plugins' ), array_keys( \Eizport\Playground\plugin_choices() ) ) ),
			'mu_plugins'        => \Eizport\Playground\mu_plugin_files( $names( 'mu_plugins' ) ),
		),
	);
}
