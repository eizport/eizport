<?php
/**
 * Tests for the Export screen's menu item and for the new screens'
 * translations (wp.i18n locale data).
 *
 * @package Eizport
 */

namespace Eizport\Tests;

use Eizport\AdminApp;

/**
 * Admin app tests.
 */
class AdminAppTest extends Test_Case {

	/**
	 * Unloads the test's translations.
	 */
	protected function tear_down() {
		unload_textdomain( 'eizport' );
		remove_all_filters( 'pre_option_' . \Eizport\Settings\OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Loads translations from a temporary .mo.
	 *
	 * @param array<int, array<string, mixed>> $entries Translation_Entry arguments.
	 */
	private function translate( array $entries ) {
		$mo = new \MO();
		$mo->set_header( 'Plural-Forms', 'nplurals=2; plural=(n > 1);' );
		foreach ( $entries as $entry ) {
			$mo->add_entry( new \Translation_Entry( $entry ) );
		}
		// load_textdomain() only takes .mo (or .l10n.php) files.
		$file = $this->temp_file() . '.mo';
		$this->remove_later( $file );
		$mo->export_to_file( $file );
		unload_textdomain( 'eizport' );
		load_textdomain( 'eizport', $file );
	}

	/**
	 * Nothing translated, nothing sent to wp.i18n.
	 */
	public function test_no_translations() {
		$this->translate( array() );
		$this->assertSame( array(), AdminApp\locale_data() );
	}

	/**
	 * Translated strings go as Jed locale data, with context and plural forms.
	 */
	public function test_locale_data() {
		$this->translate(
			array(
				array(
					'singular'     => 'Export',
					'translations' => array( 'Exportar' ),
				),
				array(
					'singular'     => '%d plugin',
					'plural'       => '%d plugins',
					'translations' => array( '%d plugin', '%d plugins' ),
				),
				array(
					'context'      => 'button',
					'singular'     => 'Choose',
					'translations' => array( 'Escolher' ),
				),
				array(
					'singular'     => 'Untranslated',
					'translations' => array( '' ),
				),
			)
		);
		$data = AdminApp\locale_data();

		$this->assertSame( array( 'Exportar' ), $data['Export'] );
		$this->assertSame( array( '%d plugin', '%d plugins' ), $data['%d plugin'] );
		$this->assertSame( array( 'Escolher' ), $data["button\4Choose"] );
		$this->assertArrayNotHasKey( 'Untranslated', $data );
		$this->assertSame( 'eizport', $data['']['domain'] );
		$this->assertSame( 'nplurals=2; plural=(n > 1);', $data['']['plural-forms'] );
	}

	/**
	 * The Tools submenu items after add_page(), with these settings.
	 *
	 * @param array $settings Settings stored.
	 * @return array<string, string> Slug => title.
	 */
	private function tools_menu( array $settings ) {
		global $submenu, $_registered_pages, $_parent_pages;
		$saved = array( $submenu, $_registered_pages, $_parent_pages );
		add_filter(
			'pre_option_' . \Eizport\Settings\OPTION,
			function () use ( $settings ) {
				return $settings;
			}
		);
		$admin = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admin[0]->ID );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		$submenu = array(
			'tools.php' => array(
				5  => array( 'Available Tools', 'edit_posts', 'tools.php' ),
				10 => array( 'Import', 'import', 'import.php' ),
				15 => array( 'Export', 'export', 'export.php' ),
				20 => array( 'Site Health', 'view_site_health_checks', 'site-health.php' ),
			),
		);

		AdminApp\add_page();
		$items = array_column( $submenu['tools.php'], 0, 2 );
		list( $submenu, $_registered_pages, $_parent_pages ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores them.

		return $items;
	}

	/**
	 * By default the new screen takes Export's place; the classic screen
	 * keeps core's item; not replacing adds Eizport after Export.
	 */
	public function test_menu() {
		if ( ! AdminApp\available() ) {
			$this->markTestSkipped( 'The Export screen isn\'t built.' );
		}

		$this->assertSame(
			array( 'tools.php', 'import.php', AdminApp\SLUG, 'site-health.php' ),
			array_keys( $this->tools_menu( array() ) ),
			'Replaced, in the same place.'
		);
		$this->assertSame(
			array( 'tools.php', 'import.php', 'export.php', 'site-health.php' ),
			array_keys( $this->tools_menu( array( 'classic_ui' => true ) ) ),
			'The classic screen.'
		);
		$items = $this->tools_menu(
			array(
				'replace_export' => false,
				'classic_ui'     => true,
			)
		);
		$this->assertSame( array( 'tools.php', 'import.php', 'export.php', AdminApp\SLUG, 'site-health.php' ), array_keys( $items ), 'Not replaced: its own item, and the classic setting is ignored.' );
		$this->assertSame( 'Eizport', $items[ AdminApp\SLUG ] );
	}

	/**
	 * WordPress's Export screen gets the plugin's choices only while it is
	 * replaced.
	 */
	public function test_core_screen_untouched_when_not_replaced() {
		add_filter(
			'pre_option_' . \Eizport\Settings\OPTION,
			function () {
				return array( 'replace_export' => false );
			}
		);
		$_GET['eizport_bundle'] = '1';
		$args                   = array( 'content' => 'all' );
		$filtered               = \Eizport\Export\file_args( \Eizport\Selection\args( $args ) );
		ob_start();
		\Eizport\Selection\render();
		\Eizport\Export\file_options();
		$printed = ob_get_clean();
		unset( $_GET['eizport_bundle'] );

		$this->assertFalse( AdminApp\replaces_export() );
		$this->assertSame( $args, $filtered );
		$this->assertSame( '', $printed );
	}

	/**
	 * The importer looks like the Export screen only with "Style Tools →
	 * Import → WordPress"; .wxrz files are mentioned either way.
	 */
	public function test_importer_styling_follows_the_setting() {
		foreach ( array( true, false ) as $styled ) {
			remove_all_filters( 'pre_option_' . \Eizport\Settings\OPTION );
			add_filter(
				'pre_option_' . \Eizport\Settings\OPTION,
				function () use ( $styled ) {
					return array( 'style_import' => $styled );
				}
			);
			\Eizport\Import\mention_wxrz();
			$enqueues = has_action( 'admin_enqueue_scripts', 'Eizport\Import\style' );
			$classes  = has_filter( 'admin_body_class', 'Eizport\Import\body_class' );
			remove_action( 'admin_enqueue_scripts', 'Eizport\Import\style' );
			remove_filter( 'admin_body_class', 'Eizport\Import\body_class' );
			remove_all_filters( 'gettext_wordpress-importer' );

			$this->assertSame( $styled, false !== $enqueues );
			$this->assertSame( $styled, false !== $classes );
		}

		\Eizport\Import\style();
		$this->assertTrue( wp_style_is( 'eizport-importer', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'eizport-importer', 'enqueued' ) );
		$this->assertStringContainsString( '"choose":"Choose file"', implode( '', wp_scripts()->get_data( 'eizport-importer', 'before' ) ) );
		wp_dequeue_style( 'eizport-importer' );
		wp_dequeue_script( 'eizport-importer' );
		$this->assertSame( 'a eizport-import', \Eizport\Import\body_class( 'a' ) );
	}

	/**
	 * The styled importer's scripts run right after the screen, before the
	 * admin's footer scripts, with step 1's fields already in their data:
	 * the importer's markup never shows unstyled while the others load.
	 */
	public function test_importer_scripts_print_right_after_the_screen() {
		$_GET['step'] = '1';
		\Eizport\Import\mention_wxrz();
		unset( $_GET['step'] );
		foreach ( array( 'update_existing_field', 'css_field', 'options_field' ) as $field ) {
			$this->assertSame( 5, has_action( 'in_admin_footer', 'Eizport\Import\\' . $field ) );
			remove_action( 'in_admin_footer', 'Eizport\Import\\' . $field, 5 );
		}
		$this->assertSame( 5, has_action( 'in_admin_footer', 'Eizport\Import\check_fetch_attachments' ) );
		remove_all_actions( 'admin_notices' );
		remove_all_filters( 'gettext_wordpress-importer' );
		remove_action( 'admin_enqueue_scripts', 'Eizport\Import\style' );
		remove_filter( 'admin_body_class', 'Eizport\Import\body_class' );

		\Eizport\Import\register_steps_script();
		\Eizport\Import\style();
		$this->assertSame( 10, has_action( 'in_admin_footer', 'Eizport\Import\print_early_scripts' ) );
		remove_action( 'in_admin_footer', 'Eizport\Import\print_early_scripts' );

		ob_start();
		\Eizport\Import\print_early_scripts();
		$printed = ob_get_clean();
		$steps   = strpos( $printed, 'import-steps.js' );
		$this->assertNotFalse( $steps );
		$this->assertGreaterThan( $steps, strpos( $printed, 'importer.js' ) );
		$this->assertTrue( wp_script_is( 'eizport-importer', 'done' ) );
		$this->assertTrue( wp_script_is( 'eizport-import-steps', 'done' ) );
		wp_dequeue_style( 'eizport-importer' );
		wp_scripts()->done = array();
	}

	/**
	 * Tools → Import's link to every importer skips the redirect.
	 */
	public function test_import_list_url() {
		$this->assertStringEndsWith( 'import.php?all=1', \Eizport\Import\all_importers_url() );
		$this->assertNotFalse( has_action( 'load-import.php', 'Eizport\Import\open_wordpress_importer' ) );
	}
}
