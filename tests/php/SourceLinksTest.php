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
			. '<a href="https://old.example.org/">other</a> <a href="//old.example/x">x</a> Our address is: http://old.example. old.example',
			'http://old.example',
			'https://new.test/site'
		);

		$this->assertStringContainsString( 'href="https://new.test/site/shop/"', $out );
		$this->assertStringContainsString( 'href="https://new.test/site"', $out );
		$this->assertStringContainsString( '{"url":"https:\/\/new.test\/site\/about\/"}', $out );
		$this->assertStringContainsString( 'https://old.example.org/', $out, 'Another site.' );
		$this->assertStringContainsString( 'href="//new.test/site/x"', $out );
		$this->assertStringEndsWith( ' old.example', $out, 'A bare host, not an address.' );
		$this->assertStringContainsString( 'Our address is: https://new.test/site. ', $out, 'A full stop ends the address.' );
		$this->assertSame( 'same', eizport_replace_source_links( 'same', 'https://a.test', 'https://a.test/' ) );
	}

	public function test_on_a_network_this_sites_addresses_are_never_touched() {
		// From the main site (http://net.test) to a subsite (http://net.test/b): its uploads and home start with the source.
		$keep = array( 'http://net.test/b', 'http://net.test/wp-content/uploads/sites/3' );
		$out  = eizport_replace_source_links(
			'<img src="http://net.test/wp-content/uploads/sites/3/red.png"/><a href="http://net.test/about/">About</a><a href="http://net.test/b/contact/">Here</a>',
			'http://net.test',
			'http://net.test/b',
			$keep
		);
		$this->assertSame( '<img src="http://net.test/wp-content/uploads/sites/3/red.png"/><a href="http://net.test/b/about/">About</a><a href="http://net.test/b/contact/">Here</a>', $out );

		// From a subsite (http://net.test/a) to the main site (http://net.test): the shorter home isn't held back.
		$this->assertSame(
			'<a href="http://net.test/hello/">x</a>',
			eizport_replace_source_links( '<a href="http://net.test/a/hello/">x</a>', 'http://net.test/a', 'http://net.test', array( 'http://net.test' ) )
		);
	}

	public function test_the_source_home_is_the_files_blog_url() {
		$file = $this->temp_file();
		file_put_contents( $file, "<rss>\n<channel>\n\t<wp:base_site_url>http://net.test</wp:base_site_url>\n\t<wp:base_blog_url>http://net.test/a</wp:base_blog_url>\n<item>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$GLOBALS['eizport_wxr_file'] = $file;
		$home                        = eizport_source_home( (object) array( 'base_url' => 'http://net.test' ) );
		unset( $GLOBALS['eizport_wxr_file'] );

		$this->assertSame( 'http://net.test/a', $home );
		$this->assertSame( 'http://net.test', eizport_source_home( (object) array( 'base_url' => 'http://net.test' ) ), 'No file: the importer\'s base URL.' );
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
