<?php
/**
 * Tests for the files an import reads and the addresses an export writes.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Bundle;
use Eizport\Playground;

/**
 * Files security tests.
 */
class FilesSecurityTest extends Test_Case {

	public function test_a_zip_entry_is_counted_by_what_it_inflates_to() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$zip  = $this->temp_file();
		$file = new \ZipArchive();
		$file->open( $zip, \ZipArchive::OVERWRITE );
		$file->addFromString( 'big.txt', str_repeat( 'a', 300000 ) );
		$file->addFromString( 'small.txt', 'ok' );
		$file->close();

		$file->open( $zip );
		$dir = Bundle\create_folder();
		$this->assertFalse( Bundle\copy_zip_entry( $file, (int) $file->locateName( 'big.txt' ), $dir . '/big.txt', 100000 ), 'Over the budget: refused.' );
		$this->assertFileDoesNotExist( $dir . '/big.txt', 'And nothing left half written.' );
		$this->assertSame( 2, Bundle\copy_zip_entry( $file, (int) $file->locateName( 'small.txt' ), $dir . '/small.txt', 100000 ) );
		$this->assertSame( 'ok', file_get_contents( $dir . '/small.txt' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file->close();
		Bundle\delete_folder( $dir );
	}

	public function test_the_playground_landing_page_stays_on_the_site() {
		$this->assertSame( '/wp-admin/edit.php?post_type=page', Playground\landing_page( '/wp-admin/edit.php?post_type=page' ) );
		foreach ( array( '//evil.test', '/\\evil.test', '/a\\b', "/a\nb", 'https://evil.test/', 'evil' ) as $choice ) {
			$this->assertSame( '/', Playground\landing_page( $choice ), var_export( $choice, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}
}
