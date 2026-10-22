<?php
/**
 * Plugin Name:       Eizport
 * Plugin URI:        https://eizport.github.io/
 * Description:       Export and import WordPress content as .wxrz bundles: the WXR plus its media and content files, gzipped, optionally ready for WordPress Playground. Export several content types at once, each by filters or by selection, with settings.
 * Version:           1.1.0-dev
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            eizport
 * Author URI:        https://profiles.wordpress.org/eizport/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eizport
 * Domain Path:       /languages
 *
 * @package Eizport
 */

namespace Eizport;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.1.0-dev';

require_once __DIR__ . '/includes/files.php';
require_once __DIR__ . '/includes/wxrz.php';
require_once __DIR__ . '/includes/tar.php';
require_once __DIR__ . '/includes/bundle.php';
require_once __DIR__ . '/includes/playground.php';
require_once __DIR__ . '/includes/copies.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/selection.php';
require_once __DIR__ . '/includes/options.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/runner.php';
require_once __DIR__ . '/includes/suggestions.php';
require_once __DIR__ . '/includes/menus.php';
require_once __DIR__ . '/includes/terms.php';
require_once __DIR__ . '/includes/import-menus.php';
require_once __DIR__ . '/includes/import-templates.php';
require_once __DIR__ . '/includes/import-blocks.php';
require_once __DIR__ . '/includes/import-links.php';
require_once __DIR__ . '/includes/import-advanced-custom-fields.php';
require_once __DIR__ . '/includes/import-elementor.php';
require_once __DIR__ . '/includes/appearance.php';
require_once __DIR__ . '/includes/bundle-plugin.php';
require_once __DIR__ . '/includes/folder.php';
require_once __DIR__ . '/includes/defaults.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/admin-app.php';
require_once __DIR__ . '/includes/compat/woocommerce.php';
require_once __DIR__ . '/includes/compat/advanced-custom-fields.php';
require_once __DIR__ . '/includes/compat/elementor.php';

Selection\bootstrap();
Menus\bootstrap();
Terms\bootstrap();
Export\bootstrap();
Import\bootstrap();
Copies\bootstrap();
Settings\bootstrap();
Abilities\bootstrap();
AdminApp\bootstrap();
BundlePlugin\bootstrap();

// Compatibilities with other plugins hook here (after every plugin loaded);
// a plugin can remove ours and register its own.
add_action( 'eizport_register_compat', 'Eizport\Compat\WooCommerce\register' );
add_action( 'eizport_register_compat', 'Eizport\Compat\AdvancedCustomFields\register' );
add_action( 'eizport_register_compat', 'Eizport\Compat\Elementor\register' );
add_action(
	'plugins_loaded',
	function () {
		/**
		 * Registers compatibilities with other plugins, through the
		 * eizport_* filters (eizport_linked_options, eizport_option_groups,
		 * eizport_denied_options…).
		 */
		do_action( 'eizport_register_compat' );
	},
	20
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
	Cli\bootstrap();
}
