<?php
/**
 * Templates and template parts (block themes) on import: a template the
 * site already has for the same theme is updated, not duplicated. The
 * WordPress Importer looks for an item by its title and date, so a header
 * the site has edited is inserted again, and core renames its slug
 * ("header-2"): the site would keep showing its own header. Here an item
 * of those types maps to the post with its slug and theme (the `wp_theme`
 * term it brings), whatever the "Update existing" setting, and takes the
 * item's content when the import ends — before the navigation refs, block
 * IDs and links in it are mapped (import-menus.php, import-blocks.php,
 * import-links.php). Loaded by the plugin and appended to the temporary
 * must-use plugin of a Playground .zip: no namespace, no other files.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_templates_existing' ) ) {
	/**
	 * The post types this applies to.
	 *
	 * @return string[]
	 */
	function eizport_template_types() {
		return array( 'wp_template', 'wp_template_part' );
	}

	/**
	 * The site's post of a template or template part: its slug, for a theme,
	 * in any state but the trash; the newest when there are more (the one
	 * core shows).
	 *
	 * @param string $type  wp_template or wp_template_part.
	 * @param string $slug  Its slug (post_name).
	 * @param string $theme The theme's stylesheet (its wp_theme term).
	 * @return int 0 for none.
	 */
	function eizport_template_post( $type, $slug, $theme ) {
		if ( '' === $slug || '' === $theme ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => $type,
				// Not "name": a query by name is singular, and WP_Query drops its tax_query.
				'post_name__in'  => array( $slug ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one post, by the theme it belongs to.
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The theme an imported item belongs to: its wp_theme term.
	 *
	 * @param array $post The importer's item.
	 * @return string '' when it has none.
	 */
	function eizport_template_theme( $post ) {
		foreach ( (array) ( $post['terms'] ?? array() ) as $term ) {
			if ( 'wp_theme' === ( $term['domain'] ?? '' ) ) {
				return (string) ( $term['slug'] ?? $term['name'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * `wp_import_existing_post`: a template or template part maps to the
	 * site's post of the same slug and theme, or is inserted (0) when the
	 * site has none, even if another post has its title and date. The
	 * item's content waits for the import's end (eizport_templates_import_end()).
	 *
	 * @param int   $post_exists The post found so far.
	 * @param array $post        The importer's item.
	 * @return int
	 */
	function eizport_templates_existing( $post_exists, $post ) {
		$post = (array) $post;
		$type = (string) ( $post['post_type'] ?? '' );
		if ( ! in_array( $type, eizport_template_types(), true ) ) {
			return $post_exists;
		}
		$id = eizport_template_post( $type, (string) ( $post['post_name'] ?? '' ), eizport_template_theme( $post ) );
		if ( $id ) {
			$GLOBALS['eizport_templates_pending'][ $id ] = array(
				'post_content' => (string) ( $post['post_content'] ?? '' ),
				'post_title'   => (string) ( $post['post_title'] ?? '' ),
				'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
				'post_status'  => (string) ( $post['status'] ?? 'publish' ),
			);
		}

		return $id;
	}

	/**
	 * `import_end`, before the IDs and links are mapped: the site's templates
	 * the file had take its content. Written straight to the table, as the
	 * importer writes block markup (no kses: a WP-CLI import has no user
	 * allowed to post it), then the cache cleared.
	 *
	 * @return int Posts updated.
	 */
	function eizport_templates_import_end() {
		global $wpdb;

		$pending                              = (array) ( $GLOBALS['eizport_templates_pending'] ?? array() );
		$GLOBALS['eizport_templates_pending'] = array();
		foreach ( $pending as $id => $fields ) {
			$wpdb->update( $wpdb->posts, $fields, array( 'ID' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per template the file had; see the docblock.
			clean_post_cache( (int) $id );
		}

		return count( $pending );
	}

	add_filter( 'wp_import_existing_post', 'eizport_templates_existing', 30, 2 );
	add_action( 'import_end', 'eizport_templates_import_end', 5 );
}
