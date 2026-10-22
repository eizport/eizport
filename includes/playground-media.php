<?php
/**
 * Must-use plugin that a Playground blueprint writes to
 * wp-content/mu-plugins/eizport-media.php for the import only, and removes
 * after it. From the files the blueprint wrote to wp-content/eizport-media/,
 * like Bundle\serve_files() and Bundle\merge_post_content() do for a .wxrz:
 *
 * - answers the importer's attachment downloads (map.json: URL => file);
 * - puts each item's post_content/<slug>.html into its content
 *   (content.json: file => original id, type and slug; else by slug);
 * - when the import ends, sets the options of options.json with
 *   eizport_apply_options(), from the other temporary plugin
 *   (apply-options.php), with the IDs the importer gave the items.
 *
 * Playground's importWxr step runs the WordPress Importer's WP_Import, so
 * its `wp_import_post_data_raw` filter applies; ours runs first, before
 * Playground rewrites the URLs in the content. Eizport never
 * loads this file.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		static $map = null;

		$dir = dirname( __DIR__ ) . '/eizport-media';
		if ( null === $map ) {
			$json = is_file( $dir . '/map.json' ) ? json_decode( (string) file_get_contents( $dir . '/map.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$map  = is_array( $json ) ? $json : array();
		}
		if ( false !== $response || ! isset( $map[ $url ] ) || ! is_string( $map[ $url ] ) || 0 !== strpos( $map[ $url ], 'uploads/' ) || false !== strpos( $map[ $url ], '..' ) ) {
			return $response;
		}
		$path = $dir . '/' . $map[ $url ];
		if ( ! is_file( $path ) ) {
			return $response;
		}

		$body = '';
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $path, $args['filename'] );
		} else {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return array(
			'headers'  => array(
				'content-type'   => wp_check_filetype( $path )['type'],
				'content-length' => (string) filesize( $path ),
			),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	},
	10,
	3
);

add_filter(
	'wp_import_post_data_raw',
	function ( $post ) {
		static $by_id = null;

		$dir = dirname( __DIR__ ) . '/eizport-media/post_content';
		if ( null === $by_id ) {
			$json  = is_file( dirname( $dir ) . '/content.json' ) ? json_decode( (string) file_get_contents( dirname( $dir ) . '/content.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$by_id = array();
			foreach ( is_array( $json ) ? $json : array() as $file => $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$by_id[ (int) $item['id'] ] = (string) $file;
				}
			}
		}
		if ( ! is_array( $post ) || in_array( $post['post_type'] ?? '', array( 'attachment', 'nav_menu_item' ), true ) ) {
			return $post;
		}

		$slug = (string) ( $post['post_name'] ?? '' );
		$file = $by_id[ (int) ( $post['post_id'] ?? 0 ) ] ?? ( '' === $slug ? '' : $slug . '.html' );
		// One level, no hidden files: as Bundle\is_content_name().
		if ( ! preg_match( '/^[A-Za-z0-9%_-][A-Za-z0-9%._-]*\.html$/', $file ) || ! is_file( $dir . '/' . $file ) ) {
			return $post;
		}
		$post['post_content'] = (string) file_get_contents( $dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return $post;
	},
	5
);

add_action(
	'import_start',
	function () {
		// ACF's post types, taxonomies and field groups before the items that use them (import-advanced-custom-fields.php).
		$path = dirname( __DIR__ ) . '/eizport-media/options.json';
		$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( function_exists( 'eizport_acf_start' ) ) {
			eizport_acf_start( $data );
		}
	}
);

add_action(
	'import_end',
	function () {
		$path     = dirname( __DIR__ ) . '/eizport-media/options.json';
		$data     = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$importer = $GLOBALS['wp_import'] ?? null;
		if ( is_array( $data ) && function_exists( 'eizport_apply_options' ) ) {
			eizport_apply_options(
				$data,
				is_object( $importer ) ? (array) $importer->processed_posts : array(),
				is_object( $importer ) ? (array) $importer->processed_terms : array(),
				is_object( $importer ) ? eizport_urls_with_source( (array) $importer->url_remap, function_exists( 'eizport_source_home' ) ? eizport_source_home( $importer ) : (string) $importer->base_url ) : array()
			);
		}
	}
);
