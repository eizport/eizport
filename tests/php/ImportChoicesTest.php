<?php
/**
 * Tests for what whoever imports or exports decides, not the file: options
 * outside the groups, secrets, existing items, the network's code.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Appearance;
use Eizport\Bundle;
use Eizport\Import;
use Eizport\Options;
use Eizport\Playground;

/**
 * Import and export choices tests.
 */
class ImportChoicesTest extends Test_Case {

	/**
	 * Logged out after each test.
	 */
	protected function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_options_outside_the_groups_wait_for_approval() {
		$data = array(
			'options' => array(
				'blogname'                          => 'Delivered',
				'page_on_front'                     => 4,
				'theme_mods_' . get_stylesheet()    => array( 'header_textcolor' => '000000' ),
				'widget_text'                       => array(),
				'eizport_test_plugin_setting'       => 'x',
				'siteurl'                           => 'https://evil.test',
				'eizport_test_other_plugin_setting' => 'y',
			),
		);

		$this->assertSame( array( 'eizport_test_plugin_setting', 'eizport_test_other_plugin_setting' ), Bundle\other_options( $data ), 'Not the groups\' nor the ones never set.' );
		$allowed = Bundle\with_allowed_options( $data );
		$this->assertSame( array( 'blogname', 'page_on_front', 'theme_mods_' . get_stylesheet(), 'widget_text' ), array_keys( $allowed['options'] ) );
		if ( ! is_multisite() ) {
			$approved = Bundle\with_allowed_options( $data, array( 'eizport_test_plugin_setting' ) );
			$this->assertArrayHasKey( 'eizport_test_plugin_setting', $approved['options'] );
			$this->assertArrayNotHasKey( 'eizport_test_other_plugin_setting', $approved['options'] );
		} else {
			$this->assertArrayNotHasKey( 'eizport_test_plugin_setting', Bundle\with_allowed_options( $data, array( 'eizport_test_plugin_setting' ) )['options'], 'On a network, a site admin approves none.' );
		}
	}

	public function test_secrets_are_never_exported() {
		foreach ( array( 'wp_mail_smtp', 'my_maps_api_key', 'stripe_secret', 'gateway_token', 'acme_license_key', 'jetpack_private_options', 'smtp_password' ) as $name ) {
			$this->assertTrue( Options\is_secret( $name ), $name );
			$this->assertTrue( Options\is_denied( $name ), $name );
		}
		foreach ( array( 'blogname', 'page_on_front', 'wp_page_for_privacy_policy', 'comment_moderation', 'header_textcolor' ) as $name ) {
			$this->assertFalse( Options\is_secret( $name ), $name );
		}

		$let = function ( $secret, $name ) {
			return 'my_maps_api_key' === $name ? false : $secret;
		};
		add_filter( 'eizport_is_secret', $let, 10, 2 );
		$this->assertFalse( Options\is_secret( 'my_maps_api_key' ), 'A plugin lets one through.' );
		remove_filter( 'eizport_is_secret', $let );

		$name  = 'theme_mods_' . get_stylesheet();
		$saved = get_option( $name );
		set_theme_mod( 'eizport_test_maps_api_key', 'k3y' );
		set_theme_mod( 'eizport_test_color', '#123456' );
		$mods = Appearance\theme_mods();
		update_option( $name, $saved );
		$this->assertArrayNotHasKey( 'eizport_test_maps_api_key', $mods );
		$this->assertSame( '#123456', $mods['eizport_test_color'] );

		$widgets = get_option( 'widget_eizport_test' );
		update_option(
			'widget_eizport_test',
			array(
				7 => array(
					'title'     => 'Feed',
					'api_token' => 's3cret',
				),
			)
		);
		$widget = Appearance\widget( 'eizport_test-7' );
		false === $widgets ? delete_option( 'widget_eizport_test' ) : update_option( 'widget_eizport_test', $widgets );
		$this->assertSame( array( 'title' => 'Feed' ), $widget, 'Its token stays home.' );
	}

	public function test_the_network_s_code_is_for_who_manages_it() {
		if ( ! is_multisite() ) {
			$this->assertFalse( Playground\network_code_limited(), 'A single site: every installed plugin and theme.' );
			return;
		}
		$this->assertTrue( Playground\network_code_limited(), 'Logged out on a network: limited.' );
		$this->assertSame( array(), Playground\mu_plugin_choices() );
		$active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$this->assertSame( array(), array_diff( array_keys( Playground\plugin_choices() ), $active ), 'Only the plugins active on this site.' );
	}

	/**
	 * Runs alone: the importer is loaded once per process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_file_never_decides_to_update_existing_items() {
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_name'    => 'eizport-choices-page',
				'post_title'   => 'Choices page',
				'post_content' => 'Ours',
				'post_status'  => 'publish',
			)
		);
		$wxr  = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>http://source.test</wp:base_site_url><wp:base_blog_url>http://source.test</wp:base_blog_url>'
			. '<item><title>Choices page</title><dc:creator><![CDATA[admin]]></dc:creator><content:encoded><![CDATA[Theirs]]></content:encoded><excerpt:encoded><![CDATA[]]></excerpt:encoded><wp:post_id>9123</wp:post_id><wp:post_date>2001-01-01 00:00:00</wp:post_date><wp:post_name>eizport-choices-page</wp:post_name><wp:status>publish</wp:status><wp:post_parent>0</wp:post_parent><wp:post_type>page</wp:post_type></item></channel></rss>';
		$file = $this->temp_file(
			gzencode(
				$this->tar(
					array(
						'manifest.json' => '{"format":"wxrz","version":2,"requires":"1.1.0","update_existing":true,"wxr":["site.xml"],"files":{}}',
						'site.xml'      => $wxr,
					)
				)
			)
		);

		ob_start();
		$result = Import\import_file( $file );
		ob_end_clean();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'Ours', get_post( $page )->post_content, 'The manifest alone doesn\'t update it.' );
		foreach ( get_posts(
			array(
				'post_type'   => 'page',
				'title'       => 'Choices page',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		) as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
