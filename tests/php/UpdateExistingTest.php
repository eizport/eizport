<?php
/**
 * "Update items that already exist" and the term media mapped on import.
 *
 * Posts and terms are made inside a transaction rolled back after each
 * test: nothing stays in the site's database.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Import;

/**
 * Update existing tests.
 */
class UpdateExistingTest extends Test_Case {

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
		remove_all_filters( 'eizport_term_media' );
		remove_all_actions( 'eizport_updated_from_import' );
		parent::tear_down();
	}

	/**
	 * A page, rolled back with the test.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private function page( $slug ) {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => 'Theme ' . $slug,
				'post_content' => 'From the theme.',
			)
		);
	}

	public function test_existing_by_slug_matches_type_and_slug() {
		$slug = 'eizport-test-' . wp_generate_password( 8, false, false );
		$id   = $this->page( $slug );

		$this->assertSame(
			$id,
			Import\existing_by_slug(
				array(
					'post_type' => 'page',
					'post_name' => $slug,
				)
			)
		);
		$this->assertSame(
			0,
			Import\existing_by_slug(
				array(
					'post_type' => 'post',
					'post_name' => $slug,
				)
			)
		);
		$this->assertSame(
			0,
			Import\existing_by_slug(
				array(
					'post_type' => 'attachment',
					'post_name' => $slug,
				)
			)
		);
		$this->assertSame(
			0,
			Import\existing_by_slug(
				array(
					'post_type' => 'page',
					'post_name' => '',
				)
			)
		);
	}

	public function test_update_from_import_rewrites_the_post() {
		$id    = $this->page( 'eizport-test-' . wp_generate_password( 8, false, false ) );
		$fired = 0;
		add_action(
			'eizport_updated_from_import',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		Import\update_from_import(
			$id,
			array(
				'post_title'   => 'Home',
				'post_content' => '<!-- wp:paragraph --><p>Imported</p><!-- /wp:paragraph -->',
				'post_excerpt' => 'Short',
				'menu_order'   => 3,
				'postmeta'     => array(
					array(
						'key'   => '_eizport_test',
						'value' => serialize( array( 'a' => 1 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as a WXR holds it.
					),
					array(
						'key'   => '_edit_lock',
						'value' => '1:1',
					),
				),
			)
		);

		$post = get_post( $id );
		$this->assertSame( 'Home', $post->post_title );
		$this->assertStringContainsString( '<p>Imported</p>', $post->post_content );
		$this->assertSame( 'Short', $post->post_excerpt );
		$this->assertSame( 3, (int) $post->menu_order );
		$this->assertSame( array( 'a' => 1 ), get_post_meta( $id, '_eizport_test', true ) );
		$this->assertSame( '', get_post_meta( $id, '_edit_lock', true ) );
		$this->assertSame( 1, $fired );
	}

	public function test_update_existing_hooks_only_when_on() {
		$off = Import\update_existing( false );
		// The plugin's own filter (existing attachments' URLs) is always there: only the update's ones are checked.
		$this->assertSame( 10, has_filter( 'wp_import_existing_post', 'Eizport\\Import\\existing_attachment_urls' ) );
		$this->assertFalse( has_filter( 'eizport_menus_update_existing' ) );
		$off();

		$slug   = 'eizport-test-' . wp_generate_password( 8, false, false );
		$id     = $this->page( $slug );
		$remove = Import\update_existing( true );
		$this->assertSame(
			$id,
			apply_filters(
				'wp_import_existing_post', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WordPress Importer's.
				0,
				array(
					'post_type'  => 'page',
					'post_name'  => $slug,
					'post_title' => 'Theme ' . $slug,
				)
			)
		);
		$this->assertSame( 'Theme ' . $slug, get_post( $id )->post_title, 'No title: kept as the item had it.' );
		$remove();
		$this->assertFalse( has_filter( 'eizport_menus_update_existing' ), 'The update\'s filters are gone.' );
	}

	public function test_term_media_is_mapped_to_the_imported_attachments() {
		register_taxonomy( 'eizport_test_tax', 'post' );
		add_filter(
			'eizport_term_media',
			function ( $keys ) {
				$keys['eizport_test_tax'] = array( 'thumbnail_id' );
				return $keys;
			}
		);
		$term = wp_insert_term( 'Western', 'eizport_test_tax' );
		update_term_meta( $term['term_id'], 'thumbnail_id', 101 );
		$kept = wp_insert_term( 'English', 'eizport_test_tax' );
		update_term_meta( $kept['term_id'], 'thumbnail_id', 999 );

		$attachment = (int) wp_insert_attachment(
			array(
				'post_title'     => 'thumb',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$mapped = Import\remap_term_media(
			array(
				'eizport_test_tax' => array(
					'western' => array( 'thumbnail_id' => 101 ),
					'english' => array( 'thumbnail_id' => 102 ),
					'missing' => array( 'thumbnail_id' => 101 ),
				),
				'category'         => array( 'uncategorized' => array( 'thumbnail_id' => 101 ) ),
			),
			array(
				101 => $attachment,
				102 => $term['term_id'] + 999999,
			)
		);
		unregister_taxonomy( 'eizport_test_tax' );

		$this->assertSame( 1, $mapped );
		$this->assertSame( $attachment, (int) get_term_meta( $term['term_id'], 'thumbnail_id', true ) );
		$this->assertSame( 999, (int) get_term_meta( $kept['term_id'], 'thumbnail_id', true ), 'Not an attachment: left alone.' );
	}

	public function test_existing_attachment_urls_are_remapped() {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => 'hero',
				'post_mime_type' => 'image/jpeg',
			),
			'2026/10/hero.jpg'
		);
		update_post_meta( $id, '_wp_attached_file', '2026/10/hero.jpg' );
		$saved                = $GLOBALS['wp_import'] ?? null;
		$GLOBALS['wp_import'] = (object) array( 'url_remap' => array() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's, restored below.

		Import\remap_existing_attachment( $id, array( 'attachment_url' => 'https://old.example/wp-content/uploads/2026/10/hero.jpg' ) );
		$remap                = $GLOBALS['wp_import']->url_remap;
		$GLOBALS['wp_import'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		$new = wp_get_attachment_url( $id );
		$this->assertSame( $new, $remap['https://old.example/wp-content/uploads/2026/10/hero.jpg'] );
		$this->assertSame( substr( $new, 0, -4 ), $remap['https://old.example/wp-content/uploads/2026/10/hero'], 'Resized images too.' );
	}

	public function test_existing_attachments_are_remapped_without_update_existing() {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => 'kept',
				'post_mime_type' => 'image/jpeg',
			),
			'2026/10/kept.jpg'
		);
		update_post_meta( $id, '_wp_attached_file', '2026/10/kept.jpg' );
		$saved                = $GLOBALS['wp_import'] ?? null;
		$GLOBALS['wp_import'] = (object) array( 'url_remap' => array() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's, restored below.

		// "Update existing" off: only the plugin's own filter runs.
		$found                = apply_filters(
			'wp_import_existing_post', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WordPress Importer's.
			$id,
			array(
				'post_type'      => 'attachment',
				'attachment_url' => 'https://old.example/wp-content/uploads/2026/10/kept.jpg',
			)
		);
		$remap                = $GLOBALS['wp_import']->url_remap;
		$none                 = apply_filters( 'wp_import_existing_post', 0, array( 'post_type' => 'attachment' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WordPress Importer's.
		$GLOBALS['wp_import'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		$this->assertSame( $id, $found, 'The importer still skips it.' );
		$this->assertSame( wp_get_attachment_url( $id ), $remap['https://old.example/wp-content/uploads/2026/10/kept.jpg'] );
		$this->assertSame( 0, $none, 'A new attachment is left to the importer.' );
	}

	public function test_term_media_of_lists_the_items_terms() {
		register_taxonomy( 'eizport_test_tax', 'post' );
		add_filter(
			'eizport_term_media',
			function ( $keys ) {
				$keys['eizport_test_tax'] = array( 'thumbnail_id' );
				return $keys;
			}
		);
		$term = wp_insert_term( 'Western', 'eizport_test_tax' );
		update_term_meta( $term['term_id'], 'thumbnail_id', 101 );
		$wxr = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $wxr, "<item>\n<category domain=\"eizport_test_tax\" nicename=\"western\"><![CDATA[Western]]></category>\n<category domain=\"category\" nicename=\"uncategorized\"><![CDATA[U]]></category>\n</item>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$media = \Eizport\Bundle\term_media_of( $wxr );
		unregister_taxonomy( 'eizport_test_tax' );

		$this->assertSame( array( 'eizport_test_tax' => array( 'western' => array( 'thumbnail_id' => 101 ) ) ), $media );
	}

	public function test_bundle_updates_existing_reads_the_manifest() {
		$dir = $this->temp_file();
		wp_delete_file( $dir );
		wp_mkdir_p( $dir );
		$this->assertFalse( Import\bundle_updates_existing( $dir ) );
		file_put_contents( $dir . '/manifest.json', wp_json_encode( array( 'update_existing' => true ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertTrue( Import\bundle_updates_existing( $dir ) );
		$this->assertFalse( Import\bundle_updates_existing( '' ) );
	}

	public function test_the_label_names_the_types_the_bundle_holds() {
		$this->assertSame(
			'Update the items that already exist (same type and slug) instead of adding copies: Pages and Posts',
			\Eizport\Import\update_existing_label( array( 'page', 'post', 'attachment' ) )
		);
		$this->assertStringNotContainsString( 'eizport_no_such_type', \Eizport\Import\update_existing_label( array( 'page', 'eizport_no_such_type' ) ), 'A type this site does not have is left out.' );
		$this->assertSame( '', \Eizport\Import\update_existing_label( array( 'attachment', 'nav_menu_item' ) ), 'Nothing the update reaches: no box.' );
		$this->assertSame( '', \Eizport\Import\update_existing_label( array() ) );
	}

	public function test_wxr_types_reads_the_items_post_types() {
		$wxr = $this->temp_file( "<rss>\n<item>\n<wp:post_type><![CDATA[page]]></wp:post_type>\n</item>\n<item>\n<wp:post_type><![CDATA[attachment]]></wp:post_type>\n</item>\n<item>\n<wp:post_type><![CDATA[page]]></wp:post_type>\n</item>\n</rss>\n" );

		$this->assertSame( array( 'page', 'attachment' ), \Eizport\Import\wxr_types( $wxr ) );
		$this->assertSame( array(), \Eizport\Import\wxr_types( '/no/such/file.xml' ) );
	}
}
