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
	 * address ends (old.example never matches old.example.org).
	 *
	 * @param string $text Text.
	 * @param string $from Source site's address, without trailing slash.
	 * @param string $to   This site's address, without trailing slash.
	 * @return string
	 */
	function eizport_replace_source_links( $text, $from, $to ) {
		$from = untrailingslashit( (string) $from );
		$to   = untrailingslashit( (string) $to );
		if ( '' === $text || '' === $from || $from === $to ) {
			return $text;
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
				'#(https?:)?' . preg_quote( $old, '#' ) . '(?=$|[/\\\\"\'?\#<>\s)])#i',
				function ( $m ) use ( $new, $scheme ) {
					return ( '' === ( $m[1] ?? '' ) ? '' : $scheme . ':' ) . $new;
				},
				$text
			);
		}

		return $text;
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
		$from     = is_object( $importer ) ? (string) $importer->base_url : '';
		$to       = home_url();
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
				'post_content' => eizport_replace_source_links( $post->post_content, $from, $to ),
				'post_excerpt' => eizport_replace_source_links( $post->post_excerpt, $from, $to ),
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
			$new = eizport_replace_source_links( $url, $from, $to );
			if ( $new !== $url ) {
				update_post_meta( $item, '_menu_item_url', $new );
			}
		}

		return $changed;
	}

	add_action( 'import_end', 'eizport_remap_source_links', 16 );
}
