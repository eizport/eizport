<?php
/**
 * Tests for the Playground .zip.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\Bundle;
use Eizport\Playground;
use Eizport\Tar;
use Eizport\Wxrz;

/**
 * Playground tests.
 */
class PlaygroundTest extends Test_Case {

	/**
	 * A child theme of the active theme, created for the test.
	 *
	 * @var string
	 */
	private $child = 'eizport-test-child';

	/**
	 * Creates the child theme.
	 */
	protected function set_up() {
		parent::set_up();
		$dir = get_theme_root() . '/' . $this->child;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: EIZ Test Child\nTemplate: " . get_template() . "\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->remove_later( $dir );
		wp_clean_themes_cache();
		// WordPress's update check, as if nothing chosen came from the WordPress.org directory.
		$this->directory( array(), array() );
	}

	/**
	 * Fakes what WordPress's update check knows (no database write, no request).
	 *
	 * @param array $plugins Plugin file => slug, from the directory.
	 * @param array $themes  Stylesheet => slug, from the directory.
	 * @param array $latest  Plugin file or stylesheet => the directory's version.
	 */
	private function directory( $plugins, $themes, $latest = array() ) {
		remove_all_filters( 'pre_site_transient_update_plugins' );
		remove_all_filters( 'pre_site_transient_update_themes' );
		$checked_plugins = array_fill_keys( array_keys( \Eizport\Playground\plugin_choices() ), '1' );
		$checked_themes  = array_fill_keys( array_keys( wp_get_themes( array( 'errors' => null ) ) ), '1' );
		add_filter(
			'pre_site_transient_update_plugins',
			function () use ( $plugins, $checked_plugins, $latest ) {
				$no_update = array();
				foreach ( $plugins as $file => $slug ) {
					$no_update[ $file ] = (object) array_filter(
						array(
							'id'          => 'w.org/plugins/' . $slug,
							'slug'        => $slug,
							'new_version' => $latest[ $file ] ?? '',
						)
					);
				}
				return (object) array(
					'checked'   => $checked_plugins,
					'response'  => array(),
					'no_update' => $no_update,
				);
			}
		);
		add_filter(
			'pre_site_transient_update_themes',
			function () use ( $themes, $checked_themes, $latest ) {
				$no_update = array();
				foreach ( $themes as $stylesheet => $slug ) {
					$no_update[ $stylesheet ] = array_filter(
						array(
							'theme'       => $slug,
							'package'     => 'https://downloads.wordpress.org/theme/' . $slug . '.zip',
							'new_version' => $latest[ $stylesheet ] ?? '',
						)
					);
				}
				return (object) array(
					'checked'   => $checked_themes,
					'response'  => array(),
					'no_update' => $no_update,
				);
			}
		);
	}

	/**
	 * Removes the blueprint filter and the child theme from the caches.
	 */
	protected function tear_down() {
		wp_set_current_user( 0 );
		remove_all_filters( 'eizport_playground_blueprint' );
		remove_all_filters( 'wp_import_post_data_raw' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_site_transient_update_plugins' );
		remove_all_filters( 'pre_site_transient_update_themes' );
		remove_all_filters( 'eizport_playground_from_directory' );
		parent::tear_down();
		wp_clean_themes_cache();
	}

	public function test_child_themes_bring_their_parent() {
		$this->assertSame( array( $this->child, get_template() ), Playground\with_parent_themes( array( $this->child, 'no-such-theme' ) ) );
		$this->assertSame( array( get_template() ), Playground\with_parent_themes( array( get_template(), get_template() ) ) );
	}

	public function test_theme_to_activate() {
		$this->assertSame( $this->child, Playground\theme_to_activate( array( $this->child ) ) );
		$this->assertSame( get_stylesheet(), Playground\theme_to_activate( array( get_stylesheet(), $this->child ) ) );
		$this->assertSame( '', Playground\theme_to_activate( array() ) );
	}

	public function test_blueprint_installs_themes_parent_first_and_plugins() {
		$steps = Playground\blueprint( 'site.wxrz', array( $this->child ), array( 'akismet/akismet.php', 'hello.php' ) )['steps'];

		$this->assertSame( '/plugins/akismet.zip', $steps[0]['pluginData']['path'] );
		$this->assertSame( '/plugins/hello.zip', $steps[1]['pluginData']['path'] );
		$this->assertSame( '/themes/' . get_template() . '.zip', $steps[2]['themeData']['path'] );
		$this->assertFalse( $steps[2]['options']['activate'] );
		$this->assertSame( '/themes/' . $this->child . '.zip', $steps[3]['themeData']['path'] );
		$this->assertTrue( $steps[3]['options']['activate'] );
		$this->assertSame( 'importWxr', end( $steps )['step'] );

		// This site's theme chosen too: it is activated, after the child it is the parent of.
		$steps = Playground\blueprint( 'site.wxrz', array( $this->child, get_stylesheet() ) )['steps'];
		$this->assertSame( '/themes/' . $this->child . '.zip', $steps[0]['themeData']['path'] );
		$this->assertSame( '/themes/' . get_stylesheet() . '.zip', $steps[1]['themeData']['path'] );
		$this->assertTrue( $steps[1]['options']['activate'] );
	}

	public function test_plugins_are_active_or_inactive_as_on_this_site() {
		$active = function ( $plugins ) {
			return array_merge( array_diff( (array) $plugins, array( 'hello.php' ) ), array( 'akismet/akismet.php' ) );
		};
		add_filter( 'option_active_plugins', $active );
		$steps = Playground\blueprint( 'site.wxrz', array(), array( 'akismet/akismet.php', 'hello.php' ) )['steps'];
		remove_filter( 'option_active_plugins', $active );

		$this->assertTrue( $steps[0]['options']['activate'], 'Active here.' );
		$this->assertFalse( $steps[1]['options']['activate'], 'Inactive here: installed, not activated.' );
	}

	public function test_where_playground_opens() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction, rolled back below.
		$parent = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Eizport shop',
				'post_name'   => 'eizport-shop',
				'post_status' => 'publish',
			)
		);
		$child  = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Eizport coffee',
				'post_name'   => 'eizport-coffee',
				'post_parent' => $parent,
				'post_status' => 'publish',
			)
		);
		$draft  = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Eizport draft',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( '/', Playground\landing_page( 'home' ) );
		$this->assertSame( '/wp-admin/', Playground\landing_page( 'admin' ) );
		$this->assertSame( '/?pagename=eizport-shop/eizport-coffee', Playground\landing_page( array( 'page' => $child ) ) );
		$this->assertSame( '/?pagename=eizport-shop', Playground\landing_page( 'page:' . $parent ) );
		$this->assertSame( '/', Playground\landing_page( array( 'page' => $draft ) ), 'Not published: the home page.' );
		$this->assertSame( '/shop/?ref=demo', Playground\landing_page( '/shop/?ref=demo#top' ) );
		$this->assertSame( '/', Playground\landing_page( '//evil.example/' ), 'Not a path.' );
		$this->assertSame( '/', Playground\landing_page( 'https://evil.example/' ) );
		$this->assertSame( '/wp-admin/', Playground\blueprint( 'site.wxrz', array(), array(), array(), array(), null, '/wp-admin/' )['landingPage'] );
		$this->assertSame( '/', Playground\blueprint( 'site.wxrz' )['landingPage'] );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
	}

	public function test_what_the_directory_has_comes_from_there_in_the_same_version() {
		$this->directory( array( 'akismet/akismet.php' => 'akismet' ), array( get_template() => get_template() ) );
		$akismet = Playground\plugin_choices()['akismet/akismet.php']['Version'] ?? '';
		if ( '' === $akismet ) {
			$this->markTestSkipped( 'Akismet is needed.' );
		}

		$steps = Playground\blueprint( 'site.wxrz', array( $this->child ), array( 'akismet/akismet.php', 'eizport/eizport.php' ) )['steps'];

		$this->assertSame(
			array(
				'resource' => 'wordpress.org/plugins',
				'slug'     => 'akismet.' . $akismet . '.zip',
			),
			$steps[0]['pluginData'],
			'The installed version, not the newest.'
		);
		$this->assertSame( 'bundled', $steps[1]['pluginData']['resource'], 'Not in the directory: in the .zip.' );
		$this->assertSame( 'wordpress.org/themes', $steps[2]['themeData']['resource'], 'The parent from the directory, first.' );
		$this->assertSame( get_template() . '.' . wp_get_theme( get_template() )->get( 'Version' ) . '.zip', $steps[2]['themeData']['slug'] );
		$this->assertSame( '/themes/' . $this->child . '.zip', $steps[3]['themeData']['path'], 'The custom child in the .zip, activated.' );
		$this->assertTrue( $steps[3]['options']['activate'] );

		// The .zip holds only the child theme; the directory's come from there.
		if ( class_exists( 'ZipArchive' ) ) {
			$wxr = $this->temp_file( '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>' );
			$zip = Playground\build_zip( $wxr, 'site.xml', array( $this->child ), array( 'akismet/akismet.php' ) );
			$this->remove_later( $zip );
			$archive = new \ZipArchive();
			$archive->open( $zip );
			$this->assertNotFalse( $archive->locateName( 'themes/' . $this->child . '.zip' ) );
			$this->assertFalse( $archive->locateName( 'plugins/akismet.zip' ), 'Not in the .zip: from the directory.' );
			$this->assertFalse( $archive->locateName( 'themes/' . get_template() . '.zip' ) );
			$archive->close();
		}

		// A filter puts it back in the .zip.
		add_filter( 'eizport_playground_from_directory', '__return_empty_string' );
		$this->assertSame( '', Playground\directory_plugin_zip( 'akismet/akismet.php' ) );
	}

	public function test_only_versions_the_directory_has_come_from_there() {
		$this->assertSame( 'eizport.1.0.1.zip', Playground\directory_zip( 'eizport', '1.0.1', 'plugin', 'eizport/eizport.php', '1.0.1' ) );
		$this->assertSame( 'woocommerce.11.2.1.zip', Playground\directory_zip( 'woocommerce', '11.2.1', 'plugin', 'woocommerce/woocommerce.php', '11.3' ), 'An older release: still there.' );
		$this->assertSame( 'twentytwentyfive.1.3.zip', Playground\directory_zip( 'twentytwentyfive', '1.3', 'theme', 'twentytwentyfive' ), 'Not known: a release goes.' );
		foreach ( array( '1.1.0-dev', '2.0-beta1', '1.0.1-RC1', '1.0+build', 'trunk' ) as $version ) {
			$this->assertSame( '', Playground\directory_zip( 'eizport', $version, 'plugin', 'eizport/eizport.php', '1.0.1' ), $version . ': in the .zip.' );
		}
		$this->assertSame( '', Playground\directory_zip( 'eizport', '1.1.0', 'plugin', 'eizport/eizport.php', '1.0.1' ), 'Newer than the directory: in the .zip.' );
		$this->assertSame( '', Playground\directory_zip( 'twentytwentyfive', '1.4', 'theme', 'twentytwentyfive', '1.3' ) );
	}

	public function test_a_version_newer_than_the_directory_goes_in_the_zip() {
		$akismet = Playground\plugin_choices()['akismet/akismet.php']['Version'] ?? '';
		$theme   = (string) wp_get_theme( get_template() )->get( 'Version' );
		if ( '' === $akismet || '' === $theme ) {
			$this->markTestSkipped( 'Akismet and a versioned theme are needed.' );
		}
		$this->directory(
			array( 'akismet/akismet.php' => 'akismet' ),
			array( get_template() => get_template() ),
			array(
				'akismet/akismet.php' => '0.1',
				get_template()        => '0.1',
			)
		);

		$steps = Playground\blueprint( 'site.wxrz', array( get_template() ), array( 'akismet/akismet.php' ) )['steps'];

		$this->assertSame(
			array(
				'resource' => 'bundled',
				'path'     => '/plugins/akismet.zip',
			),
			$steps[0]['pluginData']
		);
		$this->assertSame( 'bundled', $steps[1]['themeData']['resource'] );
	}

	public function test_theme_entries_and_choices() {
		$this->assertSame( array( $this->child . '/style.css' ), array_keys( Playground\theme_entries( $this->child ) ) );
		$this->assertArrayHasKey( $this->child, Playground\theme_choices() );
		$this->assertSame( array(), Playground\other_plugin_entries( 'nope/nope.php' ) );
	}

	public function test_playground_never_asks_to_update_existing() {
		$this->assertFalse(
			\Eizport\Export\bundle_settings(
				array(
					'bundle'          => true,
					'blueprint'       => true,
					'update_existing' => true,
				)
			)['update_existing'],
			'Playground starts from a new site.'
		);
		$this->assertTrue(
			\Eizport\Export\bundle_settings(
				array(
					'bundle'          => true,
					'update_existing' => true,
				)
			)['update_existing']
		);
	}

	public function test_blueprint_imports_the_wxr() {
		$blueprint = Playground\blueprint( 'site.xml' );

		$this->assertSame(
			array(
				array(
					'step' => 'importWxr',
					'file' => array(
						'resource' => 'bundled',
						'path'     => '/site.xml',
					),
				),
			),
			$blueprint['steps']
		);

		add_filter(
			'eizport_playground_blueprint',
			function ( $blueprint ) {
				$blueprint['landingPage'] = '/wp-admin/';
				return $blueprint;
			}
		);
		$this->assertSame( '/wp-admin/', Playground\blueprint( 'site.xml' )['landingPage'] );
	}

	public function test_blueprint_serves_media_during_the_import_only() {
		$files = array( 'https://old.example/wp-content/uploads/2026/10/a.jpg' => 'uploads/2026/10/a.jpg' );
		$steps = Playground\blueprint( 'site.xml', array(), array(), $files )['steps'];

		$this->assertSame( array( 'mkdir', 'mkdir', 'writeFile', 'writeFile', 'writeFile', 'importWxr', 'rm', 'rmdir' ), wp_list_pluck( $steps, 'step' ) );
		$this->assertSame( array( dirname( Playground\MEDIA_PLUGIN ), Playground\MEDIA_DIR . '/uploads/2026/10' ), wp_list_pluck( array_splice( $steps, 0, 2 ), 'path' ) );
		$this->assertSame( Playground\MEDIA_DIR . '/uploads/2026/10/a.jpg', $steps[0]['path'] );
		$this->assertSame( '/uploads/2026/10/a.jpg', $steps[0]['data']['path'] );
		$this->assertSame( $files, json_decode( $steps[1]['data'], true ) );
		$this->assertSame( Playground\MEDIA_PLUGIN, $steps[2]['path'] );
		$this->assertSame( Playground\MEDIA_PLUGIN, $steps[4]['path'] );
		$this->assertSame( Playground\MEDIA_DIR, $steps[5]['path'] );
	}

	public function test_blueprint_writes_content_files_for_the_import() {
		$content = array(
			'about.html' => array(
				'id'   => 7,
				'type' => 'page',
				'slug' => 'about',
			),
		);
		$steps   = Playground\blueprint( 'site.xml', array(), array(), array(), $content )['steps'];

		$this->assertSame( array( 'mkdir', 'mkdir', 'writeFile', 'writeFile', 'writeFile', 'importWxr', 'rm', 'rmdir' ), wp_list_pluck( $steps, 'step' ) );
		$this->assertSame( Playground\MEDIA_DIR . '/post_content', $steps[1]['path'] );
		$this->assertSame( Playground\MEDIA_DIR . '/post_content/about.html', $steps[2]['path'] );
		$this->assertSame( '/post_content/about.html', $steps[2]['data']['path'] );
		$this->assertSame( Playground\MEDIA_DIR . '/content.json', $steps[3]['path'] );
		$this->assertSame( $content, json_decode( $steps[3]['data'], true ) );
		$this->assertSame( Playground\MEDIA_PLUGIN, $steps[4]['path'] );
	}

	public function test_media_plugin_puts_content_files_into_their_items() {
		$root = get_temp_dir() . 'eizport-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $root . '/mu-plugins' );
		wp_mkdir_p( $root . '/eizport-media/post_content' );
		$this->remove_later( $root );
		file_put_contents( $root . '/mu-plugins/eizport-media.php', Playground\media_plugin_code() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $root . '/eizport-media/content.json', '{"by-id.html":{"id":7,"type":"page","slug":"x"}}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $root . '/eizport-media/post_content/by-id.html', '<p>By ID</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $root . '/eizport-media/post_content/by-slug.html', '<p>By slug</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		include $root . '/mu-plugins/eizport-media.php';
		$item = function ( $id, $slug, $type = 'page' ) {
			// The WordPress Importer's filter, applied as WP_Import does.
			return apply_filters(
				'wp_import_post_data_raw', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WordPress Importer's.
				array(
					'post_id'      => $id,
					'post_name'    => $slug,
					'post_type'    => $type,
					'post_content' => 'WXR',
				)
			);
		};

		$this->assertSame( '<p>By ID</p>', $item( 7, 'other' )['post_content'] );
		$this->assertSame( '<p>By slug</p>', $item( 8, 'by-slug' )['post_content'] );
		$this->assertSame( 'WXR', $item( 9, 'missing' )['post_content'] );
		$this->assertSame( 'WXR', $item( 8, 'by-slug', 'attachment' )['post_content'] );
		$this->assertSame( 'WXR', $item( 8, '../by-slug' )['post_content'] );
	}

	public function test_media_plugin_code_is_valid_php() {
		$file = $this->temp_file( Playground\media_plugin_code() );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ), $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	public function test_zip_holds_the_entries() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is needed to read the .zip back.' );
		}
		$source = $this->temp_file( 'file contents' );
		$target = $this->temp_file();

		$this->assertTrue(
			Playground\create_zip(
				$target,
				array(
					'a/data.txt' => array( 'data' => 'some data' ),
					'b/file.txt' => array( 'path' => $source ),
				)
			)
		);

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $target ) );
		$this->assertSame( 'some data', $zip->getFromName( 'a/data.txt' ) );
		$this->assertSame( 'file contents', $zip->getFromName( 'b/file.txt' ) );
		$zip->close();
	}

	public function test_bundle_written_to_a_file_without_media() {
		$wxr = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $wxr, '<wp:attachment_url><![CDATA[' . wp_get_upload_dir()['baseurl'] . "/x.jpg]]></wp:attachment_url>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$target = $this->temp_file();
		$plain  = $this->temp_file();

		$this->assertTrue( Bundle\write_bundle( $target, $wxr, 'site.xml', false ) );
		$this->assertTrue( Wxrz\is_gzip( $target ) );
		$this->assertTrue( Wxrz\decompress( $target, $plain ) );

		$names = array();
		Tar\each_file(
			$plain,
			function ( $name ) use ( &$names ) {
				$names[] = $name;
			}
		);
		$this->assertSame( array( Bundle\MANIFEST, 'site.xml' ), $names );
	}

	/**
	 * Fake translation files in WP_LANG_DIR (the ones that weren't there are
	 * removed after the test).
	 *
	 * @param string[] $files Paths under WP_LANG_DIR.
	 */
	private function language_files( $files ) {
		foreach ( $files as $file ) {
			$path = WP_LANG_DIR . '/' . $file;
			if ( ! file_exists( $path ) ) {
				wp_mkdir_p( dirname( $path ) );
				file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$this->remove_later( $path );
			}
		}
	}

	public function test_the_language_playground_opens_in() {
		// Installed here (core keeps its list of files in a cache).
		$installed = function () {
			return array( 'pt_BR', 'de_DE' );
		};
		add_filter( 'get_available_languages', $installed );
		$site = function () {
			return 'pt_BR';
		};
		add_filter( 'locale', $site );
		// The user's own language (their "locale" meta), without writing it.
		$user = function ( $value, $id, $key ) {
			return 'locale' === $key ? array( 'de_DE' ) : $value;
		};
		add_filter( 'get_user_metadata', $user, 10, 3 );
		wp_set_current_user( 1 );

		$this->assertSame( 'pt_BR', Playground\language( 'site' ) );
		$this->assertSame( 'pt_BR', Playground\language( '' ), 'The site\'s by default.' );
		$this->assertSame( 'de_DE', Playground\language( 'user' ) );
		$this->assertSame( 'de_DE', Playground\language( 'de_DE' ) );
		$this->assertSame( '', Playground\language( 'en_US' ) );
		$this->assertSame( '', Playground\language( 'xx_YY' ), 'Not installed here: English.' );

		// The screens' choices: the site's, the user's and English.
		add_filter(
			'pre_site_transient_available_translations',
			function () {
				return array( 'pt_BR' => array( 'native_name' => 'Português do Brasil' ) );
			}
		);
		$choices = Playground\language_choices();
		$this->assertSame( array( 'site', 'user', 'en_US' ), wp_list_pluck( $choices, 'value' ) );
		$this->assertSame( array( 'Português do Brasil', 'de_DE', 'English (United States)' ), wp_list_pluck( $choices, 'name' ), 'The locale when its name isn\'t known.' );

		// An English site: no second English.
		remove_filter( 'locale', $site );
		$this->assertSame( array( 'site', 'user' ), wp_list_pluck( Playground\language_choices(), 'value' ) );
		$this->assertSame( 'en_US', Playground\language_choices()[0]['locale'] );

		remove_filter( 'get_user_metadata', $user );
		remove_filter( 'get_available_languages', $installed );
		remove_all_filters( 'pre_site_transient_available_translations' );
		wp_set_current_user( 0 );
	}

	/**
	 * On a network, logs in a super admin: the network's plugins, themes
	 * and must-use plugins are theirs to put in a Playground .zip.
	 */
	private function as_network_admin() {
		if ( is_multisite() ) {
			$admin = get_user_by( 'login', (string) current( get_super_admins() ) );
			wp_set_current_user( $admin ? $admin->ID : 1 );
		}
	}

	public function test_blueprint_in_a_language() {
		$this->as_network_admin();
		$steps = Playground\blueprint( 'site.xml', array( $this->child ), array( 'eizport/eizport.php' ) )['steps'];
		$this->assertNotContains( 'setSiteLanguage', wp_list_pluck( $steps, 'step' ), 'English: no step.' );

		$this->language_files(
			array(
				'plugins/eizport-pt_BR.mo',
				'plugins/eizport-pt_BR.l10n.php',
				'plugins/eizport-pt_BR-0123456789abcdef0123456789abcdef.json',
				'plugins/eizport-pt_BR.po',
				'plugins/eizport-pt_BR_formal.mo',
			)
		);
		$steps = Playground\blueprint( 'site.xml', array( $this->child ), array( 'eizport/eizport.php' ), array(), array(), null, '/', 'pt_BR' )['steps'];
		$names = wp_list_pluck( $steps, 'step' );
		$at    = array_search( 'setSiteLanguage', $names, true );

		$this->assertSame( 'pt_BR', $steps[ $at ]['language'] );
		$this->assertGreaterThan( max( array_keys( $names, 'installTheme', true ) ), $at, 'After the installs: it fetches their translations.' );
		$this->assertLessThan( array_search( 'importWxr', $names, true ), $at );
		// This site's translations of the plugin that isn't from the directory, after it.
		$written = preg_grep( '#^/wordpress/wp-content/languages/#', array_column( array_slice( $steps, $at + 1 ), 'path' ) );
		foreach ( array( 'eizport-pt_BR.mo', 'eizport-pt_BR.l10n.php', 'eizport-pt_BR-0123456789abcdef0123456789abcdef.json' ) as $file ) {
			$this->assertContains( '/wordpress/wp-content/languages/plugins/' . $file, $written );
		}
		$this->assertNotContains( '/wordpress/wp-content/languages/plugins/eizport-pt_BR.po', $written );
		$this->assertNotContains( '/wordpress/wp-content/languages/plugins/eizport-pt_BR_formal.mo', $written, 'Not another locale.' );

		// From the directory: Playground fetches it, nothing in the .zip (this copy is 1.1.0-dev, which the directory hasn't: the filter says it is).
		$from_directory = function () {
			return 'eizport.1.0.1.zip';
		};
		add_filter( 'eizport_playground_from_directory', $from_directory );
		$this->assertSame( array(), Playground\language_files( array(), array( 'eizport/eizport.php' ), 'pt_BR' ) );
		remove_filter( 'eizport_playground_from_directory', $from_directory );

		if ( class_exists( 'ZipArchive' ) ) {
			$wxr = $this->temp_file( '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>' );
			$zip = Playground\build_zip( $wxr, 'site.xml', array(), array( 'eizport/eizport.php' ), false, array(), null, '/', 'pt_BR' );
			$this->remove_later( $zip );
			$archive = new \ZipArchive();
			$archive->open( $zip );
			$this->assertNotFalse( $archive->locateName( 'languages/plugins/eizport-pt_BR.mo' ) );
			$this->assertFalse( $archive->locateName( 'languages/plugins/eizport-pt_BR.po' ) );
			$archive->close();
		}
	}

	/**
	 * Writes a must-use plugin (and files under it) into WPMU_PLUGIN_DIR,
	 * removed after the test.
	 *
	 * @param array<string, string> $files Path under WPMU_PLUGIN_DIR => code.
	 */
	private function mu_plugin( $files ) {
		$tops = array_unique( array_map( fn( $file ) => WPMU_PLUGIN_DIR . '/' . strtok( $file, '/' ), array_keys( $files ) ) );
		foreach ( $tops as $top ) {
			$this->assertFileDoesNotExist( $top, 'Never over a real one.' );
			$this->remove_later( $top );
		}
		foreach ( $files as $file => $code ) {
			wp_mkdir_p( dirname( WPMU_PLUGIN_DIR . '/' . $file ) );
			file_put_contents( WPMU_PLUGIN_DIR . '/' . $file, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public function test_must_use_plugins_offered() {
		$this->as_network_admin();
		$this->mu_plugin( array( 'eizport-test-single.php' => "<?php\n/* Plugin Name: EIZ Test Single */\n" ) );
		$choices = Playground\mu_plugin_choices();

		$this->assertArrayHasKey( 'eizport-test-single.php', $choices );
		$this->assertSame( 'EIZ Test Single', $choices['eizport-test-single.php']['Name'] );
		foreach ( array( '99-studio-loader.php', 'eizport-media.php', 'eizport-options.php' ) as $platform ) {
			$this->assertArrayNotHasKey( $platform, $choices, 'The platform\'s and Eizport\'s own aren\'t offered.' );
		}

		$hide = function ( $choices ) {
			unset( $choices['eizport-test-single.php'] );
			return $choices;
		};
		add_filter( 'eizport_playground_mu_plugins', $hide );
		$this->assertArrayNotHasKey( 'eizport-test-single.php', Playground\mu_plugin_choices() );
		remove_filter( 'eizport_playground_mu_plugins', $hide );
		$this->assertSame( array( 'eizport-test-single.php' ), Playground\mu_plugin_files( array( 'eizport-test-single.php', 'nope.php', '99-studio-loader.php' ) ) );
	}

	public function test_must_use_plugins_and_the_folders_they_load() {
		$this->as_network_admin();
		$this->mu_plugin(
			array(
				'eizport-test-single.php'          => "<?php\n/* Plugin Name: EIZ Test Single */\n",
				'eizport-test-loader.php'          => "<?php\n/* Plugin Name: EIZ Test Loader */\nrequire_once __DIR__ . '/eizport-test-lib/load.php';\ninclude WPMU_PLUGIN_DIR . '/eizport-test-missing/x.php';\n",
				'eizport-test-lib/load.php'        => "<?php\n",
				'eizport-test-lib/inc/more.php'    => "<?php\n",
				'eizport-test-same.php'            => "<?php\n/* Plugin Name: EIZ Test Same */\n",
				'eizport-test-same/assets/app.css' => 'a{}',
			)
		);

		$this->assertSame( array(), Playground\mu_plugin_folders( 'eizport-test-single.php' ) );
		$this->assertSame( array( 'eizport-test-lib' ), Playground\mu_plugin_folders( 'eizport-test-loader.php' ), 'Required from its folder; a folder that isn\'t there doesn\'t count.' );
		$this->assertSame( array( 'eizport-test-same' ), Playground\mu_plugin_folders( 'eizport-test-same.php' ), 'name.php next to name/.' );

		$chosen = array( 'eizport-test-single.php', 'eizport-test-loader.php', 'eizport-test-same.php' );
		$steps  = Playground\blueprint( 'site.xml', array(), array(), array(), array(), null, '/', '', $chosen )['steps'];
		$names  = wp_list_pluck( $steps, 'step' );
		$unzips = array_values( array_filter( $steps, fn( $step ) => 'unzip' === $step['step'] ) );

		$this->assertSame( array( '/mu-plugins/eizport-test-lib.zip', '/mu-plugins/eizport-test-same.zip' ), wp_list_pluck( wp_list_pluck( $unzips, 'zipFile' ), 'path' ) );
		$this->assertSame( '/wordpress/wp-content/mu-plugins', $unzips[0]['extractToPath'] );
		$written = array_column( $steps, 'path' );
		foreach ( $chosen as $file ) {
			$this->assertContains( '/wordpress/wp-content/mu-plugins/' . $file, $written );
		}
		$this->assertLessThan( array_search( 'importWxr', $names, true ), array_search( 'unzip', $names, true ), 'Before the import: what they register is there for it.' );
		$this->assertCount( 1, array_keys( array_column( $steps, 'path' ), '/wordpress/wp-content/mu-plugins', true ), 'mu-plugins/ made once.' );

		if ( class_exists( 'ZipArchive' ) ) {
			$wxr = $this->temp_file( '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>' );
			$zip = Playground\build_zip( $wxr, 'site.xml', array(), array(), false, array(), null, '/', '', $chosen );
			$this->remove_later( $zip );
			$archive = new \ZipArchive();
			$archive->open( $zip );
			$this->assertNotFalse( $archive->locateName( 'mu-plugins/eizport-test-single.php' ) );
			$this->assertNotFalse( $archive->locateName( 'mu-plugins/eizport-test-lib.zip' ) );
			$inner = $this->temp_file( (string) $archive->getFromName( 'mu-plugins/eizport-test-lib.zip' ) );
			$archive->close();
			$folder = new \ZipArchive();
			$folder->open( $inner );
			$this->assertNotFalse( $folder->locateName( 'eizport-test-lib/inc/more.php' ), 'The folder, as it is.' );
			$folder->close();
		}
	}

	public function test_the_directory_s_live_preview_blueprint() {
		$file = dirname( __DIR__, 2 ) . '/.wordpress-org/blueprints/blueprint.json';
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $text, true );

		// WordPress.org's rules: json_decode() reads it, 100 KB at most.
		$this->assertIsArray( $data, 'Valid JSON for PHP.' );
		$this->assertLessThan( 100 * 1024, strlen( $text ) );
		$this->assertSame( '/wp-admin/export.php', $data['landingPage'], 'Eizport\'s Export screen.' );
		$this->assertSame(
			array(
				'step'       => 'installPlugin',
				'pluginData' => array(
					'resource' => 'wordpress.org/plugins',
					'slug'     => 'eizport',
				),
				'options'    => array( 'activate' => true ),
			),
			$data['steps'][1],
			'From the directory: the version released there.'
		);
		$this->assertSame( array( 'login', 'installPlugin' ), array_column( $data['steps'], 'step' ), 'A plain site: its own posts and pages, nothing written into it.' );
	}
}
