<?php
/**
 * What makes the plugin work site by site on a network: the saved defaults
 * per site, the upload path never moved, the uninstall of one site. The
 * network itself is checked end to end (a Playground network).
 *
 * Users' data and options change inside a transaction rolled back after
 * each test.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Defaults;

/**
 * Multisite tests.
 */
class MultisiteTest extends Test_Case {

	/**
	 * Opens the transaction, as an administrator.
	 */
	protected function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator' ) )[0]->ID );
		require_once dirname( __DIR__, 2 ) . '/includes/apply-options.php';
		require_once dirname( __DIR__, 2 ) . '/includes/uninstall-site.php';
	}

	/**
	 * Rolls it back.
	 */
	protected function tear_down() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_a_users_defaults_are_kept_per_site() {
		global $wpdb;
		$user = get_current_user_id();
		$this->stored_settings( array( 'defaults_scope' => 'user' ) );
		delete_user_meta( $user, Defaults\KEY );

		Defaults\save( array( 'settings' => array( 'filename' => 'per-site' ) ) );
		$this->assertSame( 'per-site', get_user_meta( $user, $wpdb->get_blog_prefix() . Defaults\KEY, true )['settings']['filename'], 'A user option: the key has the site\'s prefix.' );
		$this->assertSame( '', get_user_meta( $user, Defaults\KEY, true ), 'Not the user meta every site shares.' );
		$this->assertSame( 'per-site', Defaults\get()['settings']['filename'] );

		Defaults\clear();
		$this->assertSame( array(), Defaults\get() );
	}

	public function test_defaults_saved_before_move_to_the_site() {
		global $wpdb;
		if ( ! is_main_site() ) {
			$this->markTestSkipped( 'Old defaults move to the main site only.' );
		}
		$user = get_current_user_id();
		$this->stored_settings( array( 'defaults_scope' => 'user' ) );
		delete_user_option( $user, Defaults\KEY );
		update_user_meta( $user, Defaults\KEY, array( 'settings' => array( 'filename' => 'older' ) ) );

		$this->assertSame( 'older', Defaults\get()['settings']['filename'] );
		$this->assertSame( '', get_user_meta( $user, Defaults\KEY, true ), 'The old user meta is gone.' );
		$this->assertSame( 'older', get_user_meta( $user, $wpdb->get_blog_prefix() . Defaults\KEY, true )['settings']['filename'] );
	}

	public function test_the_upload_path_is_never_exported_nor_set() {
		foreach ( array( 'upload_path', 'upload_url_path' ) as $name ) {
			$this->assertTrue( eizport_never_set_option( $name ), $name );
			$this->assertTrue( \Eizport\Options\is_denied( $name ), $name . ' is never exported either.' );
		}
		$this->assertArrayNotHasKey( 'upload_path', eizport_options_to_apply( array( 'options' => array( 'upload_path' => '/elsewhere' ) ), array(), array() ) );
	}

	public function test_uninstalling_a_site_removes_its_data() {
		global $wpdb;
		$user = get_current_user_id();
		update_option( 'eizport_export_defaults', array( 'x' => 1 ) );
		update_option( 'eizport_bundle_plugins_before', array( 'x' ) );
		update_user_option( $user, 'eizport_export_defaults', array( 'x' => 1 ) );

		eizport_uninstall_site( false );

		$this->assertFalse( get_option( 'eizport_export_defaults' ) );
		$this->assertFalse( get_option( 'eizport_bundle_plugins_before' ) );
		$this->assertSame( '', get_user_meta( $user, $wpdb->get_blog_prefix() . 'eizport_export_defaults', true ) );
		$this->assertFalse( wp_next_scheduled( 'eizport_cleanup' ) );
	}

	/**
	 * Settings as stored, for the test.
	 *
	 * @param array $settings Settings.
	 */
	private function stored_settings( $settings ) {
		update_option( 'eizport_settings', array_merge( (array) get_option( 'eizport_settings', array() ), $settings ) );
		wp_cache_delete( 'eizport_settings', 'options' );
	}
}
