<?php
/**
 * The site's look: what the export puts in the options file (theme mods,
 * custom CSS, global styles and CSS, widgets) and what the import sets.
 *
 * Options, posts and styles are changed inside a transaction rolled back
 * after each test: nothing stays in the site's database.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Appearance;

/**
 * Appearance tests.
 */
class AppearanceTest extends Test_Case {

	/**
	 * Opens the transaction, as an administrator.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator' ) )[0]->ID );
		require_once dirname( __DIR__, 2 ) . '/includes/apply-options.php';
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		\WP_Theme_JSON_Resolver::clean_cached_data();
		parent::tear_down();
	}

	/**
	 * An image attachment (no file needed).
	 *
	 * @param string $name File name in uploads.
	 * @return int
	 */
	private function image( $name ) {
		return (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $name,
				'post_status'    => 'inherit',
			),
			'2026/10/' . $name
		);
	}

	public function test_theme_mods_keep_only_the_look() {
		$name = 'theme_mods_' . get_stylesheet();
		update_option(
			$name,
			array(
				'custom_logo'        => 12,
				'background_color'   => 'ffeedd',
				'custom_css_post_id' => 99,
				'nav_menu_locations' => array( 'primary' => 3 ),
				'sidebars_widgets'   => array( 'data' => array() ),
			)
		);

		$this->assertSame(
			array(
				'custom_logo'      => 12,
				'background_color' => 'ffeedd',
			),
			Appearance\theme_mods()
		);
		$this->assertSame( Appearance\theme_mods(), Appearance\data( array( 'theme_mods' ) )['options'][ $name ] );
	}

	public function test_media_the_look_uses_come_along() {
		$logo   = $this->image( 'logo.png' );
		$header = $this->image( 'header.png' );
		$back   = $this->image( 'back.png' );
		$base   = wp_get_upload_dir()['baseurl'];
		update_option(
			'theme_mods_' . get_stylesheet(),
			array(
				'custom_logo'       => $logo,
				'header_image'      => $base . '/2026/10/header.png',
				'header_image_data' => (object) array( 'attachment_id' => $header ),
				'background_image'  => $base . '/2026/10/back-300x200.png',
			)
		);

		$ids = Appearance\media_ids( array( 'theme_mods' ) );
		sort( $ids );
		$expected = array( $logo, $header, $back );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	public function test_theme_mods_are_mapped_and_merged() {
		$name = 'theme_mods_eizport-test';
		update_option( $name, array( 'nav_menu_locations' => array( 'primary' => 7 ) ) );
		$apply = eizport_options_to_apply(
			array(
				'options' => array(
					$name => array(
						'header_image'      => 'https://old.example/wp-content/uploads/h.png',
						'header_image_data' => (object) array(
							'attachment_id' => 21,
							'url'           => 'https://old.example/wp-content/uploads/h.png',
						),
						'background_image'  => 'https://old.example/wp-content/uploads/b.png',
					),
				),
			),
			array( 21 => 121 ),
			array(),
			array( 'https://old.example/wp-content/uploads/' => 'https://new.example/wp-content/uploads/' )
		);
		$mods  = $apply[ $name ];

		$this->assertSame( array( 'primary' => 7 ), $mods['nav_menu_locations'], 'What the file doesn\'t hold stays.' );
		$this->assertSame( 'https://new.example/wp-content/uploads/h.png', $mods['header_image'] );
		$this->assertSame( 'https://new.example/wp-content/uploads/b.png', $mods['background_image'] );
		$this->assertSame( 121, $mods['header_image_data']->attachment_id );
		$this->assertSame( 'https://new.example/wp-content/uploads/h.png', $mods['header_image_data']->url );
	}

	public function test_widgets_are_mapped() {
		$apply = eizport_options_to_apply(
			array(
				'options' => array(
					'sidebars_widgets'   => array( 'sidebar-1' => array( 'nav_menu-2', 'media_image-3', 'block-4' ) ),
					'widget_nav_menu'    => array(
						2              => array( 'nav_menu' => 40 ),
						'_multiwidget' => 1,
					),
					'widget_media_image' => array(
						3 => array(
							'attachment_id' => 21,
							'url'           => 'https://old.example/a.png',
						),
					),
					'widget_block'       => array( 4 => array( 'content' => '<!-- wp:image {"id":21} --><img src="https://old.example/a.png"/><!-- /wp:image -->' ) ),
				),
			),
			array( 21 => 121 ),
			array( 40 => 140 ),
			array( 'https://old.example/a.png' => 'https://new.example/a.png' )
		);

		$this->assertSame( array( 'sidebar-1' => array( 'nav_menu-2', 'media_image-3', 'block-4' ) ), $apply['sidebars_widgets'] );
		$this->assertSame( 140, $apply['widget_nav_menu'][2]['nav_menu'] );
		$this->assertSame( 1, $apply['widget_nav_menu']['_multiwidget'] );
		$this->assertSame( 121, $apply['widget_media_image'][3]['attachment_id'] );
		$this->assertSame( 'https://new.example/a.png', $apply['widget_media_image'][3]['url'] );
		$this->assertStringContainsString( 'https://new.example/a.png', $apply['widget_block'][4]['content'] );
	}

	public function test_custom_css_is_set_on_the_active_theme() {
		$set = eizport_apply_look(
			array(
				'custom_css' => array(
					'stylesheet' => 'another-theme',
					'css'        => 'body { background: url(https://old.example/bg.png); }',
				),
			),
			array( 'https://old.example/' => 'https://new.example/' )
		);

		$this->assertSame( array( 'custom_css' ), $set );
		$this->assertSame( 'body { background: url(https://new.example/bg.png); }', wp_get_custom_css() );
	}

	public function test_global_styles_and_css_each_change_their_part() {
		if ( ! wp_is_block_theme() ) {
			$this->markTestSkipped( 'The active theme is not a block theme.' );
		}
		eizport_apply_look( array( 'global_css' => array( 'css' => 'p { color: red; }' ) ) );
		eizport_apply_look(
			array(
				'global_styles' => array(
					'content' => array(
						'version' => 3,
						'styles'  => array( 'color' => array( 'background' => '#123456' ) ),
					),
				),
			)
		);
		$styles = Appearance\global_styles();
		$this->assertSame( '#123456', $styles['styles']['color']['background'] );
		$this->assertSame( 'p { color: red; }', $styles['styles']['css'], 'The styles left the CSS as it was.' );

		eizport_apply_look( array( 'global_css' => array( 'css' => 'p { color: blue; }' ) ) );
		$styles = Appearance\global_styles();
		$this->assertSame( '#123456', $styles['styles']['color']['background'], 'The CSS left the styles as they were.' );
		$this->assertSame( 'p { color: blue; }', $styles['styles']['css'] );

		$data = Appearance\data( array( 'global_styles', 'global_css' ) );
		$this->assertArrayNotHasKey( 'css', $data['global_styles']['content']['styles'] );
		$this->assertSame( 'p { color: blue; }', $data['global_css']['css'] );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_bundle_carries_the_look() {
		wp_update_custom_css_post( '.eizport-test { color: red; }' );
		update_option( 'theme_mods_' . get_stylesheet(), array( 'background_color' => 'abcdef' ) );
		$copy = wp_get_ability( 'eizport/export' )->execute(
			array(
				'types'      => array(
					'page' => array(
						'ids' => array(
							(int) get_posts(
								array(
									'post_type'   => 'page',
									'numberposts' => 1,
									'fields'      => 'ids',
								)
							)[0],
						),
					),
				),
				'appearance' => array( 'theme_mods', 'custom_css' ),
				'media'      => false,
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );

		$wxr = $this->temp_file();
		$dir = \Eizport\Import\prepare_wxr( \Eizport\Copies\path( $copy['name'] ), $wxr );
		$this->assertIsString( $dir );
		$data = \Eizport\Bundle\read_options( $dir );
		\Eizport\Bundle\delete_folder( $dir );

		$this->assertSame( array( 'background_color' => 'abcdef' ), $data['options'][ 'theme_mods_' . get_stylesheet() ] );
		$this->assertSame( '.eizport-test { color: red; }', $data['custom_css']['css'] );
	}
}
