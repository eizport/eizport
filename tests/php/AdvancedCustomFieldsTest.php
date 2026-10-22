<?php
/**
 * Advanced Custom Fields: the IDs in ACF values and field group locations
 * mapped to the imported items when an import ends, with the field types
 * the bundle's items (ACF's export format) give — ACF itself isn't needed.
 *
 * Posts are made inside a transaction rolled back after each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

/**
 * Advanced Custom Fields tests.
 */
class AdvancedCustomFieldsTest extends Test_Case {

	/**
	 * Opens the transaction.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		unset( $GLOBALS['wp_import'], $GLOBALS['eizport_acf_items'] );
		parent::tear_down();
	}

	/**
	 * A field group in ACF's export format, with a group field's sub field.
	 *
	 * @return array
	 */
	private function group() {
		return array(
			'key'      => 'group_eiztest',
			'title'    => 'Test',
			'fields'   => array(
				array(
					'key'  => 'field_photo',
					'name' => 'photo',
					'type' => 'image',
				),
				array(
					'key'  => 'field_related',
					'name' => 'related',
					'type' => 'relationship',
				),
				array(
					'key'  => 'field_more',
					'name' => 'more',
					'type' => 'page_link',
				),
				array(
					'key'  => 'field_cuisine',
					'name' => 'cuisine',
					'type' => 'taxonomy',
				),
				array(
					'key'  => 'field_cook',
					'name' => 'cook',
					'type' => 'user',
				),
				array(
					'key'  => 'field_note',
					'name' => 'note',
					'type' => 'text',
				),
				array(
					'key'        => 'field_box',
					'name'       => 'box',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'  => 'field_box_file',
							'name' => 'file',
							'type' => 'file',
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'page',
						'operator' => '==',
						'value'    => '999101',
					),
				),
			),
		);
	}

	public function test_field_types_from_the_bundle_at_any_depth() {
		$types = eizport_acf_field_types_in( array( $this->group(), array( 'key' => 'post_type_x' ) ) );

		$this->assertSame( 'image', $types['field_photo'] );
		$this->assertSame( 'file', $types['field_box_file'], 'A group field\'s sub field.' );
		$this->assertSame( 'image', eizport_acf_field_type( 'field_photo', $types ) );
		$this->assertSame( '', eizport_acf_field_type( 'field_nowhere', $types ) );
	}

	public function test_values_found_by_their_field_key() {
		$meta = array(
			'photo'      => array( '5' ),
			'_photo'     => array( 'field_photo' ),
			'plain'      => array( 'x' ),
			'_edit_lock' => array( '1:1' ),
			'other'      => array( '3' ),
			'_other'     => array( 'not a key' ),
		);

		$this->assertSame( array( 'photo' => 'field_photo' ), eizport_acf_values_in( $meta ) );
	}

	public function test_a_value_keeps_its_shape() {
		$map = array(
			5  => 50,
			13 => 130,
		);

		$this->assertSame( '50', eizport_acf_mapped_value( '5', $map ), 'A string stays a string.' );
		$this->assertSame( 50, eizport_acf_mapped_value( 5, $map ) );
		$this->assertSame( array( '130', '7' ), eizport_acf_mapped_value( serialize( array( '13', '7' ) ), $map ), 'A list (serialized, as ACF saves it): only the IDs that changed.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as meta comes.
		$this->assertNull( eizport_acf_mapped_value( '7', $map ), 'Nothing changed.' );
		$this->assertNull( eizport_acf_mapped_value( 'https://example.com/shop/', $map ), 'A URL isn\'t an ID.' );
		$this->assertSame( array( 13 ), eizport_acf_ids_of( 'a:2:{i:0;s:2:"13";i:1;s:3:"url";}' ) );
	}

	public function test_location_rules_on_pages_and_posts() {
		$location = array(
			array(
				array(
					'param'    => 'page',
					'operator' => '==',
					'value'    => '13',
				),
			),
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'recipe',
				),
			),
		);
		$mapped   = eizport_acf_mapped_location( $location, array( 13 => 130 ) );

		$this->assertSame( '130', $mapped[0][0]['value'] );
		$this->assertSame( 'recipe', $mapped[1][0]['value'], 'Not an ID.' );
		$this->assertNull( eizport_acf_mapped_location( $location, array( 7 => 70 ) ) );
	}

	public function test_imported_values_are_mapped_when_the_import_ends() {
		$image  = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Imported photo',
				'post_status'    => 'inherit',
			),
			'2026/10/photo.png'
		);
		$file   = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Imported card',
				'post_status'    => 'inherit',
			),
			'2026/10/card.pdf'
		);
		$page   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Imported page',
				'post_status' => 'publish',
			)
		);
		$recipe = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Imported recipe',
				'post_status' => 'publish',
			)
		);
		$term   = wp_insert_term( 'Eizport test cuisine', 'category' );
		$term   = (int) $term['term_id'];
		$values = array(
			'photo'    => array( '999001', 'field_photo' ),
			'related'  => array( array( '999101', '999102' ), 'field_related' ),
			'more'     => array( 'https://source.example/shop/', 'field_more' ),
			'cuisine'  => array( '999201', 'field_cuisine' ),
			'cook'     => array( '999301', 'field_cook' ),
			'note'     => array( '999001', 'field_note' ),
			'box_file' => array( '999002', 'field_box_file' ),
		);
		foreach ( $values as $name => $value ) {
			update_post_meta( $recipe, $name, $value[0] );
			update_post_meta( $recipe, '_' . $name, $value[1] );
		}
		update_term_meta( $term, 'photo', '999001' );
		update_term_meta( $term, '_photo', 'field_photo' );

		$GLOBALS['eizport_acf_items'] = array( $this->group() );
		$GLOBALS['wp_import']         = (object) array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's, unset in tear_down().
			'processed_posts'   => array(
				999001 => $image,
				999002 => $file,
				999101 => $page,
				999103 => $recipe,
			),
			'processed_terms'   => array( 999201 => $term ),
			'processed_authors' => array( 999301 => 1 ),
		);
		eizport_acf_remap_ids();

		$this->assertSame( (string) $image, get_post_meta( $recipe, 'photo', true ) );
		$this->assertSame( array( (string) $page, '999102' ), get_post_meta( $recipe, 'related', true ), 'An item that didn\'t come keeps its ID.' );
		$this->assertSame( 'https://source.example/shop/', get_post_meta( $recipe, 'more', true ) );
		$this->assertSame( (string) $term, get_post_meta( $recipe, 'cuisine', true ), 'A taxonomy field, by the terms map.' );
		$this->assertSame( '1', get_post_meta( $recipe, 'cook', true ), 'A user field, by the authors map.' );
		$this->assertSame( '999001', get_post_meta( $recipe, 'note', true ), 'A text field is left alone.' );
		$this->assertSame( (string) $file, get_post_meta( $recipe, 'box_file', true ), 'A sub field.' );
		$this->assertSame( (string) $image, get_term_meta( $term, 'photo', true ), 'Term meta too.' );
	}

	public function test_acf_posts_exist_by_their_key() {
		$field = (int) wp_insert_post(
			array(
				'post_type'   => 'acf-field',
				'post_title'  => 'Image',
				'post_name'   => 'field_eiz_one',
				'post_status' => 'publish',
			)
		);
		$same  = array(
			'post_type'  => 'acf-field',
			'post_title' => 'Image',
			'post_name'  => 'field_eiz_one',
		);

		$this->assertSame( $field, eizport_acf_existing_post( 0, $same ) );
		$this->assertSame( 0, eizport_acf_existing_post( $field, array_merge( $same, array( 'post_name' => 'field_eiz_two' ) ) ), 'Same title and date, another key: another field.' );
		$this->assertSame( 77, eizport_acf_existing_post( 77, array( 'post_type' => 'page' ) ), 'Other types: the importer\'s answer.' );
	}

	public function test_without_acf_nothing_is_imported() {
		if ( function_exists( 'acf_import_internal_post_type' ) ) {
			$this->markTestSkipped( 'ACF is active here.' );
		}

		$this->assertSame( array(), eizport_acf_import_items( array( $this->group() ) ) );
		$this->assertSame( array(), \Eizport\Appearance\items( 'advanced-custom-fields' ), 'No row without ACF.' );
	}
}
