<?php
/**
 * Tests for the export arguments and import upload helpers.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Export;
use Eizport\Import;

/**
 * Export and import helper tests.
 */
class ExportImportTest extends Test_Case {

	/**
	 * Restores $_GET.
	 */
	protected function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	public function test_import_names_end_in_xml() {
		$this->assertSame( 'site.xml.txt', Import\xml_name( 'site.wxrz.txt' ) );
		$this->assertSame( 'site.xml.txt', Import\xml_name( 'site.xml.gz.txt' ) );
		$this->assertSame( 'site.xml.txt', Import\xml_name( 'site.WXRZ.txt' ) );
		$this->assertSame( 'site.xml', Import\xml_name( 'site.wxrz' ) );
	}

	public function test_file_name_is_only_for_bundles() {
		$_GET = array( 'eizport_filename' => 'meu-export' );
		$this->assertArrayNotHasKey( 'filename', Export\file_args( array() ) );

		$_GET = array(
			'eizport_bundle'   => '1',
			'eizport_filename' => '',
		);
		$this->assertSame( Export\default_filename(), Export\file_args( array() )['filename'] );

		$_GET['eizport_filename'] = '../meu export.wxrz';
		$this->assertSame( 'meu-export', Export\file_args( array() )['filename'] );

		$_GET['eizport_filename'] = 'site.ZIP';
		$this->assertSame( 'site', Export\file_args( array() )['filename'] );
	}

	public function test_bundle_options_need_the_bundle() {
		$_GET = array(
			'eizport_media'        => '1',
			'eizport_post_content' => '1',
			'eizport_blueprint'    => '1',
			'eizport_keep_copy'    => '1',
		);
		$args = Export\file_args( array() );
		$this->assertFalse( $args['keep_copy'] );
		$this->assertFalse( $args['bundle'] );
		$this->assertFalse( $args['media'] );
		$this->assertFalse( $args['post_content'] );
		$this->assertFalse( $args['blueprint'] );

		$_GET['eizport_bundle'] = '1';
		$args                   = Export\file_args( array() );
		$this->assertTrue( $args['media'] );
		$this->assertTrue( $args['post_content'], 'The Playground .zip takes content files too.' );
		$this->assertTrue( $args['blueprint'] );
		$this->assertFalse( $args['keep_copy'], 'A copy is written to disk only with the form\'s nonce.' );

		$_GET[ Export\KEEP_NONCE ] = wp_create_nonce( Export\KEEP_FIELD );
		$this->assertTrue( Export\file_args( array() )['keep_copy'] );

		unset( $_GET['eizport_blueprint'] );
		$this->assertTrue( Export\file_args( array() )['post_content'] );
		$this->assertFalse( Export\file_args( array() )['keep_copy'], 'Only a Playground .zip is kept.' );
		$_GET['eizport_blueprint'] = '1';

		unset( $_GET['eizport_media'] );
		$this->assertFalse( Export\file_args( array() )['media'] );
	}

	public function test_blueprint_themes_and_plugins_must_be_installed() {
		$_GET = array(
			'eizport_bundle'    => '1',
			'eizport_blueprint' => '1',
			'eizport_themes'    => array( get_stylesheet(), '../etc', get_stylesheet() ),
			'eizport_plugins'   => array( 'plugin-check/plugin.php', 'nope/nope.php' ),
		);
		$args = Export\file_args( array() );
		$this->assertSame( array( get_stylesheet() ), $args['themes'] );
		$this->assertSame( array_intersect( array( 'plugin-check/plugin.php' ), array_keys( get_plugins() ) ), $args['plugins'] );

		unset( $_GET['eizport_blueprint'] );
		$this->assertSame( array(), Export\file_args( array() )['themes'] );
	}

	public function test_importer_loads_when_its_plugin_file_returned_early() {
		if ( ! is_file( WP_PLUGIN_DIR . '/' . Import\IMPORTER_PLUGIN ) ) {
			$this->markTestSkipped( 'The WordPress Importer is needed.' );
		}
		// The test site loads the importer as a plugin, without WP_LOAD_IMPORTERS.
		$this->assertInstanceOf( 'WP_Import', Import\new_importer() );
		$this->assertInstanceOf( 'WP_Import', Import\new_importer(), 'Twice in a request.' );
	}

	public function test_import_links_follow_the_importer_state() {
		$active   = function ( $plugins ) {
			return array_merge( (array) $plugins, array( Import\IMPORTER_PLUGIN ) );
		};
		$inactive = function ( $plugins ) {
			return array_values( array_diff( (array) $plugins, array( Import\IMPORTER_PLUGIN ) ) );
		};

		add_filter( 'option_active_plugins', $active );
		$this->assertTrue( Import\importer_active() );
		$this->assertSame( admin_url( 'admin.php?import=' . Import\IMPORTER_ID ), Import\importer_url() );
		remove_filter( 'option_active_plugins', $active );

		// On a network, a plugin can also be active network-wide.
		$network = function ( $plugins ) {
			unset( $plugins[ Import\IMPORTER_PLUGIN ] );
			return $plugins;
		};
		add_filter( 'option_active_plugins', $inactive );
		add_filter( 'site_option_active_sitewide_plugins', $network );
		$this->assertFalse( Import\importer_active() );
		$this->assertSame( admin_url( 'import.php' ), Import\importer_url() );
		remove_filter( 'option_active_plugins', $inactive );
		remove_filter( 'site_option_active_sitewide_plugins', $network );
	}
}
