<?php
/**
 * Removes what the plugin leaves behind (includes/uninstall-site.php) on
 * every site: each site of a network, or the site. Defaults users saved
 * before they were kept per site (user meta) go too.
 *
 * @package Eizport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/uninstall-site.php';

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $eizport_site ) {
		switch_to_blog( (int) $eizport_site );
		eizport_uninstall_site();
		restore_current_blog();
	}
} else {
	eizport_uninstall_site();
}
delete_metadata( 'user', 0, 'eizport_export_defaults', '', true );
