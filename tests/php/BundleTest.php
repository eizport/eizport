<?php
/**
 * Tests for bundles: entry safety, content files and unpacking.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Bundle;

/**
 * Bundle tests.
 */
class BundleTest extends Test_Case {

	/**
	 * Clears the export collector.
	 */
	protected function set_up() {
		parent::set_up();
		$GLOBALS['eizport_post_content'] = array();
	}

	/**
	 * A minimal WXR with the given items.
	 *
	 * @param array[] $items Each with id, type, slug and content.
	 * @return string
	 */
	private function wxr( array $items ) {
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<rss version=\"2.0\">\n<channel>\n\t<wp:wxr_version>1.2</wp:wxr_version>\n";
		foreach ( $items as $item ) {
			$xml .= "\t<item>\n"
				. "\t\t<title><![CDATA[" . $item['slug'] . "]]></title>\n"
				. "\t\t<content:encoded><![CDATA[" . $item['content'] . "]]></content:encoded>\n"
				. "\t\t<wp:post_id>" . $item['id'] . "</wp:post_id>\n"
				. "\t\t<wp:post_name><![CDATA[" . $item['slug'] . "]]></wp:post_name>\n"
				. "\t\t<wp:post_type><![CDATA[" . $item['type'] . "]]></wp:post_type>\n"
				. "\t</item>\n";
		}

		return $xml . "</channel>\n</rss>\n";
	}

	/**
	 * Content of each item of a WXR, by original ID, read with SimpleXML as
	 * the importer does.
	 *
	 * @param string $path WXR file.
	 * @return array<int, string>
	 */
	private function contents( $path ) {
		$xml = simplexml_load_string( str_replace( array( 'content:encoded', 'wp:' ), array( 'content', 'wp_' ), file_get_contents( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$out = array();
		foreach ( $xml->channel->item as $item ) {
			$out[ (int) $item->wp_post_id ] = (string) $item->content;
		}

		return $out;
	}

	/**
	 * Unpacks a bundle made of the given entries.
	 *
	 * @param array<string, string> $entries Entries.
	 * @return array{0: string|\WP_Error, 1: string} Folder or error, WXR path.
	 */
	private function unpack_entries( array $entries ) {
		$tar = $this->temp_file( $this->tar( $entries ) );
		$wxr = $this->temp_file();
		$dir = Bundle\unpack_bundle( $tar, $wxr );
		if ( is_string( $dir ) ) {
			$this->remove_later( $dir );
		}

		return array( $dir, $wxr );
	}

	public function test_safe_entries() {
		$this->assertTrue( Bundle\is_safe_entry( 'uploads/2026/10/a.jpg' ) );
		$this->assertTrue( Bundle\is_safe_entry( 'uploads/doc.pdf' ) );

		$this->assertFalse( Bundle\is_safe_entry( 'uploads/a.php' ), 'PHP' );
		$this->assertFalse( Bundle\is_safe_entry( 'uploads/a.phtml' ), 'PHTML' );
		$this->assertFalse( Bundle\is_safe_entry( 'uploads/../../a.jpg' ), 'Traversal' );
		$this->assertFalse( Bundle\is_safe_entry( 'uploads/./a.jpg' ), 'Dot segment' );
		$this->assertFalse( Bundle\is_safe_entry( 'uploads//a.jpg' ), 'Empty segment' );
		$this->assertFalse( Bundle\is_safe_entry( 'uploads\\a.jpg' ), 'Backslash' );
		$this->assertFalse( Bundle\is_safe_entry( "uploads/a\0.jpg" ), 'NUL' );
		$this->assertFalse( Bundle\is_safe_entry( 'themes/a.jpg' ), 'Outside uploads/' );
		$this->assertFalse( Bundle\is_safe_entry( array( 'uploads/a.jpg' ) ), 'Not a string' );
	}

	public function test_content_names() {
		$this->assertTrue( Bundle\is_content_name( 'about.html' ) );
		$this->assertTrue( Bundle\is_content_name( '%c3%a9t%c3%a9.html' ) );
		$this->assertTrue( Bundle\is_content_name( 'landing.json' ), 'A builder\'s design.' );
		$this->assertFalse( Bundle\is_content_name( 'a.js' ) );
		$this->assertFalse( Bundle\is_content_name( '.hidden.html' ) );
		$this->assertFalse( Bundle\is_content_name( 'a/b.html' ) );
		$this->assertFalse( Bundle\is_content_name( 'a.php' ) );
		$this->assertFalse( Bundle\is_content_name( '.html' ) );
	}

	public function test_content_file_names_avoid_collisions() {
		$page  = new \WP_Post(
			(object) array(
				'ID'        => 5,
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$post  = new \WP_Post(
			(object) array(
				'ID'        => 20,
				'post_type' => 'post',
				'post_name' => 'about',
			)
		);
		$draft = new \WP_Post(
			(object) array(
				'ID'        => 30,
				'post_type' => 'page',
				'post_name' => '',
			)
		);

		$this->assertSame( 'about.html', Bundle\content_file_name( $page ) );
		$GLOBALS['eizport_post_content']['about.html'] = array();
		$this->assertSame( 'post-20-about.html', Bundle\content_file_name( $post ) );
		$this->assertSame( 'page-30.html', Bundle\content_file_name( $draft ) );
	}

	public function test_merge_by_manifest_by_slug_and_filter() {
		$wxr = $this->temp_file(
			$this->wxr(
				array(
					array( 'id' => 5, 'type' => 'page', 'slug' => 'about', 'content' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 20, 'type' => 'post', 'slug' => 'about', 'content' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 7, 'type' => 'page', 'slug' => 'contato', 'content' => 'kept' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 8, 'type' => 'attachment', 'slug' => 'foto', 'content' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 9, 'type' => 'page', 'slug' => 'filtrada', 'content' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				)
			)
		);
		$dir = get_temp_dir() . 'eizport-test-' . wp_generate_password( 8, false );
		$this->remove_later( $dir );
		wp_mkdir_p( $dir . '/post_content' );
		file_put_contents( $dir . '/post_content/about.html', '<p>Page a]]>b é ü</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/post_content/post-20-about.html', '<p>Post</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/post_content/foto.html', 'never used' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		add_filter(
			'eizport_post_content',
			function ( $content, $item ) {
				return 'filtrada' === $item['slug'] ? '<p>From the filter</p>' : $content;
			},
			10,
			2
		);

		$manifest = array(
			'post_content' => array(
				'about.html'         => array( 'id' => 5 ),
				'post-20-about.html' => array( 'id' => 20 ),
			),
		);
		$this->assertTrue( Bundle\merge_post_content( $wxr, $dir, $manifest ) );

		$this->assertSame(
			array(
				5  => '<p>Page a]]>b é ü</p>',
				20 => '<p>Post</p>',
				7  => 'kept',
				8  => '',
				9  => '<p>From the filter</p>',
			),
			$this->contents( $wxr )
		);
	}

	public function test_unpack_writes_only_listed_safe_files() {
		$wxr      = $this->wxr( array( array( 'id' => 5, 'type' => 'page', 'slug' => 'about', 'content' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$manifest = wp_json_encode(
			array(
				'version' => 1,
				'wxr'     => array( 'site.xml' ),
				'files'   => array(
					'http://x/a.jpg'   => 'uploads/ok/a.jpg',
					'http://x/b.php'   => 'uploads/b.php',
					'http://x/c.jpg'   => 'uploads/../../c.jpg',
					'http://x/d.phtml' => 'uploads/ok/d.phtml',
				),
			)
		);

		list( $dir, $wxr_path ) = $this->unpack_entries(
			array(
				'manifest.json'           => $manifest,
				'site.xml'                => $wxr,
				'uploads/ok/a.jpg'        => 'jpeg',
				'uploads/b.php'           => '<?php',
				'uploads/../../c.jpg'     => 'jpeg',
				'uploads/ok/d.phtml'      => '<?php',
				'uploads/not-listed.jpg'  => 'jpeg',
				'post_content/about.html' => '<p>About</p>',
				'post_content/../x.html'  => 'nope',
			)
		);

		$this->assertIsString( $dir );
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$files[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
		}
		sort( $files );
		$this->assertSame( array( 'index.php', 'manifest.json', 'post_content/about.html', 'uploads/ok/a.jpg' ), $files );
		$this->assertSame( array( 5 => '<p>About</p>' ), $this->contents( $wxr_path ) );
	}

	public function test_unpack_needs_a_valid_manifest_and_the_wxr() {
		list( $no_manifest ) = $this->unpack_entries( array( 'site.xml' => '<rss/>' ) );
		$this->assertSame( 'eizport_bundle_manifest', $no_manifest->get_error_code() );

		list( $bad_version ) = $this->unpack_entries( array( 'manifest.json' => '{"version":"2","wxr":["site.xml"],"files":{}}' ) );
		$this->assertSame( 'eizport_bundle_manifest', $bad_version->get_error_code() );

		list( $no_wxr ) = $this->unpack_entries( array( 'manifest.json' => '{"version":1,"wxr":["site.xml"],"files":{}}' ) );
		$this->assertSame( 'eizport_bundle_corrupt', $no_wxr->get_error_code() );
	}

	public function test_the_manifest_says_which_eizport_made_it_and_needs() {
		$wxr = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $wxr, '<rss></rss>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		ob_start();
		Bundle\print_archive( $wxr, 'site.xml', false );
		$tar = $this->temp_file( (string) ob_get_clean() );

		$manifest = Bundle\read_manifest( $tar );
		$this->assertSame( Bundle\FORMAT_VERSION, $manifest['version'] );
		$this->assertSame( Bundle\REQUIRES, $manifest['requires'] );
		$this->assertSame( 'Eizport ' . \Eizport\VERSION, $manifest['generator'] );
		$this->assertSame( \Eizport\VERSION, Bundle\made_with( $manifest ) );
	}

	public function test_a_bundle_for_a_newer_eizport_is_refused() {
		$wxr = $this->wxr( array() );

		// A format this version doesn't know.
		list( $format ) = $this->unpack_entries(
			array(
				'manifest.json' => '{"version":3,"requires":"9.0.0","generator":"Eizport 9.0.1","wxr":["site.xml"],"files":{}}',
				'site.xml'      => $wxr,
			)
		);
		$this->assertSame( 'eizport_bundle_newer', $format->get_error_code() );
		$this->assertStringContainsString( 'made with Eizport 9.0.1 and needs Eizport 9.0.0 or newer; this site has ' . \Eizport\VERSION, $format->get_error_message() );

		// This format, but a newer Eizport asked for.
		list( $requires ) = $this->unpack_entries(
			array(
				'manifest.json' => '{"version":2,"requires":"9.0.0","wxr":["site.xml"],"files":{}}',
				'site.xml'      => $wxr,
			)
		);
		$this->assertSame( 'eizport_bundle_newer', $requires->get_error_code() );

		// This version's own bundles, even from a development copy (1.1.0-dev reads 1.1.0's).
		list( $own ) = $this->unpack_entries(
			array(
				'manifest.json' => wp_json_encode(
					array(
						'version'  => Bundle\FORMAT_VERSION,
						'requires' => preg_replace( '/-.*$/', '', \Eizport\VERSION ),
						'wxr'      => array( 'site.xml' ),
						'files'    => array(),
					)
				),
				'site.xml'      => $wxr,
			)
		);
		$this->assertIsString( $own );

		// Made before 1.1.0: format 1, no "requires".
		list( $old ) = $this->unpack_entries(
			array(
				'manifest.json' => '{"format":"wxrz","version":1,"generator":"Eizport 1.0.1","wxr":["site.xml"],"files":{}}',
				'site.xml'      => $wxr,
			)
		);
		$this->assertIsString( $old, 'A 1.0.1 bundle still imports.' );
	}

	public function test_delete_folder_only_deletes_bundle_folders() {
		$outside = get_temp_dir() . 'eizport-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );
		$this->remove_later( $outside );

		Bundle\delete_folder( $outside );
		$this->assertDirectoryExists( $outside );

		$inside = Bundle\create_folder();
		$this->assertSame( \Eizport\Files\tmp_path(), dirname( $inside ) );
		$this->assertFileExists( dirname( $inside ) . '/index.php' );
		$this->assertFileExists( $inside . '/index.php' );
		Bundle\delete_folder( $inside );
		$this->assertDirectoryDoesNotExist( $inside );
	}
}
