<?php
/**
 * Elementor: the design data mapped for (or without) Elementor's import,
 * the global colors and fonts merged by `_id`, and pages built with
 * Elementor carried as post_content/<slug>.json — none of it needs
 * Elementor.
 *
 * Posts are made inside a transaction rolled back after each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Bundle;

/**
 * Elementor tests.
 */
class ElementorTest extends Test_Case {

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
		remove_all_filters( 'eizport_content_file' );
		unset( $GLOBALS['eizport_content_data'], $GLOBALS['eizport_post_content'] );
		$GLOBALS['eizport_post_content'] = array();
		parent::tear_down();
	}

	/**
	 * A page design: a section with a background, an image, a gallery, a
	 * template widget and a button linking to the source site.
	 *
	 * @return array
	 */
	private function design() {
		return array(
			array(
				'id'       => 's1',
				'elType'   => 'section',
				'settings' => array(
					'background_image' => array(
						'id'  => 5,
						'url' => 'https://source.example/wp-content/uploads/2026/10/logo.png',
					),
				),
				'elements' => array(
					array(
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'image-gallery',
						'settings'   => array(
							'wp_gallery' => array(
								array(
									'id'  => '5',
									'url' => 'https://source.example/wp-content/uploads/2026/10/logo.png',
								),
								array(
									'id'  => 7,
									'url' => 'https://source.example/wp-content/uploads/2026/10/kept.png',
								),
							),
						),
					),
					array(
						'id'         => 'w2',
						'elType'     => 'widget',
						'widgetType' => 'template',
						'settings'   => array( 'template_id' => '28' ),
					),
					array(
						'id'         => 'w3',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array( 'link' => array( 'url' => 'https://source.example/about-the-chef/' ) ),
					),
				),
			),
		);
	}

	public function test_the_design_is_mapped_for_elementors_import() {
		$maps   = array(
			'posts'     => array( 5 => 14 ),
			'templates' => array( 28 => 69 ),
			'urls'      => array(
				'https://source.example/wp-content/uploads/2026/10/logo.png' => 'https://here.example/wp-content/uploads/2026/10/logo.png',
				'https://source.example' => 'https://here.example',
			),
		);
		$mapped = eizport_elementor_map( $this->design(), $maps );

		$this->assertSame( '69', $mapped[0]['elements'][1]['settings']['template_id'], 'The template, a string as it came.' );
		$this->assertSame( 'https://here.example/about-the-chef/', $mapped[0]['elements'][2]['settings']['link']['url'], 'A link to the source site.' );
		$this->assertSame( 5, $mapped[0]['settings']['background_image']['id'], 'Media IDs are left to Elementor\'s import.' );
		$this->assertSame( 'https://here.example/wp-content/uploads/2026/10/logo.png', $mapped[0]['settings']['background_image']['url'] );

		// Without Elementor, the media are mapped here.
		$mapped = eizport_elementor_map( $this->design(), $maps, true );
		$this->assertSame( 14, $mapped[0]['settings']['background_image']['id'] );
		$this->assertSame( '14', $mapped[0]['elements'][0]['settings']['wp_gallery'][0]['id'] );
		$this->assertSame( 7, $mapped[0]['elements'][0]['settings']['wp_gallery'][1]['id'], 'An image that didn\'t come keeps its ID.' );
	}

	public function test_source_urls_in_the_order_they_are_replaced() {
		$importer = (object) array(
			'url_remap' => array( 'https://source.example/wp-content/uploads/a.png' => 'http://here.example/wp-content/uploads/a.png' ),
			'base_url'  => 'https://source.example',
		);
		$urls     = eizport_elementor_urls( $importer );

		$this->assertSame( 'https://source.example/wp-content/uploads/a.png', array_key_first( $urls ), 'Longest first.' );
		$this->assertSame( untrailingslashit( home_url() ), $urls['https://source.example'] );
		$this->assertArrayHasKey( 'https:\/\/source.example', $urls, 'As Elementor saves them.' );
	}

	public function test_without_elementor_the_design_goes_to_the_meta() {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is active here.' );
		}
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Landing',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue(
			eizport_elementor_apply(
				$page,
				array(
					'content'       => $this->design(),
					'page_settings' => array( 'template' => 'default' ),
					'version'       => '4.3.5',
				)
			)
		);
		$this->assertSame( $this->design(), json_decode( get_post_meta( $page, '_elementor_data', true ), true ), 'Slashes kept (wp_slash).' );
		$this->assertSame( 'builder', get_post_meta( $page, '_elementor_edit_mode', true ) );
		$this->assertSame( array( 'template' => 'default' ), get_post_meta( $page, '_elementor_page_settings', true ) );
		$this->assertFalse( eizport_elementor_apply( $page, array( 'title' => 'No content' ) ) );
	}

	public function test_global_colors_merged_by_id() {
		$merged = eizport_elementor_merge_globals(
			array(
				array(
					'_id'   => 'brand',
					'color' => '#000',
				),
				array(
					'_id'   => 'theirs',
					'color' => '#111',
				),
			),
			array(
				array(
					'_id'   => 'brand',
					'color' => '#C0392B',
				),
				array(
					'_id'   => 'new',
					'color' => '#222',
				),
			)
		);

		$this->assertSame( array( '#C0392B', '#111', '#222' ), array_column( $merged, 'color' ), 'Replaced in place, theirs kept, new added.' );
	}

	public function test_a_design_goes_as_a_json_content_file() {
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Landing',
				'post_name'    => 'eizport-landing',
				'post_status'  => 'publish',
				'post_content' => '<p>Fallback</p>',
			)
		);
		add_filter(
			'eizport_content_file',
			function ( $file, $post ) use ( $page ) {
				return $post->ID === $page ? array(
					'extension' => 'json',
					'data'      => '{"content":[]}',
				) : $file;
			},
			10,
			2
		);
		$GLOBALS['id'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as export_wp() sets it.

		$this->assertSame( '<p>Fallback</p>', Bundle\collect_content( '<p>Fallback</p>' ), 'The content stays in the WXR.' );
		$this->assertArrayHasKey( 'eizport-landing.json', $GLOBALS['eizport_post_content'] );
		rewind( $GLOBALS['eizport_post_content']['eizport-landing.json']['stream'] );
		$this->assertSame( '{"content":[]}', stream_get_contents( $GLOBALS['eizport_post_content']['eizport-landing.json']['stream'] ) );
		unset( $GLOBALS['id'] );
	}

	public function test_json_files_are_kept_for_the_end_not_merged() {
		$dir = get_temp_dir() . 'eizport-elementor-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir . '/post_content' );
		$this->remove_later( $dir );
		file_put_contents( $dir . '/post_content/landing.json', '{"content":[]}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/manifest.json', wp_json_encode( array( 'post_content' => array( 'landing.json' => array( 'id' => 30 ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$xml = "<item>\n<wp:post_id>30</wp:post_id>\n<wp:post_type><![CDATA[page]]></wp:post_type>\n<content:encoded><![CDATA[<p>Fallback</p>]]></content:encoded>\n</item>\n";

		$this->assertSame( $xml, Bundle\merge_item( $xml, $dir, array( 30 => 'landing.json' ) ), 'The content stays.' );
		Bundle\keep_designs( $dir );
		$this->assertSame( '{"content":[]}', $GLOBALS['eizport_content_data'][30] );
	}
}
