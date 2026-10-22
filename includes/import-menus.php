<?php
/**
 * Navigation menus on import, for the WordPress Importer: loaded by the
 * plugin, and appended to the temporary must-use plugin a Playground
 * blueprint writes (playground-media.php), so it can't use the plugin's
 * namespace or other files.
 *
 * - Theme locations: the WXR's nav_menu terms carry them as the
 *   `_eizport_menu_locations` term meta (Menus\terms_xml()); they're read
 *   before the terms are imported (and never stored), and set on the active
 *   theme when the import ends, for the menus the site has then.
 * - A classic menu the site already has (same slug): its items would be
 *   added a second time. With "Update existing" (the
 *   `eizport_menus_update_existing` filter) its items are deleted first, so
 *   the menu ends up as in the file; without it, the file's items for that
 *   menu are left out.
 * - Navigation blocks point to a wp_navigation by ID ("ref"): when the
 *   import ends, the imported items' refs, and those of the site's
 *   templates that point to a navigation the site doesn't have, are
 *   changed to the navigations' new IDs.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_menus_read_terms' ) ) {
	/**
	 * The import's state: nav_menu slug => location names, the menus the
	 * site had before, and the menus whose items the file replaces.
	 *
	 * @return array{locations: array<string, string[]>, existing: string[]}
	 */
	function &eizport_menus_state() {
		static $state = array(
			'locations' => array(),
			'existing'  => array(),
		);

		return $state;
	}

	/**
	 * `wp_import_terms`: reads (and drops) the locations meta of nav_menu
	 * terms, notes which menus the site already has, and drops a term
	 * printed twice (with "All content", core and Eizport both print it).
	 *
	 * @param array $terms Terms from the WXR.
	 * @return array
	 */
	function eizport_menus_read_terms( $terms ) {
		$state = &eizport_menus_state();
		$seen  = array();
		$kept  = array();
		foreach ( (array) $terms as $term ) {
			if ( 'nav_menu' !== ( $term['term_taxonomy'] ?? '' ) ) {
				$kept[] = $term;
				continue;
			}
			$slug = (string) ( $term['slug'] ?? '' );
			$meta = array();
			foreach ( (array) ( $term['termmeta'] ?? array() ) as $row ) {
				if ( '_eizport_menu_locations' === ( $row['key'] ?? '' ) ) {
					$state['locations'][ $slug ] = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $row['value'] ) ) ) );
				} else {
					$meta[] = $row;
				}
			}
			$term['termmeta'] = $meta;
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			if ( '' !== $slug && term_exists( $slug, 'nav_menu' ) ) {
				$state['existing'][] = $slug;
			}
			$kept[] = $term;
		}

		return $kept;
	}

	/**
	 * `wp_import_posts`: for the menus the site already had, empties them
	 * (update existing) or leaves the file's items out.
	 *
	 * @param array $posts Items from the WXR.
	 * @return array
	 */
	function eizport_menus_existing_items( $posts ) {
		$state = &eizport_menus_state();
		if ( ! $state['existing'] ) {
			return $posts;
		}

		/**
		 * Whether an import replaces the items of the classic menus the site
		 * already has (true) or leaves them as they are (false).
		 *
		 * @param bool $update Default false.
		 */
		$update = (bool) apply_filters( 'eizport_menus_update_existing', false );
		if ( $update ) {
			foreach ( $state['existing'] as $slug ) {
				$menu = wp_get_nav_menu_object( $slug );
				foreach ( $menu ? (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) : array() as $item ) {
					wp_delete_post( (int) $item->ID, true );
				}
			}

			return $posts;
		}

		return array_values(
			array_filter(
				(array) $posts,
				function ( $post ) use ( $state ) {
					if ( 'nav_menu_item' !== ( $post['post_type'] ?? '' ) ) {
						return true;
					}
					foreach ( (array) ( $post['terms'] ?? array() ) as $term ) {
						if ( 'nav_menu' === ( $term['domain'] ?? '' ) && in_array( (string) $term['slug'], $state['existing'], true ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * `import_end`: sets the locations of the menus imported (on the active
	 * theme, only its own locations) and remaps the navigation blocks.
	 */
	function eizport_menus_import_end() {
		$state    = &eizport_menus_state();
		$importer = $GLOBALS['wp_import'] ?? null;

		$registered = get_registered_nav_menus();
		$set        = get_nav_menu_locations();
		foreach ( $state['locations'] as $slug => $locations ) {
			$menu = wp_get_nav_menu_object( $slug );
			if ( ! $menu ) {
				continue;
			}
			foreach ( $locations as $location ) {
				// A block theme registers no location: there's nothing to set.
				if ( isset( $registered[ $location ] ) ) {
					$set[ $location ] = (int) $menu->term_id;
				}
			}
		}
		if ( $state['locations'] ) {
			set_theme_mod( 'nav_menu_locations', $set );
		}

		if ( is_object( $importer ) ) {
			eizport_menus_remap_refs( (array) $importer->processed_posts );
		}

		$state['locations'] = array();
		$state['existing']  = array();
	}

	/**
	 * Changes the "ref" of navigation blocks from the file's navigation IDs
	 * to the imported ones: in the imported items, and in the site's
	 * templates and template parts when the old ID isn't a navigation here.
	 *
	 * @param array<int, int> $processed Old post ID => new, from the importer.
	 * @return int Posts changed.
	 */
	function eizport_menus_remap_refs( $processed ) {
		global $wpdb;

		$map = array();
		foreach ( $processed as $old => $new ) {
			if ( (int) $old !== (int) $new && 'wp_navigation' === get_post_type( (int) $new ) ) {
				$map[ (int) $old ] = (int) $new;
			}
		}
		if ( ! $map ) {
			return 0;
		}

		$posts = array_values( array_unique( array_map( 'intval', $processed ) ) );
		// The site's templates: only refs to IDs that aren't a navigation here, so a site's own navigation is never taken.
		$templates = get_posts(
			array(
				'post_type'      => array( 'wp_template', 'wp_template_part' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$foreign   = array_filter(
			$map,
			function ( $old ) {
				return 'wp_navigation' !== get_post_type( $old );
			},
			ARRAY_FILTER_USE_KEY
		);

		$changed = 0;
		foreach ( array( array( $posts, $map ), array( array_diff( array_map( 'intval', $templates ), $posts ), $foreign ) ) as list( $ids, $refs ) ) {
			if ( ! $refs ) {
				continue;
			}
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || false === strpos( $post->post_content, 'wp:navigation ' ) ) {
					continue;
				}
				$content = eizport_menus_replace_refs( $post->post_content, $refs );
				if ( $content !== $post->post_content ) {
					// Only a number changes: straight to the table, as the block IDs are, so kses (for a user
					// without unfiltered HTML) never strips the rest of a template someone else made.
					$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per post a navigation ref changed in.
					clean_post_cache( $post->ID );
					++$changed;
				}
			}
		}

		return $changed;
	}

	/**
	 * The markup with the navigation blocks' refs replaced, in one pass.
	 *
	 * @param string          $content Block markup.
	 * @param array<int, int> $refs    Old ID => new.
	 * @return string
	 */
	function eizport_menus_replace_refs( $content, $refs ) {
		return (string) preg_replace_callback(
			'/(<!--\s+wp:navigation\s+)(\{.*?\})(\s+\/?-->)/s',
			function ( $block ) use ( $refs ) {
				$attributes = preg_replace_callback(
					'/"ref":(\d+)/',
					function ( $ref ) use ( $refs ) {
						return '"ref":' . ( $refs[ (int) $ref[1] ] ?? (int) $ref[1] );
					},
					$block[2]
				);

				return $block[1] . $attributes . $block[3];
			},
			$content
		);
	}

	add_filter( 'wp_import_terms', 'eizport_menus_read_terms' );
	add_filter( 'wp_import_posts', 'eizport_menus_existing_items' );
	add_action( 'import_end', 'eizport_menus_import_end' );
}
