<?php
/**
 * The terms of an export of chosen types, in full. Core's export_wp() prints
 * the <wp:category>, <wp:tag> and <wp:term> entries only for "All content"
 * (or a category filter); otherwise each item names its terms (slug and
 * name) and the other site gets them without description, parent or term
 * meta — a product category's thumbnail and display type, say. Here the
 * terms the exported items use, and their ancestors, are printed as core
 * prints them, parents first, so the importer builds them in full.
 *
 * @package Eizport
 */

namespace Eizport\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks.
 */
function bootstrap() {
	add_action( 'export_wp', __NAMESPACE__ . '\to_export' );
}

/**
 * When the export picks its items (chosen types), prints their terms before
 * the items.
 *
 * @param array $args Export args.
 */
function to_export( $args ) {
	if ( ! isset( $args['eizport_types'] ) || ! is_array( $args['eizport_types'] ) ) {
		return;
	}

	add_action(
		'rss2_head',
		function () use ( $args ) {
			// rss2_head also runs for feeds: only inside export_wp(), which declares wxr_cdata().
			if ( ! function_exists( 'wxr_cdata' ) ) {
				return;
			}
			echo xml( used( exported_ids( $args ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML printed by core's wxr_*() helpers.
		}
	);
}

/**
 * The posts the export holds: what its query picks (Selection\where()).
 *
 * @param array $args Export args.
 * @return int[]
 */
function exported_ids( $args ) {
	global $wpdb;

	$where = \Eizport\Selection\where( $args );
	if ( '' === $where ) {
		return array();
	}

	return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the WHERE export_wp() runs, built and escaped by Selection\where().
}

/**
 * The taxonomies whose terms go in full: categories, tags and the ones
 * plugins and themes register, as core's "All content" — not the menus
 * (their own row) nor core's internal ones (post formats, template areas).
 *
 * @return string[]
 */
function taxonomies() {
	return array_values( array_merge( array( 'category', 'post_tag' ), get_taxonomies( array( '_builtin' => false ) ) ) );
}

/**
 * The terms the posts use, with their ancestors, each taxonomy in order
 * with no child before its parent.
 *
 * @param int[] $ids Post IDs.
 * @return array<string, \WP_Term[]> Taxonomy => terms by ID.
 */
function used( $ids ) {
	$found = array();
	foreach ( array_chunk( array_filter( array_map( 'absint', $ids ) ), 200 ) as $chunk ) {
		$terms = wp_get_object_terms( $chunk, taxonomies(), array( 'fields' => 'all' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$found[ $term->taxonomy ][ $term->term_id ] = $term;
			foreach ( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) as $ancestor ) {
				$parent = get_term( $ancestor, $term->taxonomy );
				if ( $parent instanceof \WP_Term ) {
					$found[ $term->taxonomy ][ $parent->term_id ] = $parent;
				}
			}
		}
	}

	$ordered = array();
	foreach ( $found as $taxonomy => $terms ) {
		$ordered[ $taxonomy ] = array();
		$guard                = count( $terms ) * count( $terms ) + 1;
		while ( $terms && $guard-- > 0 ) {
			$term = array_shift( $terms );
			if ( ! $term->parent || isset( $ordered[ $taxonomy ][ $term->parent ] ) || ! isset( $found[ $taxonomy ][ $term->parent ] ) ) {
				$ordered[ $taxonomy ][ $term->term_id ] = $term;
			} else {
				$terms[] = $term;
			}
		}
	}

	return $ordered;
}

/**
 * The entries, as core's export_wp() prints them.
 *
 * @param array<string, \WP_Term[]> $terms Taxonomy => terms by ID.
 * @return string
 */
function xml( $terms ) {
	ob_start();
	foreach ( $terms as $taxonomy => $list ) {
		foreach ( $list as $term ) {
			$parent = $term->parent && isset( $list[ $term->parent ] ) ? $list[ $term->parent ]->slug : '';
			if ( 'category' === $taxonomy ) {
				echo "\t<wp:category>\n\t\t<wp:term_id>" . (int) $term->term_id . "</wp:term_id>\n\t\t<wp:category_nicename>" . wxr_cdata( $term->slug ) . "</wp:category_nicename>\n\t\t<wp:category_parent>" . wxr_cdata( $parent ) . "</wp:category_parent>\n\t\t"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CDATA, as core.
				wxr_cat_name( $term );
				wxr_category_description( $term );
				wxr_term_meta( $term );
				echo "\n\t</wp:category>\n";
			} elseif ( 'post_tag' === $taxonomy ) {
				echo "\t<wp:tag>\n\t\t<wp:term_id>" . (int) $term->term_id . "</wp:term_id>\n\t\t<wp:tag_slug>" . wxr_cdata( $term->slug ) . "</wp:tag_slug>\n\t\t"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CDATA, as core.
				wxr_tag_name( $term );
				wxr_tag_description( $term );
				wxr_term_meta( $term );
				echo "\n\t</wp:tag>\n";
			} else {
				echo "\t<wp:term>\n\t\t<wp:term_id>" . (int) $term->term_id . "</wp:term_id>\n\t\t<wp:term_taxonomy>" . wxr_cdata( $term->taxonomy ) . "</wp:term_taxonomy>\n\t\t<wp:term_slug>" . wxr_cdata( $term->slug ) . "</wp:term_slug>\n\t\t<wp:term_parent>" . wxr_cdata( $parent ) . "</wp:term_parent>\n\t\t"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CDATA, as core.
				wxr_term_name( $term );
				wxr_term_description( $term );
				wxr_term_meta( $term );
				echo "\n\t</wp:term>\n";
			}
		}
	}

	return (string) ob_get_clean();
}
