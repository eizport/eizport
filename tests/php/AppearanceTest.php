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

	public function test_css_is_split_into_rules() {
		$css   = "/* Title */\nh1 { content: \"}\"; }\n@import url(x.css);\n@media (min-width: 600px) { .a { color: red; } .b { color: blue; } }\n@supports (display: grid) { .c { display: grid; } }\n.last{margin:0}";
		$rules = eizport_css_rules( $css );

		$this->assertSame( "/* Title */\nh1 { content: \"}\"; }", $rules[0], 'The comment before goes with the rule; a brace in a string is text.' );
		$this->assertSame( '@import url(x.css);', $rules[1] );
		$this->assertStringStartsWith( '@media (min-width: 600px) {', $rules[2] );
		$this->assertStringEndsWith( '.b { color: blue; } }', $rules[2], 'An @media is one rule.' );
		$this->assertStringStartsWith( '@supports', $rules[3] );
		$this->assertSame( '.last{margin:0}', $rules[4] );
		$this->assertCount( 5, $rules );
	}

	public function test_css_merges_by_mode_and_markers() {
		$site = '.site { color: black; }';

		$end = eizport_merge_css( $site, '.new { color: red; }', 'end', true, 'delivery' );
		$this->assertSame( ".site { color: black; }\n\n/* head delivery */\n.new { color: red; }\n/* tail delivery */", $end );
		$this->assertStringStartsWith( '/* head delivery */', eizport_merge_css( $site, '.new {}', 'start', true, 'delivery' ) );
		$this->assertStringNotContainsString( '.site', eizport_merge_css( $site, '.new {}', 'replace', true, 'delivery' ) );

		// The same file again: its block is replaced in place, whatever the mode.
		$again = eizport_merge_css( $end . "\n\n.after {}", '.new { color: blue; }', 'start', true, 'delivery' );
		$this->assertSame( ".site { color: black; }\n\n/* head delivery */\n.new { color: blue; }\n/* tail delivery */\n\n.after {}", $again );

		// Without markers, rules the site has aren't repeated.
		$plain = eizport_merge_css( $site, ".site  {  color: black; }\n.new {}", 'end', false, 'delivery' );
		$this->assertSame( ".site { color: black; }\n\n.new {}", $plain );
		$this->assertSame( $site, eizport_merge_css( $site, '.site { color: black; }', 'end', false, '' ) );
	}

	public function test_whoever_imports_can_change_the_css_choice() {
		$entry = array(
			'mode'    => 'end',
			'markers' => true,
		);
		$this->assertSame( array( 'end', true ), eizport_css_choice( $entry ) );
		$GLOBALS['eizport_look_import'] = array(
			'css_mode'    => 'replace',
			'css_markers' => false,
		);
		$this->assertSame( array( 'replace', false ), eizport_css_choice( $entry ) );
		unset( $GLOBALS['eizport_look_import'] );
		$this->assertSame( array( 'replace', false ), eizport_css_choice( array() ), 'A bundle from before modes replaces, as it did.' );
	}

	public function test_only_the_rules_chosen_go() {
		wp_update_custom_css_post( ".a { color: red; }\n.b { color: green; }\n.c { color: blue; }" );
		$items = Appearance\items( 'custom_css' );
		$this->assertCount( 3, $items );
		$this->assertSame( 'Rule 3 of 3 (the last)', $items[2]['details'] );
		$this->assertSame( '.c', $items[2]['label'] );

		$data = Appearance\data(
			array( 'custom_css' => array( $items[2]['key'] ) ),
			array(
				'mode'     => 'start',
				'markers'  => false,
				'filename' => 'delivery',
			)
		);
		$this->assertSame( '.c { color: blue; }', $data['custom_css']['css'] );
		$this->assertSame( 'start', $data['custom_css']['mode'] );
		$this->assertFalse( $data['custom_css']['markers'] );
		$this->assertSame( 'delivery', $data['custom_css']['id'], 'The file name, nothing else.' );
		$this->assertSame( Appearance\css_id( 'delivery' ), $data['custom_css']['id'], 'The same name, the same ID.' );
		$this->assertSame( "{$items[0]['key']}", Appearance\rule_key( '.a  { color: red; }' ), 'Keys ignore whitespace.' );

		// A list of rows: each of them whole, as before.
		$this->assertSame( array( 'custom_css' => 'all' ), Appearance\normalize( array( 'custom_css' ) ) );
	}

	public function test_only_the_theme_mods_chosen_go() {
		$name = 'theme_mods_' . get_stylesheet();
		update_option(
			$name,
			array(
				'custom_logo'      => 12,
				'background_color' => 'ffeedd',
				'header_textcolor' => '333333',
			)
		);
		$items = Appearance\items( 'theme_mods' );
		$this->assertSame( array( 'Logo', 'Background color', 'Header text color' ), wp_list_pluck( $items, 'label' ) );
		$this->assertSame( 'color', $items[1]['preview']['type'] );

		$data = Appearance\data( array( 'theme_mods' => array( 'background_color' ) ) );
		$this->assertSame( array( 'background_color' => 'ffeedd' ), $data['options'][ $name ] );
	}

	public function test_widgets_chosen_join_the_sites_own() {
		global $wp_registered_sidebars;
		$saved                               = $wp_registered_sidebars;
		$wp_registered_sidebars['sidebar-1'] = array( 'name' => 'Footer' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		update_option(
			'widget_text',
			array(
				3              => array( 'text' => 'This site' ),
				'_multiwidget' => 1,
			)
		);
		update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-3' ) ) );

		$entry = array(
			'id'        => 'delivery',
			'sidebars'  => array(
				'sidebar-1' => array( 'text-3' ),
				'sidebar-9' => array( 'text-4' ),
			),
			'instances' => array(
				'text-3' => array( 'text' => 'From the file' ),
				'text-4' => array( 'text' => 'No such sidebar here' ),
			),
		);
		$added = eizport_apply_widgets( $entry, array(), array(), array() );

		$this->assertSame( array( 'text-4', 'text-5' ), $added, 'Free numbers: the site has text-3.' );
		$this->assertSame( array( 'text-3', 'text-4' ), get_option( 'sidebars_widgets' )['sidebar-1'], 'At the end of the same sidebar.' );
		$this->assertSame( array( 'text-5' ), get_option( 'sidebars_widgets' )['wp_inactive_widgets'] );
		$this->assertSame( 'This site', get_option( 'widget_text' )[3]['text'] );

		// The same file again, updating existing items: in place, nothing added.
		add_filter( 'eizport_look_update_existing', '__return_true' );
		$entry['instances']['text-3']['text'] = 'Changed';
		$this->assertSame( array( 'text-4', 'text-5' ), eizport_apply_widgets( $entry, array(), array(), array() ) );
		remove_filter( 'eizport_look_update_existing', '__return_true' );
		$this->assertSame( 'Changed', get_option( 'widget_text' )[4]['text'] );
		$this->assertCount( 2, get_option( 'sidebars_widgets' )['sidebar-1'] );

		$wp_registered_sidebars = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function test_style_groups_replace_only_their_paths() {
		$current = array(
			'settings' => array( 'color' => array( 'palette' => array( 'theme' => array( 'old' ) ) ) ),
			'styles'   => array(
				'color'      => array( 'background' => '#000000' ),
				'typography' => array( 'fontSize' => '20px' ),
			),
		);
		foreach ( array( 'settings.color.palette', 'styles.color' ) as $path ) {
			$current = eizport_path_set( $current, $path, eizport_path_get( array( 'styles' => array( 'color' => array( 'text' => '#ffffff' ) ) ), $path ) );
		}
		$this->assertArrayNotHasKey( 'settings', $current, 'A group the file has nothing in is emptied here too.' );
		$this->assertSame( array( 'text' => '#ffffff' ), $current['styles']['color'] );
		$this->assertSame( '20px', $current['styles']['typography']['fontSize'], 'Other groups stay.' );
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
