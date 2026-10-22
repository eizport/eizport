<?php
/**
 * Tests for exporting "as plugin": the plugin .zip and the bundle a plugin
 * may import.
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\BundlePlugin;

/**
 * Bundle plugin tests.
 */
class BundlePluginTest extends Test_Case {

	public function test_slug() {
		$this->assertSame( 'mysite-wordpress-2026-10-12-abcdefgh12345678', BundlePlugin\slug( 'mysite.WordPress.2026-10-12', 'AbCdEfGh12345678' ) );
		$this->assertSame( 'import-2026-10-12-abcdefgh12345678', BundlePlugin\slug( '2026-10-12', 'abcdefgh12345678' ), 'A slug needs a letter.' );
		$this->assertMatchesRegularExpression( '/^woocommerce-[a-z0-9]{16}$/', BundlePlugin\slug( 'woocommerce' ), 'Never another plugin\'s slug.' );
		$this->assertSame( 'AbCdEfGh12345678', BundlePlugin\copy_hash( '/tmp/site-AbCdEfGh12345678.zip' ) );
		$this->assertSame( '', BundlePlugin\copy_hash( '/tmp/site.zip' ) );
	}

	public function test_a_zip_bundle_goes_in_as_a_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$bundle = $this->temp_file() . '.zip';
		$this->remove_later( $bundle );
		$inner = new \ZipArchive();
		$inner->open( $bundle, \ZipArchive::CREATE );
		$inner->addFromString( 'manifest.json', '{}' );
		$inner->close();
		$target = $this->temp_file() . '-AbCdEfGh12345678.zip';
		$this->remove_later( $target );

		$this->assertTrue( BundlePlugin\build_zip( $target, $bundle, 'my.site' ) );
		$zip = new \ZipArchive();
		$zip->open( $target );
		$this->assertNotFalse( $zip->locateName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.zip' ), 'Named as it is.' );
		$this->assertStringContainsString( 'Eizport Bundle:    my-site-abcdefgh12345678.zip', (string) $zip->getFromName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.php' ) );
		$zip->close();
	}

	public function test_zip_holds_the_plugin_and_the_bundle() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$bundle = $this->temp_file( 'bundle' );
		$target = $this->temp_file() . '-AbCdEfGh12345678.zip';
		$this->remove_later( $target );

		$this->assertTrue( BundlePlugin\build_zip( $target, $bundle, 'my.site' ) );
		$this->assertTrue( BundlePlugin\is_plugin_zip( $target ) );

		$zip = new \ZipArchive();
		$zip->open( $target );
		$code = (string) $zip->getFromName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.php' );
		$this->assertSame( 'bundle', $zip->getFromName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.wxrz' ) );
		$zip->close();

		$this->assertStringContainsString( 'Eizport Bundle:    my-site-abcdefgh12345678.wxrz', $code );
		$this->assertStringContainsString( 'Plugin Name:       Import: my.site', $code );
		$this->assertStringNotContainsString( 'EIZPORT_', $code, 'Every placeholder is filled.' );
		$this->assertStringContainsString( "\$title = 'Import: my.site'", $code );

		// The filled-in plugin is valid PHP.
		$file = $this->temp_file() . '.php';
		$this->remove_later( $file );
		file_put_contents( $file, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$this->assertFalse( BundlePlugin\is_plugin_zip( $bundle ), 'Not a zip.' );
		$this->assertStringContainsString( 'Eizport Update:    no', $code );
		$this->assertStringContainsString( 'Text Domain:       my-site-abcdefgh12345678', $code, 'Its own text domain: its folder.' );
		$this->assertStringContainsString( "'gettext_my-site-abcdefgh12345678'", $code );
	}

	public function test_zip_says_when_the_import_updates_existing_items() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$target = $this->temp_file() . '-AbCdEfGh12345678.zip';
		$this->remove_later( $target );
		$GLOBALS['eizport_update_existing'] = true;
		$built                              = BundlePlugin\build_zip( $target, $this->temp_file( 'bundle' ), 'my.site' );
		unset( $GLOBALS['eizport_update_existing'] );
		$this->assertTrue( $built );

		$zip = new \ZipArchive();
		$zip->open( $target );
		$this->assertStringContainsString( 'Eizport Update:    yes', (string) $zip->getFromName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.php' ) );
		$zip->close();
	}

	/**
	 * A tar bundle with a manifest and a WXR, as Folder\pack() writes it.
	 *
	 * @return string
	 */
	private function tar_bundle() {
		$source = $this->temp_file();
		wp_delete_file( $source );
		wp_mkdir_p( $source );
		$this->remove_later( $source );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$source . '/manifest.json',
			wp_json_encode(
				array(
					'version' => \Eizport\Bundle\FORMAT_VERSION,
					'wxr'     => array( 'export.xml' ),
					'files'   => array(),
				)
			)
		);
		file_put_contents( $source . '/export.xml', '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Site</title></channel></rss>' );
		// phpcs:enable
		$tar = $this->temp_file();
		\Eizport\Folder\pack( $source, $tar );

		return $tar;
	}

	/**
	 * Where the bundle's files go, by Settings → Export → "As plugin holds the bundle as".
	 *
	 * @return array
	 */
	public function plugin_content_modes() {
		return array(
			'next to the plugin' => array( 'root', '' ),
			'in bundle/'         => array( 'folder', 'bundle/' ),
		);
	}

	/**
	 * The plugin holds the bundle's files instead of the .wxrz.
	 *
	 * @dataProvider plugin_content_modes
	 *
	 * @param string $mode   Setting.
	 * @param string $prefix Where the files are, in the plugin's folder.
	 */
	public function test_expanded_plugin_holds_the_bundle_files( $mode, $prefix ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$expanded = function ( $value ) use ( $mode ) {
			return array_merge( (array) $value, array( 'plugin_content' => $mode ) );
		};
		add_filter( 'pre_option_' . \Eizport\Settings\OPTION, $expanded, 2 );
		$target = $this->temp_file() . '-AbCdEfGh12345678.zip';
		$this->remove_later( $target );
		$built = BundlePlugin\build_zip( $target, $this->tar_bundle(), 'my.site' );
		remove_filter( 'pre_option_' . \Eizport\Settings\OPTION, $expanded, 2 );

		$this->assertTrue( $built );
		$this->assertTrue( BundlePlugin\is_plugin_zip( $target ) );
		$zip = new \ZipArchive();
		$zip->open( $target );
		$code     = (string) $zip->getFromName( 'my-site-abcdefgh12345678/my-site-abcdefgh12345678.php' );
		$manifest = $zip->getFromName( 'my-site-abcdefgh12345678/' . $prefix . 'manifest.json' );
		$wxr      = $zip->getFromName( 'my-site-abcdefgh12345678/' . $prefix . 'export.xml' );
		$zip->close();

		$this->assertStringContainsString( 'Eizport Bundle:    ' . $prefix . 'manifest.json', $code );
		$this->assertNotFalse( $manifest );
		$this->assertStringContainsString( '<title>Site</title>', (string) $wxr );
	}

	/**
	 * Every way a plugin holds its bundle.
	 *
	 * @return array
	 */
	public function all_plugin_content_modes() {
		return array_merge( array( 'a .wxrz' => array( 'wxrz', '' ) ), $this->plugin_content_modes() );
	}

	/**
	 * The plugin's .zip, uploaded to the WordPress Importer (or given to
	 * `wp eizport import`), imports as the bundle it holds.
	 *
	 * @dataProvider all_plugin_content_modes
	 *
	 * @param string $mode   Setting.
	 * @param string $prefix Unused.
	 */
	public function test_the_plugin_zip_imports_as_its_bundle( $mode, $prefix ) {
		unset( $prefix );
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'No ZipArchive.' );
		}
		$setting = function ( $value ) use ( $mode ) {
			return array_merge( (array) $value, array( 'plugin_content' => $mode ) );
		};
		$bundle  = $this->temp_file();
		file_put_contents( $bundle, gzencode( (string) file_get_contents( $this->tar_bundle() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		add_filter( 'pre_option_' . \Eizport\Settings\OPTION, $setting, 2 );
		$target = $this->temp_file() . '-AbCdEfGh12345678.zip';
		$this->remove_later( $target );
		$built = BundlePlugin\build_zip( $target, 'wxrz' === $mode ? $bundle : $this->tar_bundle(), 'my.site' );
		remove_filter( 'pre_option_' . \Eizport\Settings\OPTION, $setting, 2 );
		$this->assertTrue( $built );

		$wxr = $this->temp_file();
		$dir = \Eizport\Import\prepare_wxr( $target, $wxr );

		$this->assertIsString( $dir );
		$this->assertDirectoryExists( $dir );
		\Eizport\Bundle\delete_folder( $dir );
		$this->assertStringContainsString( '<title>Site</title>', (string) file_get_contents( $wxr ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * An installed plugin's bundle folder: its own, or its bundle/.
	 *
	 * @dataProvider plugin_content_modes
	 *
	 * @param string $mode   Setting (unused).
	 * @param string $prefix Where the manifest is.
	 */
	public function test_bundle_of_takes_the_bundle_folder( $mode, $prefix ) {
		unset( $mode );
		$slug = 'eizport-test-' . strtolower( wp_generate_password( 8, false ) );
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir . '/' . $prefix );
		$this->remove_later( $dir );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: Test\n * Eizport Bundle: " . $prefix . "manifest.json\n */\n" );
		$this->assertInstanceOf( \WP_Error::class, BundlePlugin\bundle_of( $dir . '/' . $slug . '.php' ), 'No manifest yet.' );
		file_put_contents( $dir . '/' . $prefix . 'manifest.json', '{}' );
		// phpcs:enable

		$this->assertSame( realpath( $dir . '/' . $prefix ), BundlePlugin\bundle_of( $dir . '/' . $slug . '.php' ) );
	}

	public function test_a_plugin_from_a_translated_site_carries_its_texts() {
		$this->assertSame( array(), BundlePlugin\template_translations(), 'English site: nothing to carry.' );
		$locale  = function () {
			return 'pt_BR';
		};
		$gettext = function ( $translation, $text, $domain ) {
			return 'eizport' === $domain && 'Install Now' === $text ? 'Instalar agora' : $translation;
		};
		add_filter( 'locale', $locale );
		add_filter( 'gettext', $gettext, 10, 3 );
		$texts = BundlePlugin\template_translations();
		remove_filter( 'locale', $locale );
		remove_filter( 'gettext', $gettext, 10 );

		$this->assertSame( array( 'pt_BR' => array( 'Install Now' => 'Instalar agora' ) ), $texts, 'Only what is translated.' );
	}

	public function test_forget_drops_the_plugin_and_empty_options() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		update_option(
			BundlePlugin\BEFORE,
			array(
				'a' => array(),
				'b' => array(),
			),
			false
		);
		update_option( BundlePlugin\IMPORTED, array( 'a' => 1 ), false );

		BundlePlugin\forget( 'a' );
		$before   = get_option( BundlePlugin\BEFORE );
		$imported = get_option( BundlePlugin\IMPORTED, 'gone' );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test's transaction.
		wp_cache_flush();

		$this->assertSame( array( 'b' => array() ), $before );
		$this->assertSame( 'gone', $imported, 'Left empty: deleted.' );
	}

	public function test_a_plugin_only_imports_its_own_bundle() {
		$this->assertInstanceOf( \WP_Error::class, BundlePlugin\bundle_of( ABSPATH . 'wp-load.php' ), 'Not an installed plugin.' );
		$this->assertInstanceOf( \WP_Error::class, BundlePlugin\bundle_of( dirname( __DIR__, 2 ) . '/eizport.php' ), 'No "Eizport Bundle" header.' );
		$this->assertSame( '', BundlePlugin\plugin_file( '../eizport' ) );
		$this->assertSame( '', BundlePlugin\plugin_file( 'eizport' ) );
	}

	/**
	 * The plugins it needed go with it, checked when they were installed for it.
	 */
	public function test_also_deletable() {
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		$main     = plugin_basename( dirname( __DIR__, 2 ) . '/eizport.php' );
		$importer = \Eizport\Import\IMPORTER_PLUGIN;
		$before   = function () use ( $main, $importer ) {
			return array(
				'z-store' => array(
					$main     => false,
					$importer => true,
				),
			);
		};
		add_filter( 'pre_option_' . BundlePlugin\BEFORE, $before );
		$known   = BundlePlugin\also_deletable( 'z-store' );
		$unknown = BundlePlugin\also_deletable( 'made-by-0-11-0' );
		remove_filter( 'pre_option_' . BundlePlugin\BEFORE, $before );
		wp_set_current_user( 0 );

		$this->assertTrue( $known[ $main ]['checked'], 'Installed for the import: checked.' );
		if ( isset( $known[ $importer ] ) ) {
			$this->assertFalse( $known[ $importer ]['checked'], 'There before: unchecked.' );
		}
		$this->assertFalse( $unknown[ $main ]['checked'], 'Not known: unchecked.' );
		$this->assertStringContainsString( 'register_activation_hook', (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/bundle-plugin.php.tpl' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
