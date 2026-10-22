<?php
/**
 * Links to the source site, after an import with the WordPress Importer: it
 * maps media URLs only, so a link to a page, a product or a category keeps
 * the other site's address. When the import ends, the source site's address
 * (the WXR's base_site_url) becomes this site's in the imported items'
 * content and excerpt (new and updated, plain and escaped in block
 * attributes) and in the custom links of the imported menu items. Loaded by
 * the plugin and appended to the temporary must-use plugin of a Playground
 * .zip (playground-media.php): no namespace, no other files.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_replace_source_links' ) ) {
	/**
	 * The text with the source site's address replaced by this site's: the
	 * plain and the JSON-escaped forms, http and https, only where the
	 * address ends (old.example never matches old.example.org; a sentence's
	 * full stop after it is fine).
	 *
	 * @param string   $text Text.
	 * @param string   $from Source site's address, without trailing slash.
	 * @param string   $to   This site's address, without trailing slash.
	 * @param string[] $keep This site's addresses (home, uploads) never touched.
	 * @return string
	 */
	function eizport_replace_source_links( $text, $from, $to, $keep = array() ) {
		$from = untrailingslashit( (string) $from );
		$to   = untrailingslashit( (string) $to );
		if ( '' === $text || '' === $from || $from === $to ) {
			return $text;
		}
		// The source's address comes from the file: only a plain http(s)://host[:port][/path] is
		// replaced, so no quote or bracket of it can close an attribute kses already checked.
		if ( ! preg_match( '#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~%/-]*)?$#', $from ) ) {
			return $text;
		}
		// Addresses that are already this site's (its home, its uploads) are never touched: on a
		// network, the source can be a prefix of them (http://site → http://site/b, http://site/wp-content/uploads/sites/3).
		$held = array();
		foreach ( array_filter( array_map( 'untrailingslashit', (array) $keep ) ) as $address ) {
			// Only addresses the source's is a prefix of are at risk; a shorter one is what may need replacing.
			if ( 0 !== stripos( (string) preg_replace( '#^https?:#i', '', $address ), (string) preg_replace( '#^https?:#i', '', $from ) ) || $address === $from ) {
				continue;
			}
			foreach ( array( $address, str_replace( '/', '\\/', $address ) ) as $form ) {
				if ( false !== strpos( $text, $form ) ) {
					$token          = "\x00eizport-keep-" . count( $held ) . "\x00";
					$held[ $token ] = $form;
					$text           = str_replace( $form, $token, $text );
				}
			}
		}
		$host  = (string) preg_replace( '#^https?://#i', '', $from );
		$pairs = array(
			'//' . $host                                => '//' . preg_replace( '#^https?://#i', '', $to ),
			'\\/\\/' . str_replace( '/', '\\/', $host ) => '\\/\\/' . str_replace( '/', '\\/', (string) preg_replace( '#^https?://#i', '', $to ) ),
		);
		// The scheme goes with the host: http(s)://old → this site's scheme and host.
		$scheme = (string) wp_parse_url( $to, PHP_URL_SCHEME );
		foreach ( $pairs as $old => $new ) {
			$text = (string) preg_replace_callback(
				'#(https?:)?' . preg_quote( $old, '#' ) . '(?=$|[/\\\\"\'?\#<>\s),]|\.(?![a-z0-9-]))#i',
				function ( $m ) use ( $new, $scheme ) {
					return ( '' === ( $m[1] ?? '' ) ? '' : $scheme . ':' ) . $new;
				},
				$text
			);
		}

		return strtr( $text, $held );
	}

	/**
	 * The source site's address: the WXR's base_blog_url (on a network,
	 * base_site_url is the network's, not the site's), read from the file
	 * being imported; else the importer's base_url.
	 *
	 * @param object $importer The WordPress Importer.
	 * @return string
	 */
	function eizport_source_home( $importer ) {
		$file = (string) ( $GLOBALS['eizport_wxr_file'] ?? '' );
		if ( '' === $file && ! empty( $importer->id ) ) {
			$file = (string) get_attached_file( (int) $importer->id );
		}
		$home = '' === $file ? '' : eizport_wxr_blog_url( $file );

		return '' !== $home ? $home : (string) ( $importer->base_url ?? '' );
	}

	/**
	 * A WXR's <wp:base_blog_url>, from its first lines.
	 *
	 * @param string $file WXR path.
	 * @return string
	 */
	function eizport_wxr_blog_url( $file ) {
		$handle = is_readable( $file ) ? fopen( $file, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the head of a local WXR.
		if ( ! $handle ) {
			return '';
		}
		$url = '';
		for ( $i = 0; $i < 400; $i++ ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			if ( preg_match( '#<wp:base_blog_url>(.*?)</wp:base_blog_url>#', $line, $m ) ) {
				$url = trim( $m[1] );
				break;
			}
			if ( false !== strpos( $line, '<item>' ) ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return esc_url_raw( $url );
	}

	/**
	 * `import_end`: replaces the source site's address in the imported
	 * items and menu items. Written straight to the tables (no revision, no
	 * kses, no hooks), as the block IDs are.
	 *
	 * @return int Posts changed.
	 */
	function eizport_remap_source_links() {
		global $wpdb;

		$importer = $GLOBALS['wp_import'] ?? null;
		$from     = is_object( $importer ) ? eizport_source_home( $importer ) : '';
		$to       = home_url();
		$keep     = array( $to, site_url(), wp_get_upload_dir()['baseurl'] );
		if ( '' === $from || untrailingslashit( $from ) === untrailingslashit( $to ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( array_unique( array_map( 'intval', (array) $importer->processed_posts ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' === $post->post_type ) {
				continue;
			}
			$fields = array(
				'post_content' => eizport_replace_source_links( $post->post_content, $from, $to, $keep ),
				'post_excerpt' => eizport_replace_source_links( $post->post_excerpt, $from, $to, $keep ),
			);
			if ( $fields['post_content'] !== $post->post_content || $fields['post_excerpt'] !== $post->post_excerpt ) {
				$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per imported post; see the docblock.
				clean_post_cache( $post->ID );
				++$changed;
			}
		}
		// Custom links in the classic menus imported.
		foreach ( array_map( 'intval', (array) ( $importer->processed_menu_items ?? array() ) ) as $item ) {
			$url = (string) get_post_meta( $item, '_menu_item_url', true );
			$new = eizport_replace_source_links( $url, $from, $to, $keep );
			if ( $new !== $url ) {
				update_post_meta( $item, '_menu_item_url', $new );
			}
		}

		return $changed;
	}

	add_action( 'import_end', 'eizport_remap_source_links', 16 );
}
