<?php
/**
 * Exports without the Export screen: the same args, filters and files as
 * the screen, written to a temporary copy instead of sent as a download.
 * Used by the export ability.
 *
 * @package Eizport
 */

namespace Eizport\Runner;

use Eizport\Bundle;
use Eizport\Copies;
use Eizport\Files;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exports to a copy in uploads/eizport/tmp/: a .wxrz, a Playground .zip
 * with `blueprint`, or core's plain WXR (.xml) with `bundle` false.
 *
 * @param array $specs    Post type => `ids` or `filters`, as
 *                        Selection\specs_from_input() returns them; none
 *                        for all content.
 * @param array $settings Bundle settings, as Export\bundle_settings()
 *                        takes them; `bundle` is on unless given false
 *                        (then media, content files and options don't
 *                        apply: they need the bundle).
 * @return array{name: string, size: int, expires: int}|WP_Error
 */
function export( $specs, $settings ) {
	require_once ABSPATH . 'wp-admin/includes/export.php';

	// export_wp() declares its helpers inside itself: it runs once per request.
	if ( function_exists( 'wxr_cdata' ) ) {
		return new WP_Error( 'eizport_export_unavailable', __( 'An export already ran in this request: run each export in its own request.', 'eizport' ) );
	}

	$media_used = $settings['media_used'] ?? null;
	$reviews    = $settings['reviews'] ?? null;
	$menus      = $settings['menus'] ?? null;
	$appearance = (array) ( $settings['appearance'] ?? array() );
	$bundle     = ! array_key_exists( 'bundle', $settings ) || ! empty( $settings['bundle'] ) || ! empty( $settings['blueprint'] );
	$filename   = (string) ( $settings['filename'] ?? '' );
	$settings   = \Eizport\Export\bundle_settings( array_merge( $settings, array( 'bundle' => $bundle ) ) );
	if ( ! $bundle ) {
		$name                 = preg_replace( '/\.xml$/i', '', sanitize_file_name( $filename ) );
		$settings['filename'] = '' === $name ? \Eizport\Export\default_filename() : $name;
	}
	$start = array( 'content' => 'all' );
	if ( null !== $menus ) {
		$start['eizport_menus'] = $menus;
	}
	if ( $appearance ) {
		$start['eizport_appearance'] = $appearance;
	}
	$args   = \Eizport\Selection\apply_specs( $start, $specs );
	$target = Files\temp_file( 'eizport-export' );

	$args = array_merge( $args, $settings, array( 'eizport_target' => $target ) );
	if ( null !== $media_used ) {
		$args['eizport_media_used'] = (bool) $media_used;
	}
	if ( null !== $reviews ) {
		$args['eizport_reviews'] = array_map( 'intval', (array) $reviews );
	}

	// The copy's random part, decided now: a plugin .zip names its folder after it.
	$hash                         = Copies\new_hash();
	$GLOBALS['eizport_copy_hash'] = $hash;

	$headers = headers_list();
	// export_wp() sends download headers; where output already started (WP-CLI), PHP warns about each.
	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- only for export_wp()'s headers, restored below.
		function ( $errno, $message ) {
			return E_WARNING === $errno && false !== strpos( $message, 'Cannot modify header information' );
		}
	);
	ob_start();
	export_wp( $args );
	if ( $bundle ) {
		$written = Bundle\finish_export();
		ob_end_clean();
	} else {
		// Core's WXR, printed by export_wp(), is the file.
		$written = false !== file_put_contents( $target, (string) ob_get_clean() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file of ours.
	}
	restore_error_handler();
	forget_download_headers( $headers );

	$extension = $settings['blueprint'] || $settings['zip'] || ! empty( $settings['as_plugin'] ) ? 'zip' : ( $bundle ? \Eizport\Wxrz\EXTENSION : 'xml' );
	$download  = $settings['filename'] . '.' . $extension;
	$name      = $written ? Copies\keep( $target, $download, $hash ) : '';
	unset( $GLOBALS['eizport_copy_hash'] );
	wp_delete_file( $target );
	if ( '' === $name ) {
		return new WP_Error( 'eizport_export_data_unavailable', __( 'The export could not be written to a file.', 'eizport' ) );
	}
	Copies\schedule_cleanup();

	$path = Copies\path( $name );

	return array(
		'name'    => $name,
		'size'    => (int) filesize( $path ),
		'expires' => Copies\lifetime() ? (int) filemtime( $path ) + Copies\lifetime() : 0,
	);
}

/**
 * Takes back the download headers export_wp() sends, which would otherwise
 * go out with the response of whoever ran the export (a REST request): the
 * ones set before it (the REST server's Content-Type) are put back.
 *
 * @param string[] $before headers_list() before export_wp().
 */
function forget_download_headers( $before ) {
	if ( headers_sent() ) {
		return;
	}
	$names = array( 'Content-Description', 'Content-Disposition', 'Content-Type' );
	foreach ( $names as $name ) {
		header_remove( $name );
	}
	foreach ( $before as $line ) {
		$name = trim( strtok( $line, ':' ) );
		if ( in_array( strtolower( $name ), array_map( 'strtolower', $names ), true ) ) {
			header( $line );
		}
	}
}
