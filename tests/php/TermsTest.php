<?php
/**
 * The terms of an export of chosen types go in full: description, parent,
 * term meta.
 *
 * Posts and terms are made inside a transaction rolled back after each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Terms;

/**
 * Terms tests.
 */
class TermsTest extends Test_Case {

	/**
	 * Opens the transaction, as an administrator.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator' ) )[0]->ID );
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		parent::tear_down();
	}

	/**
	 * A post in a child category (with a description and term meta) and a tag.
	 *
	 * @return array{post: int, parent: int, child: int}
	 */
	private function post_in_child_category() {
		$parent = (int) wp_insert_term( 'Eizport Coffees', 'category', array( 'slug' => 'eizport-coffees' ) )['term_id'];
		$child  = (int) wp_insert_term(
			'Eizport Single origin',
			'category',
			array(
				'slug'        => 'eizport-single-origin',
				'parent'      => $parent,
				'description' => 'Beans from one farm.',
			)
		)['term_id'];
		add_term_meta( $child, 'eizport_test_color', 'amber' );
		$post = (int) wp_insert_post(
			array(
				'post_title'    => 'Eizport terms test',
				'post_status'   => 'publish',
				'post_category' => array( $child ),
				'tags_input'    => array( 'eizport-roast' ),
			)
		);

		return array(
			'post'   => $post,
			'parent' => $parent,
			'child'  => $child,
		);
	}

	public function test_used_terms_come_with_their_ancestors_parents_first() {
		$made  = $this->post_in_child_category();
		$terms = Terms\used( array( $made['post'] ) );

		$this->assertSame( array( $made['parent'], $made['child'] ), array_keys( $terms['category'] ) );
		$this->assertSame( array( 'eizport-roast' ), wp_list_pluck( array_values( $terms['post_tag'] ), 'slug' ) );
		$this->assertNotContains( 'nav_menu', Terms\taxonomies() );
		$this->assertNotContains( 'post_format', Terms\taxonomies() );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_by_type_carries_the_terms_in_full_and_imports_them() {
		$made = $this->post_in_child_category();
		$copy = wp_get_ability( 'eizport/export' )->execute(
			array(
				'types'  => array( 'post' => array( 'ids' => array( $made['post'] ) ) ),
				'bundle' => false,
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$wxr = (string) file_get_contents( \Eizport\Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( '<wp:category_nicename><![CDATA[eizport-single-origin]]></wp:category_nicename>', $wxr );
		$this->assertStringContainsString( '<wp:category_parent><![CDATA[eizport-coffees]]></wp:category_parent>', $wxr );
		$this->assertStringContainsString( 'Beans from one farm.', $wxr );
		$this->assertStringContainsString( 'eizport_test_color', $wxr );
		$this->assertStringContainsString( '<wp:tag_slug><![CDATA[eizport-roast]]></wp:tag_slug>', $wxr );
		$this->assertLessThan( strpos( $wxr, 'eizport-single-origin]]></wp:category_nicename>' ), strpos( $wxr, '<wp:category_nicename><![CDATA[eizport-coffees]]>' ), 'The parent first.' );

		// The other site: none of it.
		wp_delete_post( $made['post'], true );
		wp_delete_term( $made['child'], 'category' );
		wp_delete_term( $made['parent'], 'category' );
		ob_start();
		$result = \Eizport\Import\import_file( \Eizport\Copies\path( $copy['name'] ) );
		ob_end_clean();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$child  = get_term_by( 'slug', 'eizport-single-origin', 'category' );
		$parent = get_term_by( 'slug', 'eizport-coffees', 'category' );
		$this->assertNotFalse( $child );
		$this->assertSame( 'Beans from one farm.', $child->description );
		$this->assertSame( $parent->term_id, $child->parent );
		$this->assertSame( 'amber', get_term_meta( $child->term_id, 'eizport_test_color', true ) );
	}
}
