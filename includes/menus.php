<?php
/**
 * Navigation menus in an export: classic menus (nav_menu terms, their
 * nav_menu_item posts and the theme locations they sit in) and block
 * navigations (wp_navigation posts, which navigation blocks point to with
 * "ref"). Core's export_wp() prints the nav_menu terms only for "All
 * content", and neither post type is a content type the Export screen
 * offers, so a menu is chosen on its own row and added here.
 *
 * The export args carry the choice as `eizport_menus`: `classic` (nav_menu
 * term IDs) and `navigation` (wp_navigation post IDs). Each classic menu's
 * theme locations travel as its `_eizport_menu_locations` term meta, which
 * import-menus.php turns back into locations when the import ends.
 *
 * @package Eizport
 */

namespace Eizport\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * The term meta that carries a classic menu's theme locations in the WXR.
 */
const LOCATIONS_META = '_eizport_menu_locations';

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'export_wp', __NAMESPACE__ . '\terms_to_export' );
}

/**
 * The site's menus, for the Export screen's Menus row.
 *
 * @return array{classic: array, navigation: array}
 */
function all() {
	$locations = locations_by_menu();
	$classic   = array();
	foreach ( wp_get_nav_menus() as $menu ) {
		$classic[] = array(
			'id'        => (int) $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'items'     => (int) $menu->count,
			'locations' => $locations[ (int) $menu->term_id ] ?? array(),
		);
	}

	$navigation = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'wp_navigation',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	) as $post ) {
		$navigation[] = array(
			'id'    => (int) $post->ID,
			'title' => '' === $post->post_title ? __( '(no title)', 'eizport' ) : $post->post_title,
			'links' => count( links( (string) $post->post_content ) ),
		);
	}

	return array(
		'classic'    => $classic,
		'navigation' => $navigation,
	);
}

/**
 * The theme locations of each classic menu, by their labels.
 *
 * @return array<int, string[]> Menu term ID => location labels.
 */
function locations_by_menu() {
	$labels = get_registered_nav_menus();
	$by     = array();
	foreach ( get_nav_menu_locations() as $location => $menu_id ) {
		if ( $menu_id ) {
			$by[ (int) $menu_id ][] = $labels[ $location ] ?? $location;
		}
	}

	return $by;
}

/**
 * The menus to suggest with the items chosen: the classic menus that sit in
 * a theme location, and the navigations the items' content or the active
 * theme's templates point to.
 *
 * @param int[] $ids Items chosen.
 * @return array{classic: int[], navigation: int[]}
 */
function suggested( $ids ) {
	$classic = array_values( array_unique( array_map( 'intval', array_filter( get_nav_menu_locations() ) ) ) );
	$classic = array_values(
		array_filter(
			$classic,
			function ( $id ) {
				return (bool) wp_get_nav_menu_object( $id );
			}
		)
	);

	$content = array();
	foreach ( array_chunk( array_filter( array_map( 'absint', (array) $ids ) ), 50 ) as $chunk ) {
		foreach ( get_posts(
			array(
				'post__in'       => $chunk,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		) as $post ) {
			$content[] = (string) $post->post_content;
		}
	}
	$content = array_merge( $content, theme_templates() );

	$navigation = array();
	foreach ( $content as $text ) {
		$navigation = array_merge( $navigation, refs( $text ) );
	}
	// A navigation block without "ref" shows the most recently published navigation, as core picks it.
	if ( in_array( 0, $navigation, true ) ) {
		$navigation[] = fallback_navigation();
	}
	$navigation = array_values(
		array_filter(
			array_unique( $navigation ),
			function ( $id ) {
				return 'wp_navigation' === get_post_type( $id );
			}
		)
	);

	return array(
		'classic'    => $classic,
		'navigation' => $navigation,
	);
}

/**
 * The block markup of the active theme's templates and template parts:
 * the ones saved on the site, else the theme's files.
 *
 * @return string[]
 */
function theme_templates() {
	if ( ! function_exists( 'get_block_templates' ) || ! wp_is_block_theme() ) {
		return array();
	}
	$markup = array();
	foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
		foreach ( get_block_templates( array(), $type ) as $template ) {
			$markup[] = (string) $template->content;
		}
	}

	return $markup;
}

/**
 * The navigation a navigation block without "ref" shows: the most recently
 * published one (core's WP_Navigation_Fallback, without creating one).
 *
 * @return int 0 for none.
 */
function fallback_navigation() {
	$ids = get_posts(
		array(
			'post_type'      => 'wp_navigation',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);

	return $ids ? (int) $ids[0] : 0;
}

/**
 * The navigation IDs that navigation blocks point to in some markup; 0 for
 * a block without "ref" (it shows the fallback navigation).
 *
 * @param string $content Block markup.
 * @return int[]
 */
function refs( $content ) {
	if ( false === strpos( $content, 'wp:navigation ' ) ) {
		return array();
	}
	preg_match_all( '/<!--\s+wp:navigation(?:\s+(\{.*?\}))?\s+\/?-->/s', $content, $blocks );
	$ids = array();
	foreach ( $blocks[1] as $attributes ) {
		$ids[] = preg_match( '/"ref":(\d+)/', $attributes, $ref ) ? (int) $ref[1] : 0;
	}

	return $ids;
}

/**
 * The links of a navigation (its navigation-link and navigation-submenu
 * blocks), for counting.
 *
 * @param string $content wp_navigation content.
 * @return string[]
 */
function links( $content ) {
	preg_match_all( '/<!--\s+wp:(?:navigation-link|navigation-submenu|home-link|page-list)\b/', $content, $found );

	return $found[0];
}

/**
 * The posts and terms the items of classic menus point to, to suggest
 * them: the importer leaves out an item whose page, post or term isn't on
 * the other site.
 *
 * @param int[] $menu_ids nav_menu term IDs.
 * @return array<string, int[]> Post type => IDs.
 */
function targets( $menu_ids ) {
	$targets = array();
	foreach ( array_map( 'absint', (array) $menu_ids ) as $menu_id ) {
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			if ( 'post_type' === $item->type && $item->object_id ) {
				$type = get_post_type( (int) $item->object_id );
				if ( $type ) {
					$targets[ $type ][] = (int) $item->object_id;
				}
			}
		}
	}

	return array_map(
		function ( $ids ) {
			return array_values( array_unique( $ids ) );
		},
		$targets
	);
}

/**
 * The choice in the export args, cleaned: existing menus only.
 *
 * @param array $args Export args.
 * @return array{classic: int[], navigation: int[]}
 */
function chosen( $args ) {
	$menus      = (array) ( $args['eizport_menus'] ?? array() );
	$classic    = array_values(
		array_filter(
			array_unique( array_map( 'absint', (array) ( $menus['classic'] ?? array() ) ) ),
			function ( $id ) {
				return $id && (bool) wp_get_nav_menu_object( $id );
			}
		)
	);
	$navigation = array_values(
		array_filter(
			array_unique( array_map( 'absint', (array) ( $menus['navigation'] ?? array() ) ) ),
			function ( $id ) {
				return $id && 'wp_navigation' === get_post_type( $id );
			}
		)
	);

	return array(
		'classic'    => $classic,
		'navigation' => $navigation,
	);
}

/**
 * The posts the menus chosen add to export_wp()'s query: the classic
 * menus' items and the navigations. Selection\where() adds them.
 *
 * @param array $args Export args.
 * @return int[]
 */
function post_ids( $args ) {
	$chosen = chosen( $args );
	$ids    = $chosen['navigation'];
	foreach ( $chosen['classic'] as $menu_id ) {
		$ids = array_merge(
			$ids,
			array_map(
				'intval',
				get_objects_in_term( $menu_id, 'nav_menu' )
			)
		);
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * When menus are chosen and core won't print their terms (anything but
 * "All content"), prints them before the items, with their theme locations.
 *
 * @param array $args Export args.
 */
function terms_to_export( $args ) {
	$chosen = chosen( $args );
	if ( ! $chosen['classic'] ) {
		return;
	}
	$all = 'all' === ( $args['content'] ?? 'all' ) && empty( $args['eizport_types'] );

	add_action(
		'rss2_head',
		function () use ( $chosen, $all ) {
			// rss2_head also runs for feeds: only inside export_wp(), which declares wxr_cdata().
			if ( ! function_exists( 'wxr_cdata' ) ) {
				return;
			}
			echo terms_xml( $chosen['classic'], ! $all ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built with wxr_cdata() and esc_xml().
		}
	);
}

/**
 * The <wp:term> entries of classic menus, as core prints them, with the
 * locations as term meta. With "All content" core already prints the terms:
 * only the locations are added then, in a term of their own.
 *
 * @param int[] $menu_ids    nav_menu term IDs.
 * @param bool  $with_terms  Print the terms (false: core does).
 * @return string
 */
function terms_xml( $menu_ids, $with_terms = true ) {
	$locations = array();
	foreach ( get_nav_menu_locations() as $location => $menu_id ) {
		$locations[ (int) $menu_id ][] = $location;
	}

	$xml = '';
	foreach ( $menu_ids as $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			continue;
		}
		if ( ! $with_terms && empty( $locations[ (int) $menu->term_id ] ) ) {
			continue;
		}
		$xml .= "\t<wp:term>";
		$xml .= '<wp:term_id>' . (int) $menu->term_id . '</wp:term_id>';
		$xml .= '<wp:term_taxonomy>nav_menu</wp:term_taxonomy>';
		$xml .= '<wp:term_slug>' . wxr_cdata( $menu->slug ) . '</wp:term_slug>';
		$xml .= '<wp:term_parent></wp:term_parent>';
		$xml .= '<wp:term_name>' . wxr_cdata( $menu->name ) . '</wp:term_name>';
		if ( '' !== (string) $menu->description ) {
			$xml .= '<wp:term_description>' . wxr_cdata( $menu->description ) . '</wp:term_description>';
		}
		if ( ! empty( $locations[ (int) $menu->term_id ] ) ) {
			$xml .= "\n\t\t<wp:termmeta>\n\t\t\t<wp:meta_key>" . wxr_cdata( LOCATIONS_META ) . "</wp:meta_key>\n\t\t\t<wp:meta_value>" . wxr_cdata( implode( ',', $locations[ (int) $menu->term_id ] ) ) . "</wp:meta_value>\n\t\t</wp:termmeta>\n";
		}
		$xml .= "</wp:term>\n";
	}

	return $xml;
}
