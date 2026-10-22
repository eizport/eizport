<?php
/**
 * What the plugin leaves behind on a site, removed: uploads/eizport/
 * (temporary Playground copies and folders of bundles whose import was
 * never finished), the settings, the saved export defaults (the site's and
 * each user's on this site), what bundle plugins noted and the cleanup
 * event. uninstall.php runs it on every site of a network.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_uninstall_site' ) ) {
	/**
	 * Removes the plugin's data from the current site.
	 *
	 * @param bool $files Also delete uploads/eizport/.
	 */
	function eizport_uninstall_site( $files = true ) {
		global $wpdb;

		if ( $files ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$folder = wp_get_upload_dir()['basedir'] . '/eizport';
			if ( is_dir( $folder ) ) {
				( new WP_Filesystem_Direct( null ) )->rmdir( $folder, true );
			}
		}

		wp_clear_scheduled_hook( 'eizport_cleanup' );
		delete_option( 'eizport_settings' );
		delete_option( 'eizport_export_defaults' );
		delete_option( 'eizport_bundle_plugins_before' );
		delete_option( 'eizport_bundle_plugins_imported' );
		delete_option( 'eizport_imported_widgets' );
		// Each user's defaults on this site (user options carry the site's prefix).
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'eizport_export_defaults', '', true );
	}
}
