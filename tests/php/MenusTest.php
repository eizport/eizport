<?php
/**
 * Navigation menus: what the export adds, and what the import does with
 * them (locations, menus already there, navigation blocks' refs).
 *
 * Menus, posts and theme mods are made inside a transaction rolled back
 * after each test: nothing stays in the site's database.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Menus;

/**
 * Menus tests.
 */
class MenusTest extends Test_Case {

	/**
	 * Opens the transaction, as an administrator, with a classic location.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator' ) )[0]->ID );
		register_nav_menus( array( 'eizport-test' => 'Eizport test' ) );
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		unregister_nav_menu( 'eizport-test' );
		remove_all_filters( 'eizport_menus_update_existing' );
		$state              = &eizport_menus_state();
		$state['locations'] = array();
		$state['existing']  = array();
		parent::tear_down();
	}

	/**
	 * A classic menu with a page and a custom link, in the test location.
	 *
	 * @param string $name Menu name.
	 * @return array{menu: int, page: int, items: int[]}
	 */
	private function classic_menu( $name = 'Eizport Test Header' ) {
		$menu    = (int) wp_create_nav_menu( $name );
		$page    = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Eizport menu page',
				'post_status' => 'publish',
			)
		);
		$items   = array();
		$items[] = (int) wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-object-id' => $page,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		$items[] = (int) wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Docs',
				'menu-item-url'    => 'https://eizport.github.io/',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		set_theme_mod( 'nav_menu_locations', array_merge( (array) get_theme_mod( 'nav_menu_locations', array() ), array( 'eizport-test' => $menu ) ) );

		return array(
			'menu'  => $menu,
			'page'  => $page,
			'items' => $items,
		);
	}

	/**
	 * A navigation post.
	 *
	 * @return int
	 */
	private function navigation() {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Eizport test navigation',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation-link {"label":"Docs","url":"https://eizport.github.io/"} /-->',
			)
		);
	}

	public function test_all_lists_classic_menus_with_locations_and_navigations() {
		$classic = $this->classic_menu();
		$nav     = $this->navigation();
		$all     = Menus\all();

		$menu = wp_list_filter( $all['classic'], array( 'id' => $classic['menu'] ) );
		$this->assertCount( 1, $menu );
		$menu = array_values( $menu )[0];
		$this->assertSame( 2, $menu['items'] );
		$this->assertSame( array( 'Eizport test' ), $menu['locations'] );

		$navigation = array_values( wp_list_filter( $all['navigation'], array( 'id' => $nav ) ) );
		$this->assertSame( 1, $navigation[0]['links'] );
	}

	public function test_suggests_located_menus_and_navigations_blocks_point_to() {
		$classic = $this->classic_menu();
		$nav     = $this->navigation();
		$page    = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'With a navigation block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation {"ref":' . $nav . ',"overlayMenu":"never"} /-->',
			)
		);

		$suggested = Menus\suggested( array( $page ) );
		$this->assertContains( $classic['menu'], $suggested['classic'] );
		$this->assertContains( $nav, $suggested['navigation'] );
		$this->assertSame( array( 'page' => array( $classic['page'] ) ), Menus\targets( array( $classic['menu'] ) ) );
	}

	public function test_post_ids_are_the_menus_items_and_the_navigations() {
		$classic = $this->classic_menu();
		$nav     = $this->navigation();
		$ids     = Menus\post_ids(
			array(
				'eizport_menus' => array(
					'classic'    => array( $classic['menu'], 999999 ),
					'navigation' => array( $nav, $classic['page'] ),
				),
			)
		);
		sort( $ids );
		$expected = array_merge( $classic['items'], array( $nav ) );
		sort( $expected );

		$this->assertSame( $expected, $ids, 'Only existing menus; a page is not a navigation.' );
	}

	public function test_only_menus_still_limit_the_export() {
		$classic = $this->classic_menu();
		$args    = \Eizport\Selection\apply_specs(
			array(
				'content'       => 'all',
				'eizport_menus' => array( 'classic' => array( $classic['menu'] ) ),
			),
			array()
		);

		$this->assertSame( 'nav_menu_item', $args['content'] );
		$this->assertStringContainsString( (string) $classic['items'][0], \Eizport\Selection\where( $args ) );
	}

	public function test_import_reads_and_drops_the_locations_meta() {
		$terms = eizport_menus_read_terms(
			array(
				array(
					'term_id'       => 7,
					'term_taxonomy' => 'nav_menu',
					'slug'          => 'eizport-imported-header',
					'termmeta'      => array(
						array(
							'key'   => '_eizport_menu_locations',
							'value' => 'eizport-test,footer',
						),
						array(
							'key'   => 'other',
							'value' => 'kept',
						),
					),
				),
				// Printed twice with "All content": core's and Eizport's.
				array(
					'term_id'       => 7,
					'term_taxonomy' => 'nav_menu',
					'slug'          => 'eizport-imported-header',
				),
				array(
					'term_id'       => 8,
					'term_taxonomy' => 'category',
					'slug'          => 'news',
				),
			)
		);

		$this->assertCount( 2, $terms );
		$this->assertSame( array( 'other' ), wp_list_pluck( $terms[0]['termmeta'], 'key' ) );
		$this->assertSame( array( 'eizport-test', 'footer' ), eizport_menus_state()['locations']['eizport-imported-header'] );

		// When the import ends, the menu the site has by then goes to the theme's own location only.
		$menu = (int) wp_create_nav_menu( 'Eizport Imported Header' );
		eizport_menus_import_end();
		$this->assertSame( $menu, get_nav_menu_locations()['eizport-test'] );
		$this->assertArrayNotHasKey( 'footer', get_nav_menu_locations() );
	}

	public function test_a_menu_the_site_has_keeps_its_items_without_update_existing() {
		$classic = $this->classic_menu();
		$slug    = wp_get_nav_menu_object( $classic['menu'] )->slug;
		eizport_menus_read_terms(
			array(
				array(
					'term_taxonomy' => 'nav_menu',
					'slug'          => $slug,
				),
			)
		);
		$item  = array(
			'post_type' => 'nav_menu_item',
			'terms'     => array(
				array(
					'domain' => 'nav_menu',
					'slug'   => $slug,
				),
			),
		);
		$posts = eizport_menus_existing_items( array( $item, array( 'post_type' => 'page' ) ) );

		$this->assertSame( array( array( 'post_type' => 'page' ) ), $posts );
		$this->assertCount( 2, wp_get_nav_menu_items( $classic['menu'] ) );
	}

	public function test_a_menu_the_site_has_is_emptied_with_update_existing() {
		$classic = $this->classic_menu();
		$slug    = wp_get_nav_menu_object( $classic['menu'] )->slug;
		eizport_menus_read_terms(
			array(
				array(
					'term_taxonomy' => 'nav_menu',
					'slug'          => $slug,
				),
			)
		);
		$undo  = \Eizport\Import\update_existing( true );
		$items = array( array( 'post_type' => 'nav_menu_item' ) );
		$posts = eizport_menus_existing_items( $items );
		$undo();

		$this->assertSame( $items, $posts, 'The file\'s items are imported.' );
		$this->assertSame( array(), wp_get_nav_menu_items( $classic['menu'] ), 'The old ones are gone.' );
	}

	public function test_refs_point_to_the_imported_navigations() {
		$this->assertSame(
			'<!-- wp:navigation {"ref":42,"layout":{"type":"flex"}} /--><!-- wp:navigation {"ref":5} /--><!-- wp:paragraph --><p>"ref":4</p><!-- /wp:paragraph -->',
			eizport_menus_replace_refs(
				'<!-- wp:navigation {"ref":4,"layout":{"type":"flex"}} /--><!-- wp:navigation {"ref":5} /--><!-- wp:paragraph --><p>"ref":4</p><!-- /wp:paragraph -->',
				array( 4 => 42 )
			),
			'Only navigation blocks, in one pass.'
		);

		$nav  = $this->navigation();
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Imported page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation {"ref":999998} /-->',
			)
		);
		$part = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'eizport-test-header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation {"ref":999998} /-->',
			)
		);

		$this->assertSame(
			2,
			eizport_menus_remap_refs(
				array(
					999998 => $nav,
					12     => $page,
				)
			)
		);
		$this->assertStringContainsString( '"ref":' . $nav, get_post( $page )->post_content );
		$this->assertStringContainsString( '"ref":' . $nav, get_post( $part )->post_content, 'The site\'s template pointed to a navigation it doesn\'t have.' );
	}

	/**
	 * Runs alone: export_wp() runs once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_and_import_bring_a_classic_menu_back_to_its_location() {
		$classic = $this->classic_menu( 'Eizport Round Trip' );
		$copy    = wp_get_ability( 'eizport/export' )->execute(
			array(
				'types'  => array( 'page' => array( 'ids' => array( $classic['page'] ) ) ),
				'menus'  => array( 'classic' => array( $classic['menu'] ) ),
				'bundle' => false,
			)
		);
		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$wxr = (string) file_get_contents( \Eizport\Copies\path( $copy['name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<wp:term_taxonomy>nav_menu</wp:term_taxonomy>', $wxr );
		$this->assertStringContainsString( '_eizport_menu_locations', $wxr );
		$this->assertSame( 2, substr_count( $wxr, '<wp:post_type><![CDATA[nav_menu_item]]></wp:post_type>' ) );

		// The other site: no menu, no location.
		foreach ( $classic['items'] as $item ) {
			wp_delete_post( $item, true );
		}
		wp_delete_nav_menu( $classic['menu'] );
		wp_delete_post( $classic['page'], true );
		set_theme_mod( 'nav_menu_locations', array() );

		ob_start();
		$result = \Eizport\Import\import_file( \Eizport\Copies\path( $copy['name'] ) );
		ob_end_clean();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$menu = wp_get_nav_menu_object( 'eizport-round-trip' );
		$this->assertNotFalse( $menu );
		$items = wp_get_nav_menu_items( $menu->term_id );
		$this->assertSame( array( 'Eizport menu page', 'Docs' ), wp_list_pluck( $items, 'title' ) );
		$this->assertSame( 'page', $items[0]->object );
		$this->assertSame( 'Eizport menu page', get_the_title( (int) $items[0]->object_id ), 'Points to the imported page.' );
		$this->assertSame( (int) $menu->term_id, get_nav_menu_locations()['eizport-test'] );
		$this->assertSame( array(), get_term_meta( $menu->term_id, '_eizport_menu_locations', false ), 'The meta is never stored.' );
	}
}
