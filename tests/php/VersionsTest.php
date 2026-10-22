<?php
/**
 * Tests for bundles across Eizport versions and the WordPress Importer's.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Import;

/**
 * Versions tests.
 */
class VersionsTest extends Test_Case {

	/**
	 * Clears the faked plugin list and the upload globals.
	 */
	protected function tear_down() {
		wp_cache_delete( 'plugins', 'plugins' );
		unset( $_GET['import'], $_FILES['import'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A gzipped bundle whose manifest asks for Eizport 9.0.0.
	 *
	 * @return string File.
	 */
	private function newer_bundle() {
		$tar = $this->tar(
			array(
				'manifest.json' => '{"version":2,"requires":"9.0.0","generator":"Eizport 9.0.0","wxr":["site.xml"],"files":{}}',
				'site.xml'      => '<rss version="2.0"><channel></channel></rss>',
			)
		);

		return $this->temp_file( gzencode( $tar ) );
	}

	/**
	 * Fakes the installed WordPress Importer's version (core's plugin list cache).
	 *
	 * @param string $version Version.
	 */
	private function importer_at( $version ) {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					Import\IMPORTER_PLUGIN => array(
						'Name'    => 'WordPress Importer',
						'Version' => $version,
					),
				),
			),
			'plugins'
		);
	}

	/**
	 * Logs in an administrator.
	 */
	private function admin() {
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
	}

	public function test_import_refuses_a_bundle_for_a_newer_eizport() {
		$this->admin();
		$result = Import\import_file( $this->newer_bundle() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'eizport_bundle_newer', $result->get_error_code(), 'wp eizport import and the eizport/import ability return it as is.' );
	}

	public function test_the_importer_upload_refuses_a_bundle_for_a_newer_eizport() {
		$this->admin();
		$file             = $this->newer_bundle();
		$_GET['import']   = Import\IMPORTER_ID;
		$_FILES['import'] = array( 'tmp_name' => $file );
		$upload           = Import\decompress_upload(
			array(
				'name'     => 'site.wxrz',
				'tmp_name' => $file,
				'error'    => 0,
			)
		);

		$this->assertStringContainsString( 'needs Eizport 9.0.0 or newer', $upload['error'] );
	}

	public function test_the_importer_s_version_range() {
		$this->importer_at( '0.9.5' );
		$this->assertSame( 'eizport_importer_old', Import\importer_too_old()->get_error_code() );
		$this->assertFalse( Import\importer_untested() );
		$this->admin();
		$this->assertSame( 'eizport_importer_old', Import\import_file( $this->newer_bundle() )->get_error_code(), 'Before the bundle is read.' );

		$this->importer_at( Import\IMPORTER_TESTED );
		$this->assertNull( Import\importer_too_old() );
		$this->assertFalse( Import\importer_untested() );

		$this->importer_at( '9.9.9' );
		$this->assertNull( Import\importer_too_old(), 'Newer than tested: it still imports.' );
		$this->assertTrue( Import\importer_untested() );
		$this->assertStringContainsString( 'tested up to ' . Import\IMPORTER_TESTED, Import\importer_untested_message() );
	}

	public function test_the_bundle_plugin_asks_an_older_eizport_to_update() {
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/bundle-plugin.php.tpl' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "\$requires = 'EIZPORT_REQUIRES';", $template );
		$this->assertStringContainsString( "'update' : ''", $template, 'An active Eizport older than the bundle needs: update.' );
		$this->assertStringContainsString( "update.php?action=upgrade-plugin&plugin=' . rawurlencode( \$main )", $template );
		$this->assertStringContainsString( 'This plugin needs Eizport %1$s or newer; this site has %2$s.', $template );
	}
}
