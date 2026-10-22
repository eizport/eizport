<?php
/**
 * Tests for the Playground copies.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Copies;
use Eizport\Files;

/**
 * Playground copies tests.
 */
class CopiesTest extends Test_Case {

	/**
	 * Copies go to a temporary uploads folder, not the site's.
	 */
	protected function set_up() {
		parent::set_up();
		$this->sandbox_uploads();
	}

	/**
	 * Keeps a copy of a small .zip, removed after the test.
	 *
	 * @param string $download Download name.
	 * @return string Copy name.
	 */
	private function keep( $download = 'site.WordPress.2026-10-10.zip' ) {
		$name = Copies\keep( $this->temp_file( 'zip bytes' ), $download );
		$this->remove_later( Files\tmp_path() . '/' . $name );

		return $name;
	}

	public function test_copy_goes_to_uploads_eizport_with_a_random_name() {
		$name = $this->keep();

		$this->assertMatchesRegularExpression( '/^site\.WordPress\.2026-10-10-[A-Za-z0-9]{16}\.zip$/', $name );
		$this->assertSame( 'zip bytes', file_get_contents( Files\tmp_path() . '/' . $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertFileExists( Files\tmp_path() . '/index.php' );
		$this->assertFileExists( dirname( Files\tmp_path() ) . '/index.php' );
		$this->assertNotSame( $name, $this->keep(), 'Each copy gets its own token.' );
		$this->assertMatchesRegularExpression( '/^[a-z-]+-[A-Za-z0-9]{16}\.zip$/', $this->keep( '.zip' ) );
	}

	public function test_copy_names() {
		$this->assertTrue( Copies\is_copy_name( 'site-abcdefghijklmnop.zip' ) );
		$this->assertFalse( Copies\is_copy_name( 'site.zip' ), 'No token.' );
		$this->assertFalse( Copies\is_copy_name( '../site-abcdefghijklmnop.zip' ) );
		$this->assertFalse( Copies\is_copy_name( 'a/site-abcdefghijklmnop.zip' ) );
		$this->assertFalse( Copies\is_copy_name( '.site-abcdefghijklmnop.zip' ) );
		$this->assertFalse( Copies\is_copy_name( 'site-abcdefghijklmnop.php' ) );
		$this->assertFalse( Copies\is_copy_name( array() ) );
		$this->assertSame( '', Copies\path( 'index.php' ) );
	}

	public function test_responses() {
		$name = $this->keep();

		$this->assertSame( 200, Copies\response( $name, 'GET' )['status'] );
		$this->assertSame( Files\tmp_path() . '/' . $name, Copies\response( $name, 'GET' )['path'] );
		$this->assertSame( 200, Copies\response( $name, 'HEAD' )['status'] );
		$this->assertSame( 204, Copies\response( $name, 'OPTIONS' )['status'], 'CORS preflight.' );
		$this->assertSame( 404, Copies\response( $name, 'POST' )['status'] );
		$this->assertSame( 404, Copies\response( 'other-abcdefghijklmnop.zip', 'GET' )['status'] );
		$this->assertSame( 404, Copies\response( 'index.php', 'GET' )['status'] );
	}

	public function test_links_and_command() {
		$url = Copies\url( 'site-abcdefghijklmnop.zip' );

		$this->assertSame( home_url( '/?eizport_playground=site-abcdefghijklmnop.zip' ), $url );
		$this->assertSame( 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $url ), Copies\playground_url( 'site-abcdefghijklmnop.zip' ) );
		$this->assertSame( 'npx @wp-playground/cli@latest server --blueprint=./site.zip', Copies\command( 'site.zip' ) );
	}

	public function test_tmp_is_deleted_only_when_empty() {
		$copy = $this->keep();
		$this->assertFalse( \Eizport\Files\delete_tmp_if_empty() );
		$this->assertFileExists( Copies\path( $copy ) );

		Copies\delete_copies( array( $copy ) );
		$left = is_dir( Files\tmp_path() ) ? array_diff( scandir( Files\tmp_path() ), array( '.', '..', 'index.php' ) ) : array();
		$this->assertSame( (bool) $left, is_dir( Files\tmp_path() ), 'tmp/ stays only while something else is in it.' );
		$this->assertDirectoryExists( dirname( Files\tmp_path() ), 'uploads/eizport/ stays.' );
	}

	public function test_copies_listed_and_deleted() {
		$first  = $this->keep();
		$second = $this->keep();

		$this->assertEqualsCanonicalizing( array( $first, $second ), array_intersect( wp_list_pluck( Copies\copies(), 'name' ), array( $first, $second ) ) );
		$this->assertSame( 0, Copies\delete_copies( array( '../index.php', 'index.php' ) ) );
		$this->assertSame( 1, Copies\delete_copies( array( $first ) ) );
		$this->assertFileDoesNotExist( Files\tmp_path() . '/' . $first );
		$this->assertFileExists( Files\tmp_path() . '/' . $second );
	}

	public function test_expired_copies_and_abandoned_imports_are_cleaned_up() {
		$copy   = $this->keep();
		$folder = \Eizport\Bundle\create_folder();
		$this->remove_later( $folder );
		$later = time() + DAY_IN_SECONDS + 1;

		$this->assertSame( 200, Copies\response( $copy, 'GET' )['status'] );
		$this->assertSame( 0, Copies\cleanup(), 'Nothing has expired yet.' );
		$this->assertFileExists( Copies\path( $copy ) );

		$this->assertTrue( Copies\is_expired( Copies\path( $copy ), $later ) );
		$this->assertGreaterThanOrEqual( 2, Copies\cleanup( $later ) );
		$this->assertFileDoesNotExist( Files\tmp_path() . '/' . $copy );
		$this->assertDirectoryDoesNotExist( $folder );
		$this->assertDirectoryDoesNotExist( Files\tmp_path(), 'Nothing left: tmp/ goes as a whole.' );

		add_filter(
			'eizport_copy_lifetime',
			function () {
				return 1;
			}
		);
		$this->assertSame( MINUTE_IN_SECONDS, Copies\lifetime(), 'At least a minute.' );
		remove_all_filters( 'eizport_copy_lifetime' );
	}

	public function test_copies_kept_until_deleted_never_expire_but_old_imports_go() {
		add_filter( 'eizport_copy_lifetime', '__return_zero' );
		$copy   = $this->keep();
		$folder = \Eizport\Bundle\create_folder();
		$this->remove_later( $folder );
		$later = time() + 10 * YEAR_IN_SECONDS;

		$this->assertFalse( Copies\is_expired( Copies\path( $copy ), $later ) );
		Copies\cleanup( $later );
		$this->assertFileExists( Copies\path( $copy ) );
		$this->assertDirectoryDoesNotExist( $folder, 'Abandoned imports still go after a day.' );
		$this->assertSame( 200, Copies\response( $copy, 'GET' )['status'] );
		remove_all_filters( 'eizport_copy_lifetime' );
	}

	public function test_crawlers_are_kept_away() {
		$robots = Copies\robots_txt( "User-agent: *\nDisallow: /wp-admin/\n" );
		// The site's own uploads path (/wp-content/uploads/, or /b/wp-content/uploads/sites/2/ on a subsite).
		$folder = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) . '/eizport/';
		$this->assertStringContainsString( "User-agent: *\nDisallow: {$folder}\nDisallow: /*?eizport_playground=\nDisallow: /wp-admin/", $robots );
		$this->assertMatchesRegularExpression( '#\nUser-agent: \*\nDisallow: /\S*/eizport/\nDisallow: /\*\?eizport_playground=#', Copies\robots_txt( "User-agent: Googlebot\nDisallow:\n" ), 'Its own group when there is none.' );

		\Eizport\Files\tmp_folder();
		$htaccess = dirname( \Eizport\Files\tmp_path() ) . '/.htaccess';
		$this->assertFileExists( $htaccess );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $htaccess ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
