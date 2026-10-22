<?php
/**
 * Links to the source site, replaced by this site's address when an import
 * ends.
 *
 * Posts are made inside a transaction rolled back after each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

/**
 * Source links tests.
 */
class SourceLinksTest extends Test_Case {

	/**
	 * Opens the transaction.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		require_once dirname( __DIR__, 2 ) . '/includes/apply-options.php';
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		unset( $GLOBALS['wp_import'] );
		parent::tear_down();
	}

	public function test_plain_and_escaped_links_with_either_scheme() {
		$out = eizport_replace_source_links(
			'<a href="http://old.example/shop/">Shop</a> <a href="https://old.example">Home</a> '
			. '<!-- wp:navigation-link {"url":"http:\/\/old.example\/about\/"} /--> '
			. '<a href="https://old.example.org/">other</a> <a href="//old.example/x">x</a> old.example',
			'http://old.example',
			'https://new.test/site'
		);

		$this->assertStringContainsString( 'href="https://new.test/site/shop/"', $out );
		$this->assertStringContainsString( 'href="https://new.test/site"', $out );
		$this->assertStringContainsString( '{"url":"https:\/\/new.test\/site\/about\/"}', $out );
		$this->assertStringContainsString( 'https://old.example.org/', $out, 'Another site.' );
		$this->assertStringContainsString( 'href="//new.test/site/x"', $out );
		$this->assertStringEndsWith( ' old.example', $out, 'A bare host, not an address.' );
		$this->assertSame( 'same', eizport_replace_source_links( 'same', 'https://a.test', 'https://a.test/' ) );
	}

	public function test_imported_items_and_menu_links_when_the_import_ends() {
		$page                 = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Links',
				'post_status'  => 'publish',
				'post_content' => '<a href="https://old.example/product/x/">X</a>',
				'post_excerpt' => 'See https://old.example/about/',
			)
		);
		$menu                 = (int) wp_create_nav_menu( 'Eizport source links test' );
		$item                 = (int) wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Blog',
				'menu-item-url'    => 'https://old.example/blog/',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$GLOBALS['wp_import'] = (object) array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's, unset in tear_down().
			'base_url'             => 'https://old.example',
			'processed_posts'      => array( 7 => $page ),
			'processed_menu_items' => array( 8 => $item ),
		);
		$home                 = untrailingslashit( home_url() );

		$this->assertSame( 1, eizport_remap_source_links() );
		$this->assertStringContainsString( 'href="' . $home . '/product/x/"', get_post( $page )->post_content );
		$this->assertSame( 'See ' . $home . '/about/', get_post( $page )->post_excerpt );
		$this->assertSame( $home . '/blog/', get_post_meta( $item, '_menu_item_url', true ) );
	}

	public function test_widgets_and_theme_mods_get_the_source_links_too() {
		$urls = eizport_urls_with_source( array(), 'https://old.example' );
		$home = untrailingslashit( home_url() );

		$this->assertSame( $home, $urls['https://old.example'] );
		$this->assertSame(
			'<!-- wp:paragraph --><a href="' . $home . '/shop/">Shop</a><!-- /wp:paragraph -->',
			eizport_remap_urls( '<!-- wp:paragraph --><a href="https://old.example/shop/">Shop</a><!-- /wp:paragraph -->', $urls )
		);
	}
}
