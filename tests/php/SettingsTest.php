<?php
/**
 * Tests for the settings page and the copies table.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Copies;
use Eizport\Settings;

/**
 * Settings tests.
 */
class SettingsTest extends Test_Case {

	/**
	 * Restores the user and the option filter.
	 */
	protected function tear_down() {
		remove_all_filters( 'pre_option_' . Settings\OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Makes get_option() return settings without touching the database.
	 *
	 * @param mixed $value Stored value.
	 */
	private function stored( $value ) {
		remove_all_filters( 'pre_option_' . Settings\OPTION );
		add_filter(
			'pre_option_' . Settings\OPTION,
			function () use ( $value ) {
				return $value;
			}
		);
	}

	public function test_lifetime_comes_from_the_choices() {
		$defaults = Settings\defaults();
		$this->assertSame( $defaults, Settings\sanitize( null ) );
		$this->assertSame( $defaults, Settings\sanitize( array( 'copy_lifetime' => '5' ) ) );
		$this->assertSame( WEEK_IN_SECONDS, Settings\sanitize( array( 'copy_lifetime' => (string) WEEK_IN_SECONDS ) )['copy_lifetime'] );
		$this->assertTrue( Settings\sanitize( array( 'classic_ui' => '1' ) )['classic_ui'] );
		$this->assertTrue( Settings\sanitize( array( 'copy_lifetime' => HOUR_IN_SECONDS ) )['replace_export'], 'Tools → Export is replaced by default, also for settings saved before.' );
		$this->assertFalse( Settings\sanitize( array( 'replace_export' => false ) )['replace_export'] );
		$this->assertTrue( Settings\sanitize( array( 'copy_lifetime' => HOUR_IN_SECONDS ) )['style_import'], 'The importer is styled by default.' );
		$this->assertFalse( Settings\sanitize( array( 'style_import' => false ) )['style_import'] );
		$this->assertTrue( Settings\sanitize( array( 'copy_lifetime' => HOUR_IN_SECONDS ) )['import_direct'], 'Tools → Import opens the WordPress Importer by default.' );
		$this->assertFalse( Settings\sanitize( array( 'import_direct' => false ) )['import_direct'] );
		$this->assertArrayNotHasKey( 'experimental_ui', Settings\sanitize( array( 'experimental_ui' => '1' ) ), 'Unknown keys are dropped.' );
		$this->assertTrue( Settings\sanitize( array() )['content_files'], 'Content as .html files is on by default.' );
		$this->assertFalse( Settings\sanitize( array( 'content_files' => false ) )['content_files'] );
		$this->assertSame( 'site', Settings\sanitize( array( 'defaults_scope' => 'site' ) )['defaults_scope'] );
		$this->assertSame( 'user', Settings\sanitize( array( 'defaults_scope' => 'everyone' ) )['defaults_scope'] );
		$this->assertSame( 'export', Settings\sanitize( array( 'linked_options' => 'export' ) )['linked_options'] );
		$this->assertSame( 'both', Settings\sanitize( array( 'linked_options' => 'sometimes' ) )['linked_options'] );
		$this->assertFalse( Settings\sanitize( array() )['plugin_import_again'], 'A plugin export doesn\'t offer Import again by default.' );
		$this->assertTrue( Settings\sanitize( array( 'plugin_import_again' => '1' ) )['plugin_import_again'], 'The setting is kept when saved.' );

		$this->stored( array( 'copy_lifetime' => HOUR_IN_SECONDS ) );
		$this->assertSame( HOUR_IN_SECONDS, Copies\lifetime() );
		$this->stored( 'garbage' );
		$this->assertSame( DAY_IN_SECONDS, Copies\lifetime() );

		$this->assertSame( 0, Settings\sanitize( array( 'copy_lifetime' => '0' ) )['copy_lifetime'] );
		$this->stored( array( 'copy_lifetime' => 0 ) );
		$this->assertSame( 0, Copies\lifetime(), 'Until deleted.' );
		$this->assertSame( 'until deleted', Copies\lifetime_label() );
	}

	public function test_plugin_row_links_to_the_settings() {
		$this->assertSame( array( 'x' ), Settings\action_links( array( 'x' ) ) );

		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		$links = Settings\action_links( array( 'x' ) );
		$this->assertStringContainsString( 'plugins.php?page=eizport-settings-wp-admin', $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );

		// Only Settings: importing starts from Tools → Import, with the importer active or not.
		$hook     = 'plugin_action_links_' . plugin_basename( dirname( __DIR__, 2 ) . '/eizport.php' );
		$importer = \Eizport\Import\IMPORTER_PLUGIN;
		$active   = function ( $plugins ) use ( $importer ) {
			return array_merge( (array) $plugins, array( $importer ) );
		};
		$inactive = function ( $plugins ) use ( $importer ) {
			return array_values( array_diff( (array) $plugins, array( $importer ) ) );
		};
		foreach ( array( $active, $inactive ) as $state ) {
			add_filter( 'option_active_plugins', $state );
			$row = apply_filters( $hook, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's plugin_action_links_{$plugin_file}. // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's plugin_action_links_{$plugin_file}.
			remove_filter( 'option_active_plugins', $state );
			$this->assertCount( 1, $row );
			$this->assertStringContainsString( '>Settings<', $row[0] );
		}
		wp_set_current_user( 0 );
	}

	public function test_update_merges_and_sanitizes() {
		$this->stored(
			array(
				'copy_lifetime'   => WEEK_IN_SECONDS,
				'classic_ui'      => true,
				'experimental_ui' => true,
			)
		);
		$written = null;
		$keep    = function ( $value, $old ) use ( &$written ) {
			$written = $value;
			return $old; // Nothing reaches the database.
		};
		add_filter( 'pre_update_option_' . Settings\OPTION, $keep, 10, 2 );

		Settings\update(
			array(
				'copy_lifetime' => '5',
				'unknown'       => 'x',
			)
		);
		remove_filter( 'pre_update_option_' . Settings\OPTION, $keep, 10 );

		$this->assertSame(
			array_merge(
				Settings\defaults(),
				array( 'classic_ui' => true )
			),
			$written,
			'An unknown lifetime falls back to a day; other settings stay.'
		);
	}

	public function test_page_has_no_menu_item() {
		if ( ! function_exists( Settings\RENDER ) ) {
			$this->markTestSkipped( 'The settings page isn\'t built.' );
		}
		global $submenu, $_registered_pages, $_parent_pages;
		$saved = array( $submenu, $_registered_pages, $_parent_pages );
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		$submenu = array( 'plugins.php' => array( array( 'Installed Plugins', 'activate_plugins', 'plugins.php' ) ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.

		Settings\add_page();
		$items        = wp_list_pluck( $submenu['plugins.php'], 2 );
		$registered   = isset( $_registered_pages[ get_plugin_page_hookname( Settings\SLUG, 'plugins.php' ) ] );
		$hooked       = has_filter( 'submenu_file', 'Eizport\Settings\submenu_file' );
		$_GET['page'] = Settings\SLUG;
		$current      = Settings\submenu_file( null );
		unset( $_GET['page'] );
		$elsewhere = Settings\submenu_file( 'import.php' );
		remove_filter( 'submenu_file', 'Eizport\Settings\submenu_file' );
		list( $submenu, $_registered_pages, $_parent_pages ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores them.

		$this->assertSame( array( 'plugins.php' ), $items, 'No menu item of its own.' );
		$this->assertTrue( $registered, 'The page still opens.' );
		$this->assertNotFalse( $hooked );
		$this->assertSame( 'plugins.php', $current, 'Installed Plugins is highlighted.' );
		$this->assertSame( 'import.php', $elsewhere );
	}
}
