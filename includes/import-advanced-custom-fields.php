<?php
/**
 * Advanced Custom Fields on import: the post types, taxonomies and field
 * groups a bundle carries in ACF's own export format (the JSON of ACF →
 * Tools, in the options file's `advanced-custom-fields` entry) are imported with ACF's own
 * import, by key, when the import starts — so the items of those types and
 * taxonomies come in with them registered —, and when it ends the fields
 * that store IDs (image, file, post object, relationship, page link,
 * taxonomy, user) get the IDs the items have here. Loaded by the plugin
 * and appended to the temporary must-use plugin of a Playground .zip
 * (playground-media.php): no namespace, no other files.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_acf_id_field_types' ) ) {
	/**
	 * ACF field types whose values are IDs, and of what.
	 *
	 * @return array<string, string> Field type => "post", "term" or "user".
	 */
	function eizport_acf_id_field_types() {
		$types = array(
			'image'        => 'post',
			'file'         => 'post',
			'gallery'      => 'post',
			'post_object'  => 'post',
			'relationship' => 'post',
			'page_link'    => 'post',
			'taxonomy'     => 'term',
			'user'         => 'user',
		);

		/**
		 * Filters the ACF field types whose values are IDs an import maps
		 * (a plugin's own field types: type => "post", "term" or "user").
		 *
		 * @param array<string, string> $types Field type => what the IDs are.
		 */
		return (array) apply_filters( 'eizport_advanced_custom_fields_id_types', $types );
	}

	/**
	 * The ACF internal post types a bundle carries, in the order they're
	 * imported: post types and taxonomies before the field groups that may
	 * point to them.
	 *
	 * @return string[]
	 */
	function eizport_acf_kinds() {
		return array( 'acf-post-type', 'acf-taxonomy', 'acf-field-group' );
	}

	/**
	 * Field key => type, from field groups in ACF's export format (their
	 * `fields`, with `sub_fields` at any depth).
	 *
	 * @param array $items Exported ACF items.
	 * @return array<string, string>
	 */
	function eizport_acf_field_types_in( $items ) {
		$types = array();
		$walk  = function ( $fields ) use ( &$walk, &$types ) {
			foreach ( (array) $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( isset( $field['key'], $field['type'] ) ) {
					$types[ (string) $field['key'] ] = (string) $field['type'];
				}
				$walk( $field['sub_fields'] ?? array() );
			}
		};
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$walk( $item['fields'] ?? array() );
			}
		}

		return $types;
	}

	/**
	 * A field's type by its key: from the bundle's field groups, else ACF's
	 * (database or local JSON), else the `acf-field` post of that key.
	 *
	 * @param string $key   Field key ("field_…").
	 * @param array  $known Field key => type, from the bundle.
	 * @return string '' when unknown.
	 */
	function eizport_acf_field_type( $key, $known = array() ) {
		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}
		if ( function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $key );
			if ( is_array( $field ) && isset( $field['type'] ) ) {
				return (string) $field['type'];
			}
		}
		$posts = get_posts(
			array(
				'post_type'      => 'acf-field',
				'name'           => $key,
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);
		$field = $posts ? eizport_acf_unserialize( $posts[0]->post_content ) : null;

		return is_array( $field ) && isset( $field['type'] ) ? (string) $field['type'] : '';
	}

	/**
	 * A stored value, unserialized when it is serialized — data only, never
	 * objects: the values come from a file someone else made.
	 *
	 * @param mixed $value Value as stored.
	 * @return mixed
	 */
	function eizport_acf_unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- no classes allowed; a malformed value stays as it is.

		return false === $data && 'b:0;' !== trim( $value ) ? $value : $data;
	}

	/**
	 * The ACF values in an item's meta: name => field key, for the names
	 * whose `_<name>` holds a field key.
	 *
	 * @param array $meta Meta key => values (get_post_meta( $id ) or get_term_meta( $id )).
	 * @return array<string, string>
	 */
	function eizport_acf_values_in( $meta ) {
		$found = array();
		foreach ( (array) $meta as $name => $values ) {
			$name = (string) $name;
			if ( '' === $name || '_' === $name[0] || ! isset( $meta[ '_' . $name ][0] ) ) {
				continue;
			}
			$key = (string) $meta[ '_' . $name ][0];
			if ( 0 === strpos( $key, 'field_' ) ) {
				$found[ $name ] = $key;
			}
		}

		return $found;
	}

	/**
	 * The IDs a value holds: one ID, or a list (as ACF saves multiple
	 * values). URLs (a page link to an archive) aren't IDs.
	 *
	 * @param mixed $value Meta value.
	 * @return int[]
	 */
	function eizport_acf_ids_of( $value ) {
		$value = eizport_acf_unserialize( $value );
		$ids   = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			if ( is_int( $one ) || ( is_string( $one ) && ctype_digit( $one ) ) ) {
				$ids[] = (int) $one;
			}
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * A value with its IDs mapped, kept in the shape it had (one ID or a
	 * list, numbers as strings when they were); null when nothing changed.
	 *
	 * @param mixed           $value Meta value.
	 * @param array<int, int> $map   Old ID => new ID (only the ones that changed).
	 * @return mixed|null
	 */
	function eizport_acf_mapped_value( $value, $map ) {
		$value   = eizport_acf_unserialize( $value );
		$changed = false;
		$one     = function ( $id ) use ( $map, &$changed ) {
			if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && isset( $map[ (int) $id ] ) ) {
				$changed = true;
				return is_int( $id ) ? (int) $map[ (int) $id ] : (string) $map[ (int) $id ];
			}
			return $id;
		};
		$value   = is_array( $value ) ? array_map( $one, $value ) : $one( $value );

		return $changed ? $value : null;
	}

	/**
	 * Maps the ACF values of one item's meta.
	 *
	 * @param string $meta_type "post" or "term".
	 * @param int    $id        Item ID.
	 * @param array  $maps      "post", "term" and "user" => old ID => new ID.
	 * @param array  $known     Field key => type, from the bundle.
	 * @return int How many values changed.
	 */
	function eizport_acf_remap_item( $meta_type, $id, $maps, $known ) {
		$kinds   = eizport_acf_id_field_types();
		$meta    = 'term' === $meta_type ? get_term_meta( $id ) : get_post_meta( $id );
		$changed = 0;
		foreach ( eizport_acf_values_in( $meta ) as $name => $key ) {
			$kind = $kinds[ eizport_acf_field_type( $key, $known ) ] ?? '';
			if ( '' === $kind || empty( $maps[ $kind ] ) ) {
				continue;
			}
			$value = eizport_acf_mapped_value( $meta[ $name ][0], $maps[ $kind ] );
			if ( null !== $value ) {
				update_metadata( $meta_type, $id, wp_slash( $name ), wp_slash( $value ) );
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * A field group's location rules with the post IDs they hold (rules on
	 * `post`, `page` and `page_parent`) mapped; null when nothing changed.
	 *
	 * @param array           $location ACF location: groups of rules.
	 * @param array<int, int> $map      Old post ID => new.
	 * @return array|null
	 */
	function eizport_acf_mapped_location( $location, $map ) {
		$changed = false;
		foreach ( (array) $location as $g => $rules ) {
			foreach ( (array) $rules as $r => $rule ) {
				$value = (string) ( $rule['value'] ?? '' );
				if ( in_array( $rule['param'] ?? '', array( 'post', 'page', 'page_parent' ), true ) && ctype_digit( $value ) && isset( $map[ (int) $value ] ) ) {
					$location[ $g ][ $r ]['value'] = (string) $map[ (int) $value ];
					$changed                       = true;
				}
			}
		}

		return $changed ? $location : null;
	}

	/**
	 * Imports the ACF items of a bundle (ACF's export format) with ACF's
	 * own import: an item with the same key here is updated, not
	 * duplicated. Post types and taxonomies are registered at once, for the
	 * import that follows. Without ACF (6.1 or later) nothing is written.
	 *
	 * @param array $items Exported ACF items.
	 * @return string[] Keys imported.
	 */
	function eizport_acf_import_items( $items ) {
		if ( ! function_exists( 'acf_import_internal_post_type' ) || ! function_exists( 'acf_determine_internal_post_type' ) ) {
			return array();
		}
		// As ACF → Tools → Import: only who may manage ACF (its capability setting, manage_options by default).
		$capability = function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'capability' ) : 'manage_options';
		if ( ! current_user_can( '' !== $capability ? $capability : 'manage_options' ) ) {
			return array();
		}
		$by_kind = array_fill_keys( eizport_acf_kinds(), array() );
		foreach ( (array) $items as $item ) {
			$kind = is_array( $item ) && isset( $item['key'] ) ? acf_determine_internal_post_type( (string) $item['key'] ) : false;
			if ( $kind && isset( $by_kind[ $kind ] ) ) {
				$by_kind[ $kind ][] = $item;
			}
		}

		$done = array();
		foreach ( $by_kind as $kind => $list ) {
			foreach ( $list as $item ) {
				// As ACF → Tools → Import does: the item with that key here is updated.
				$post = acf_get_internal_post_type_post( $item['key'], $kind );
				// PHP callbacks by name, which ACF itself drops on import since 6.3.10: never from a file.
				unset( $item['ID'], $item['register_meta_box_cb'], $item['meta_box_cb'], $item['meta_box_sanitize_cb'] );
				if ( $post ) {
					$item['ID'] = $post->ID;
				}
				$item   = acf_import_internal_post_type( $item, $kind );
				$done[] = (string) $item['key'];
			}
		}

		// ACF registers its post types and taxonomies on acf/init: these ones are needed now.
		foreach ( array(
			'acf-post-type' => array( 'ACF_Post_Type', 'register_post_types' ),
			'acf-taxonomy'  => array( 'ACF_Taxonomy', 'register_taxonomies' ),
		) as $kind => $call ) {
			if ( $by_kind[ $kind ] && function_exists( 'acf_get_instance' ) && class_exists( $call[0] ) ) {
				$instance = acf_get_instance( $call[0] );
				if ( is_object( $instance ) && method_exists( $instance, $call[1] ) ) {
					$instance->{ $call[1] }();
				}
			}
		}

		return $done;
	}

	/**
	 * When an import starts: imports the bundle's ACF items, and keeps
	 * their field types for the end.
	 *
	 * @param array|null $data The options file's data.
	 */
	function eizport_acf_start( $data ) {
		$items = is_array( $data ) && isset( $data['advanced-custom-fields'] ) && is_array( $data['advanced-custom-fields'] ) ? $data['advanced-custom-fields'] : array();
		if ( ! $items ) {
			return;
		}
		$GLOBALS['eizport_acf_items']    = $items;
		$GLOBALS['eizport_acf_imported'] = eizport_acf_import_items( $items );
	}

	/**
	 * `import_end`: maps the IDs in the imported items' ACF values (posts
	 * and terms), and in the location rules of the field groups the bundle
	 * brought.
	 */
	function eizport_acf_remap_ids() {
		$importer = $GLOBALS['wp_import'] ?? null;
		if ( ! is_object( $importer ) ) {
			return;
		}
		$changed = function ( $processed ) {
			$map = array();
			foreach ( (array) $processed as $old => $new ) {
				if ( (int) $old && (int) $new && (int) $old !== (int) $new ) {
					$map[ (int) $old ] = (int) $new;
				}
			}
			return $map;
		};
		$maps    = array(
			'post' => $changed( $importer->processed_posts ?? array() ),
			'term' => $changed( $importer->processed_terms ?? array() ),
			'user' => $changed( $importer->processed_authors ?? array() ),
		);
		$items   = (array) ( $GLOBALS['eizport_acf_items'] ?? array() );
		$known   = eizport_acf_field_types_in( $items );

		foreach ( array_unique( array_map( 'intval', (array) ( $importer->processed_posts ?? array() ) ) ) as $id ) {
			if ( $id ) {
				eizport_acf_remap_item( 'post', $id, $maps, $known );
			}
		}
		foreach ( array_unique( array_map( 'intval', (array) ( $importer->processed_terms ?? array() ) ) ) as $id ) {
			if ( $id ) {
				eizport_acf_remap_item( 'term', $id, $maps, $known );
			}
		}

		if ( $maps['post'] && function_exists( 'acf_get_field_group' ) && function_exists( 'acf_update_field_group' ) ) {
			foreach ( $items as $item ) {
				$key   = (string) ( $item['key'] ?? '' );
				$group = 0 === strpos( $key, 'group_' ) ? acf_get_field_group( $key ) : null;
				if ( ! is_array( $group ) || empty( $group['ID'] ) ) {
					continue;
				}
				$location = eizport_acf_mapped_location( $group['location'] ?? array(), $maps['post'] );
				if ( null !== $location ) {
					$group['location'] = $location;
					acf_update_field_group( $group );
				}
			}
		}
		unset( $GLOBALS['eizport_acf_items'] );
	}
	add_action( 'import_end', 'eizport_acf_remap_ids', 16 );

	/**
	 * `wp_import_existing_post`: ACF's own posts (in a WXR of all content)
	 * exist here when one has the same key (`post_name`), not the same
	 * title and date — two fields called "Image" in two groups, saved in
	 * the same second, are two fields.
	 *
	 * @param int   $existing The importer's answer (a post ID, or 0).
	 * @param array $post     The post being imported.
	 * @return int
	 */
	function eizport_acf_existing_post( $existing, $post ) {
		$type = (string) ( $post['post_type'] ?? '' );
		if ( 'acf-field' !== $type && ! in_array( $type, eizport_acf_kinds(), true ) ) {
			return $existing;
		}
		$found = get_posts(
			array(
				'post_type'      => $type,
				'name'           => (string) ( $post['post_name'] ?? '' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return '' !== (string) ( $post['post_name'] ?? '' ) && $found ? (int) $found[0] : 0;
	}
	add_filter( 'wp_import_existing_post', 'eizport_acf_existing_post', 10, 2 );
}
