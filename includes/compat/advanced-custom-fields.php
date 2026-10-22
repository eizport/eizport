<?php
/**
 * Advanced Custom Fields compatibility (ACF 6.1 or later, free), through
 * the plugin's hooks only: the "Advanced Custom Fields" row of the Export screen, whose items
 * are ACF's post types, taxonomies and field groups, carried in ACF's own
 * export format (the JSON of ACF → Tools) in the bundle's options file;
 * their own post types (acf-field-group, acf-post-type…) off the content
 * rows; the images and files of ACF fields as media used; the posts that
 * post object, relationship and page link fields point to, suggested. On
 * import, includes/import-advanced-custom-fields.php imports them and maps the IDs.
 *
 * Hooked to `eizport_register_compat`, so another copy (ACF's own)
 * replaces it with
 *
 *     remove_action( 'eizport_register_compat', 'Eizport\Compat\AdvancedCustomFields\register' );
 *
 * @package Eizport
 */

namespace Eizport\Compat\AdvancedCustomFields;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the compatibility, when ACF (6.1 or later) is active.
 */
function register() {
	if ( ! active() ) {
		return;
	}
	add_filter( 'eizport_content_types', __NAMESPACE__ . '\content_types' );
	add_filter( 'eizport_look_items', __NAMESPACE__ . '\look_items', 10, 2 );
	add_filter( 'eizport_look_data', __NAMESPACE__ . '\look_data', 10, 3 );
	add_filter( 'eizport_look_suggested', __NAMESPACE__ . '\look_suggested', 10, 3 );
	add_filter( 'eizport_media_used', __NAMESPACE__ . '\media_used', 10, 2 );
	add_filter( 'eizport_suggested_items', __NAMESPACE__ . '\suggested_items', 10, 2 );
}

/**
 * Whether ACF's import and export of its own items is there.
 *
 * @return bool
 */
function active() {
	return function_exists( 'acf_get_internal_post_type_posts' ) && function_exists( 'acf_prepare_internal_post_type_for_export' );
}

/**
 * ACF's own post types: they travel in the Advanced Custom Fields row, not as content.
 *
 * @param array<string, string> $types Post type => value.
 * @return array<string, string>
 */
function content_types( $types ) {
	return array_diff_key( $types, array_flip( array( 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ) ) );
}

/**
 * ACF's items on this site: post types, taxonomies and field groups (in
 * the database or local JSON), each with its kind.
 *
 * @return array<int, array> ACF items, with `_kind`.
 */
function all() {
	$all = array();
	foreach ( eizport_acf_kinds() as $kind ) {
		foreach ( (array) acf_get_internal_post_type_posts( $kind ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['key'] ) ) {
				$item['_kind'] = $kind;
				$all[]         = $item;
			}
		}
	}

	return $all;
}

/**
 * The Advanced Custom Fields row's items, for the Export screen: key, label, kind and a
 * preview (a field group's fields, a post type's or taxonomy's name).
 *
 * @param array  $items Items so far.
 * @param string $row   The look row.
 * @return array
 */
function look_items( $items, $row ) {
	if ( 'advanced-custom-fields' !== $row ) {
		return $items;
	}
	$kinds = array(
		'acf-post-type'   => __( 'Post type', 'eizport' ),
		'acf-taxonomy'    => __( 'Taxonomy', 'eizport' ),
		'acf-field-group' => __( 'Field group', 'eizport' ),
	);
	foreach ( all() as $item ) {
		if ( 'acf-field-group' === $item['_kind'] ) {
			$fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $item ) : array();
			$value  = implode(
				', ',
				array_map(
					function ( $field ) {
						return sprintf( '%s (%s)', $field['label'] ?? $field['name'] ?? '', $field['type'] ?? '' );
					},
					$fields
				)
			);
		} else {
			$value = (string) ( $item['post_type'] ?? $item['taxonomy'] ?? '' );
		}
		$items[] = array(
			'key'     => (string) $item['key'],
			'label'   => (string) ( $item['title'] ?? $item['key'] ),
			'details' => $kinds[ $item['_kind'] ],
			'preview' => array(
				'type'  => 'text',
				'value' => $value,
			),
		);
	}

	return $items;
}

/**
 * The ACF items chosen, in ACF's export format (as ACF → Tools → Export
 * writes them: a field group with its `fields`).
 *
 * @param string[] $keys Item keys.
 * @return array
 */
function export_items( $keys ) {
	$out = array();
	foreach ( (array) $keys as $key ) {
		$kind = acf_determine_internal_post_type( (string) $key );
		$item = $kind ? acf_get_internal_post_type( (string) $key, $kind ) : null;
		if ( ! is_array( $item ) ) {
			continue;
		}
		if ( 'acf-field-group' === $kind ) {
			$item['fields'] = acf_get_fields( $item );
		}
		$out[] = acf_prepare_internal_post_type_for_export( $item, $kind );
	}

	return $out;
}

/**
 * The options file's `advanced-custom-fields` entry, for the ACF items chosen.
 *
 * @param array    $data The look's data so far.
 * @param string   $row  The look row.
 * @param string[] $keys Item keys chosen.
 * @return array
 */
function look_data( $data, $row, $keys ) {
	if ( 'advanced-custom-fields' === $row && $keys ) {
		$data['advanced-custom-fields'] = export_items( $keys );
	}

	return $data;
}

/**
 * The fields of a post's ACF values: name => field (ACF's array).
 *
 * @param int $post_id Post ID.
 * @return array<string, array>
 */
function fields_of( $post_id ) {
	$fields = array();
	foreach ( eizport_acf_values_in( get_post_meta( $post_id ) ) as $name => $key ) {
		$field = acf_get_field( $key );
		if ( is_array( $field ) ) {
			$fields[ $name ] = $field;
		}
	}

	return $fields;
}

/**
 * The IDs a post's ACF values of some field types point to.
 *
 * @param int      $post_id Post ID.
 * @param string[] $types   Field types.
 * @return int[]
 */
function ids_in( $post_id, $types ) {
	$ids = array();
	foreach ( fields_of( $post_id ) as $name => $field ) {
		if ( in_array( $field['type'] ?? '', $types, true ) ) {
			$ids = array_merge( $ids, eizport_acf_ids_of( get_post_meta( $post_id, $name, true ) ) );
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * The images and files of a post's ACF fields, as media it uses.
 *
 * @param int[]    $used Attachment IDs.
 * @param \WP_Post $post The post.
 * @return int[]
 */
function media_used( $used, $post ) {
	return array_merge( (array) $used, ids_in( $post->ID, array( 'image', 'file', 'gallery' ) ) );
}

/**
 * The posts a post's post object, relationship and page link fields point
 * to, suggested.
 *
 * @param int[]    $suggested Post IDs.
 * @param \WP_Post $post      The item.
 * @return int[]
 */
function suggested_items( $suggested, $post ) {
	return array_merge( (array) $suggested, ids_in( $post->ID, array( 'post_object', 'relationship', 'page_link' ) ) );
}

/**
 * The ACF items the posts chosen use, suggested in the Advanced Custom Fields row: the field
 * groups of their values, the ACF post types of their types and the ACF
 * taxonomies of their terms.
 *
 * @param string[]             $keys   Keys suggested so far.
 * @param string               $row    The look row.
 * @param array<string, int[]> $chosen Post type => IDs chosen.
 * @return string[]
 */
function look_suggested( $keys, $row, $chosen ) {
	if ( 'advanced-custom-fields' !== $row || ! $chosen ) {
		return $keys;
	}
	$groups     = array();
	$taxonomies = array();
	foreach ( $chosen as $ids ) {
		foreach ( array_slice( (array) $ids, 0, 500 ) as $id ) {
			foreach ( fields_of( (int) $id ) as $field ) {
				$group = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $field['parent'] ?? 0 ) : null;
				if ( is_array( $group ) && ! empty( $group['key'] ) ) {
					$groups[] = $group['key'];
				}
			}
			$taxonomies = array_merge( $taxonomies, get_object_taxonomies( get_post_type( (int) $id ) ) );
		}
	}
	foreach ( all() as $item ) {
		if ( ( 'acf-post-type' === $item['_kind'] && isset( $chosen[ $item['post_type'] ?? '' ] ) )
			|| ( 'acf-taxonomy' === $item['_kind'] && in_array( $item['taxonomy'] ?? '', $taxonomies, true ) ) ) {
			$keys[] = (string) $item['key'];
		}
	}

	return array_values( array_unique( array_merge( (array) $keys, $groups ) ) );
}
