<?php
/**
 * What the items chosen suggest exporting too, for the Export screen's
 * rows: the settings that point to them (homepage, shop page…), the posts
 * their content uses (products a block shows, through
 * `eizport_suggested_items`) and the reviews of the products chosen.
 * Nothing is added to an export by itself: the screen shows "N suggested"
 * and adds them when the row is checked.
 *
 * @package Eizport
 */

namespace Eizport\Suggestions;

use Eizport\Options;
use Eizport\Selection;

defined( 'ABSPATH' ) || exit;

/**
 * At most this many posts of a type chosen whole are read for suggestions.
 */
const LIMIT = 500;

/**
 * The IDs of the posts chosen.
 *
 * @param array $specs Post type => `ids`, `filters` or nothing (all), as
 *                     Selection\specs_from_input() returns them.
 * @return array<string, int[]> Post type => IDs.
 */
function chosen_ids( $specs ) {
	$chosen = array();
	foreach ( $specs as $type => $spec ) {
		if ( isset( $spec['ids'] ) ) {
			$chosen[ $type ] = array_map( 'intval', $spec['ids'] );
		} else {
			$chosen[ $type ] = array_slice( Selection\filtered_ids( $type, (array) ( $spec['filters'] ?? array() ) ), 0, LIMIT );
		}
	}

	return $chosen;
}

/**
 * The suggestions for what is chosen.
 *
 * @param array $specs Post type => spec (see chosen_ids()).
 * @return array{items: array<string, int[]>, reviews: int[], options: string[], chosen: array<string, int[]>}
 */
function suggest( $specs ) {
	$chosen = chosen_ids( $specs );
	$ids    = array() === $chosen ? array() : array_merge( ...array_values( $chosen ) );

	// Settings pointing to the items, as Settings → Export → "Linked settings" says.
	$mode    = \Eizport\Settings\get()['linked_options'];
	$options = in_array( $mode, array( 'check', 'both' ), true ) ? Options\linked_names( $ids ) : array();

	// Posts the items' content uses.
	$items = array();
	foreach ( array_chunk( $ids, 50 ) as $chunk ) {
		foreach ( get_posts(
			array(
				'post__in'       => $chunk,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		) as $post ) {
			/**
			 * Filters the posts an item suggests exporting too, like the
			 * products a block in its content shows.
			 *
			 * @param int[]    $suggested Post IDs.
			 * @param \WP_Post $post      The item.
			 */
			foreach ( (array) apply_filters( 'eizport_suggested_items', array(), $post ) as $id ) {
				$type = get_post_type( (int) $id );
				// Chosen ones too: a row that brings its suggestions keeps them.
				if ( $type && isset( Selection\content_types()[ $type ] ) ) {
					$items[ $type ][] = (int) $id;
				}
			}
		}
	}
	$items = array_map(
		function ( $ids ) {
			return array_values( array_unique( $ids ) );
		},
		$items
	);

	return array(
		'items'   => $items,
		'reviews' => reviews( $chosen ),
		'options' => $options,
		'chosen'  => $chosen,
	);
}

/**
 * The approved reviews of the products chosen.
 *
 * @param array<string, int[]> $chosen Post type => IDs.
 * @return int[] Comment IDs.
 */
function reviews( $chosen ) {
	$products = $chosen['product'] ?? array();
	if ( array() === $products ) {
		return array();
	}

	return array_map(
		'intval',
		get_comments(
			array(
				'post__in' => $products,
				'type'     => 'review',
				'status'   => 'approve',
				'fields'   => 'ids',
			)
		)
	);
}
