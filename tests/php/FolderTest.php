<?php
/**
 * Bundles as folders (wp eizport export --dir, wp eizport import <folder>).
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Folder;

/**
 * Folder tests.
 */
class FolderTest extends Test_Case {

	/**
	 * A WXR on one line, as the exporter may write it.
	 */
	const WXR = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Site</title><item><title><![CDATA[Home]]></title><content:encoded><![CDATA[<p>Line one
line two</p>]]></content:encoded><wp:post_name><![CDATA[home]]></wp:post_name></item></channel></rss>';

	/**
	 * A new empty folder, removed after the test.
	 *
	 * @return string
	 */
	private function folder() {
		$dir = $this->temp_file();
		wp_delete_file( $dir );
		wp_mkdir_p( $dir );

		return $dir;
	}

	/**
	 * The XML with whitespace between elements dropped.
	 *
	 * @param string $xml XML.
	 * @return string
	 */
	private function normalized( $xml ) {
		$document                     = new \DOMDocument();
		$document->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$document->loadXML( $xml );

		return $document->saveXML();
	}

	public function test_format_wxr_breaks_lines_and_keeps_contents() {
		$path = $this->temp_file( self::WXR );

		$this->assertTrue( Folder\format_wxr( $path ) );
		$formatted = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertGreaterThan( 5, substr_count( $formatted, "\n" ) );
		$this->assertStringContainsString( "<![CDATA[<p>Line one\nline two</p>]]>", $formatted );
		$this->assertSame( $this->normalized( self::WXR ), $this->normalized( $formatted ) );
	}

	public function test_pack_needs_a_manifest() {
		$this->assertWPError( Folder\pack( $this->folder(), $this->temp_file() ) );
	}

	public function test_pack_and_extract_round_trip() {
		$source = $this->folder();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$source . '/manifest.json',
			wp_json_encode(
				array(
					'format'   => 'wxrz',
					'version'  => \Eizport\Bundle\FORMAT_VERSION,
					'requires' => \Eizport\Bundle\REQUIRES,
					'wxr'      => array( 'export.xml' ),
					'files'    => array(),
				)
			)
		);
		file_put_contents( $source . '/export.xml', self::WXR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $source . '/notes.txt', 'not a bundle entry' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertSame( array( 'export.xml', 'manifest.json' ), array_keys( Folder\entries( $source ) ) );

		$tar = $this->temp_file();
		$this->assertTrue( Folder\pack( $source, $tar ) );

		$target = $this->folder();
		wp_mkdir_p( $target . '/post_content' );
		file_put_contents( $target . '/stale.xml', 'old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $target . '/post_content/1.html', 'old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $target . '/README.md', 'kept' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$manifest = Folder\extract( $tar, $target );
		$this->assertIsArray( $manifest, is_wp_error( $manifest ) ? $manifest->get_error_message() : '' );

		$this->assertSame( array( 'export.xml' ), $manifest['wxr'] );
		$this->assertSame( array( 'export.xml', 'manifest.json' ), array_keys( Folder\entries( $target ) ) );
		$this->assertFileExists( $target . '/README.md' );
		$this->assertDirectoryDoesNotExist( $target . '/post_content' );
		$this->assertSame( $this->normalized( self::WXR ), $this->normalized( (string) file_get_contents( $target . '/export.xml' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_extract_gives_the_wxr_a_stable_name() {
		$source = $this->folder();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$source . '/manifest.json',
			wp_json_encode(
				array(
					'format'   => 'wxrz',
					'version'  => \Eizport\Bundle\FORMAT_VERSION,
					'requires' => \Eizport\Bundle\REQUIRES,
					'wxr'      => array( 'site.WordPress.2026-10-12.xml' ),
					'files'    => array(),
				)
			)
		);
		file_put_contents( $source . '/site.WordPress.2026-10-12.xml', self::WXR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$tar = $this->temp_file();
		Folder\pack( $source, $tar );

		$target   = $this->folder();
		$manifest = Folder\extract( $tar, $target, 'delivery' );

		$this->assertSame( array( 'delivery.xml' ), $manifest['wxr'] );
		$this->assertSame( array( 'delivery.xml', 'manifest.json' ), array_keys( Folder\entries( $target ) ) );
		$this->assertSame( array( 'delivery.xml' ), json_decode( (string) file_get_contents( $target . '/manifest.json' ), true )['wxr'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// The folder still imports: it packs back into a valid bundle.
		$again = $this->temp_file();
		$this->assertTrue( Folder\pack( $target, $again ) );
		$this->assertIsArray( \Eizport\Bundle\read_manifest( $again ) );
	}

	/**
	 * Asserts a WP_Error.
	 *
	 * @param mixed $actual Value.
	 */
	private function assertWPError( $actual ) {
		$this->assertInstanceOf( \WP_Error::class, $actual );
	}
}
