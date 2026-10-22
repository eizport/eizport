<?php
/**
 * Tests for what an imported file (someone else's) may write: HTML for a
 * user without unfiltered_html, the source address, custom CSS, serialized
 * data.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

/**
 * Import security tests.
 */
class ImportSecurityTest extends Test_Case {

	/**
	 * Logged out after each test.
	 */
	protected function tear_down() {
		wp_set_current_user( 0 );
		$GLOBALS['eizport_templates_pending'] = array();
		parent::tear_down();
	}

	public function test_a_template_from_the_file_is_filtered_for_a_user_without_unfiltered_html() {
		$id = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'eizport-security-header',
				'post_title'   => 'Header',
				'post_content' => '<!-- wp:site-title /-->',
				'post_status'  => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $id );
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		$item = array(
			'post_type'    => 'wp_template_part',
			'post_name'    => 'eizport-security-header',
			'post_title'   => 'Header<script>alert(1)</script>',
			'post_content' => '<!-- wp:html --><script>alert(1)</script><p>Kept</p><!-- /wp:html -->',
			'status'       => 'evil',
			'terms'        => array(
				array(
					'domain' => 'wp_theme',
					'slug'   => get_stylesheet(),
				),
			),
		);

		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Logged out, as a site admin on a network would be for this.' );
		$this->assertSame( $id, eizport_templates_existing( 0, $item ) );
		eizport_templates_import_end();
		$post = get_post( $id );
		$this->assertStringNotContainsString( '<script', $post->post_content );
		$this->assertStringContainsString( '<p>Kept</p>', $post->post_content, 'The rest of the markup, block comments too.' );
		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertStringNotContainsString( '<script', $post->post_title );
		$this->assertSame( 'publish', $post->post_status, 'An unknown status publishes it.' );
		wp_delete_post( $id, true );
	}

	public function test_a_source_address_that_isnt_plain_replaces_nothing() {
		$content = '<img src="//a" alt=" onerror=alert(1) ">';

		$this->assertSame( $content, eizport_replace_source_links( $content, 'http://a"', 'http://example.org' ), 'Its quote would have closed the src attribute.' );
		$this->assertSame( $content, eizport_replace_source_links( $content, "http://a'(", 'http://example.org' ) );
		$this->assertSame( '<a href="http://example.org/about/">', eizport_replace_source_links( '<a href="http://old.test:8080/about/">', 'http://old.test:8080', 'http://example.org' ), 'A plain address still is.' );
	}

	public function test_widgets_from_the_file_are_filtered_for_a_user_without_unfiltered_html() {
		$widget = array(
			'title'   => 'Hours<script>alert(1)</script>',
			'content' => '<script>alert(1)</script><p>Open</p>',
		);

		$filtered = eizport_map_widget( 'custom_html', $widget, array(), array(), array() );
		$this->assertStringNotContainsString( '<script', $filtered['content'] );
		$this->assertStringContainsString( '<p>Open</p>', $filtered['content'] );
		$this->assertStringNotContainsString( '<script', $filtered['title'] );

		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$this->assertSame( $widget['content'], eizport_map_widget( 'custom_html', $widget, array(), array(), array() )['content'], 'Who may post it gets it as it came.' );
		}
	}

	public function test_custom_css_from_the_file_needs_edit_css() {
		wp_set_current_user( 0 );
		$before = (string) wp_get_custom_css();
		$set    = eizport_apply_look(
			array(
				'custom_css' => array(
					'css'  => 'body { background: url(//evil.test/x) }',
					'mode' => 'end',
				),
			)
		);

		$after = (string) wp_get_custom_css();
		if ( $after !== $before ) {
			wp_update_custom_css_post( $before );
		}
		$this->assertNotContains( 'custom_css', $set );
		$this->assertSame( $before, $after );
	}

	public function test_serialized_acf_values_never_become_objects() {
		$this->assertSame( array( 12, 13 ), eizport_acf_unserialize( serialize( array( 12, 13 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( '12', eizport_acf_unserialize( '12' ) );
		$this->assertFalse( eizport_acf_unserialize( 'b:0;' ) );
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, eizport_acf_unserialize( 'O:8:"stdClass":1:{s:1:"a";i:1;}' ), 'No class is ever made.' );
		$this->assertSame( 'a:1:{broken', eizport_acf_unserialize( 'a:1:{broken' ), 'Malformed: as it was.' );
	}
}
