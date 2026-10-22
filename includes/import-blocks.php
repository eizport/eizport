<?php
/**
 * Post IDs inside block content, after an import with the WordPress
 * Importer: it maps media URLs only, so an Image block keeps `"id":12` and
 * `wp-image-12` from the other site, a Gallery its `"ids"`, a navigation
 * link the ID of its page. When the import ends, the imported items'
 * content (new and updated) gets the IDs the items have here. Loaded by the
 * plugin, appended to the temporary must-use plugin of a Playground .zip
 * (playground-media.php), and used by apply-options.php for block widgets:
 * no namespace, no other files.
 *
 * Only IDs that came in the file and changed are touched, in one pass (an
 * ID already replaced is never replaced again).
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_replace_block_ids' ) ) {
	/**
	 * Core blocks and the attribute that holds a post ID (or IDs).
	 *
	 * @return array<string, string[]> Block name (without "core/") => attributes.
	 */
	function eizport_block_id_attributes() {
		$attributes = array(
			'image'              => array( 'id' ),
			'cover'              => array( 'id' ),
			'file'               => array( 'id' ),
			'video'              => array( 'id' ),
			'audio'              => array( 'id' ),
			'media-text'         => array( 'mediaId' ),
			'gallery'            => array( 'ids' ),
			'navigation-link'    => array( 'id' ),
			'navigation-submenu' => array( 'id' ),
		);

		/**
		 * Filters the blocks whose attributes hold post IDs that an import
		 * maps to the imported items (a plugin adds its own blocks, by full
		 * name: "my-plugin/my-block").
		 *
		 * @param array<string, string[]> $attributes Block name => attribute names.
		 */
		return (array) apply_filters( 'eizport_block_id_attributes', $attributes );
	}

	/**
	 * The content with the post IDs of its blocks mapped: the attributes of
	 * eizport_block_id_attributes(), `wp-image-N` classes, gallery images'
	 * `data-id` and `[gallery ids="…"]`.
	 *
	 * @param string          $content Content.
	 * @param array<int, int> $map     Old ID => new ID (only the ones that changed).
	 * @return string
	 */
	function eizport_replace_block_ids( $content, $map ) {
		if ( ! $map || '' === $content ) {
			return $content;
		}
		$to     = function ( $id ) use ( $map ) {
			return (string) ( $map[ (int) $id ] ?? (int) $id );
		};
		$blocks = eizport_block_id_attributes();

		// Block attributes, in the block comment.
		$content = (string) preg_replace_callback(
			'/(<!--\s+wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s+)(\{.*?\})(\s+\/?-->)/s',
			function ( $block ) use ( $blocks, $to ) {
				$name = 0 === strpos( $block[2], 'core/' ) ? substr( $block[2], 5 ) : $block[2];
				// A navigation link to a category holds a term ID: not a post.
				if ( empty( $blocks[ $name ] ) || ( 0 === strpos( $name, 'navigation-' ) && false !== strpos( $block[3], '"kind":"taxonomy"' ) ) ) {
					return $block[0];
				}
				$attributes = $block[3];
				foreach ( (array) $blocks[ $name ] as $key ) {
					$attributes = (string) preg_replace_callback(
						'/("' . preg_quote( (string) $key, '/' ) . '":)(\d+|\[[\d,\s]*\])/',
						function ( $value ) use ( $to ) {
							if ( '[' !== $value[2][0] ) {
								return $value[1] . $to( $value[2] );
							}
							return $value[1] . (string) preg_replace_callback( '/\d+/', fn( $id ) => $to( $id[0] ), $value[2] );
						},
						$attributes
					);
				}

				return $block[1] . $attributes . $block[4];
			},
			$content
		);

		// The markup: wp-image-N classes, wherever they are (an img, or the figure of some blocks).
		$content = (string) preg_replace_callback(
			'/(class="[^"]*\bwp-image-)(\d+)/',
			function ( $m ) use ( $to ) {
				return $m[1] . $to( $m[2] );
			},
			$content
		);
		// The gallery images' data-id, in img tags only (other markup's data-id isn't a post).
		$content = (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $img ) use ( $to ) {
				return (string) preg_replace_callback(
					'/(\bdata-id=["\'])(\d+)/',
					function ( $m ) use ( $to ) {
						return $m[1] . $to( $m[2] );
					},
					$img[0]
				);
			},
			$content
		);

		// The gallery shortcode: its ids attribute, a comma-separated list.
		return (string) preg_replace_callback(
			'/(\[gallery\b[^\]]*\bids=["\'])([\d,\s]+)/',
			function ( $m ) use ( $to ) {
				return $m[1] . (string) preg_replace_callback( '/\d+/', fn( $id ) => $to( $id[0] ), $m[2] );
			},
			$content
		);
	}

	/**
	 * The importer's map, without the items that kept their ID.
	 *
	 * @param array $processed Old post ID => new (WP_Import::$processed_posts).
	 * @return array<int, int>
	 */
	function eizport_changed_ids( $processed ) {
		$map = array();
		foreach ( (array) $processed as $old => $new ) {
			if ( (int) $old && (int) $new && (int) $old !== (int) $new ) {
				$map[ (int) $old ] = (int) $new;
			}
		}

		return $map;
	}

	/**
	 * `import_end`: maps the block IDs in the imported items' content.
	 * Written straight to the table: no revision, no kses, no hooks — the
	 * content is the one just imported, with only its IDs changed.
	 *
	 * @return int Posts changed.
	 */
	function eizport_remap_block_ids() {
		global $wpdb;

		$importer = $GLOBALS['wp_import'] ?? null;
		$map      = is_object( $importer ) ? eizport_changed_ids( (array) $importer->processed_posts ) : array();
		if ( ! $map ) {
			return 0;
		}

		$changed = 0;
		foreach ( array_unique( array_map( 'intval', (array) $importer->processed_posts ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' === $post->post_type || '' === $post->post_content ) {
				continue;
			}
			$content = eizport_replace_block_ids( $post->post_content, $map );
			if ( $content !== $post->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per imported post; see the docblock.
				clean_post_cache( $post->ID );
				++$changed;
			}
		}

		return $changed;
	}

	add_action( 'import_end', 'eizport_remap_block_ids', 15 );
}
