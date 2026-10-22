<?php
/**
 * Exports "as plugin": a plugin .zip that holds the bundle
 * (templates/bundle-plugin.php.tpl filled in, and the .wxrz). Installed on
 * another site, the plugin adds a screen under Tools; this file draws it
 * when Eizport is active there — the plugins needed, with links
 * to install them, and "Run import", which imports the bundle the plugin
 * holds (no upload) — and runs the import, then offers to deactivate and
 * delete the plugin.
 *
 * The bundle a plugin may import is the one its header names ("Eizport
 * Bundle:"), inside its own folder, of an installed plugin.
 *
 * @package Eizport
 */

namespace Eizport\BundlePlugin;

use Eizport\Import;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Where imported plugins are remembered: slug => time.
 */
const IMPORTED = 'eizport_bundle_plugins_imported';

/**
 * Which of the plugins a bundle plugin needs were there when it was
 * activated: slug => plugin file => bool (written by the plugin itself).
 */
const BEFORE = 'eizport_bundle_plugins_before';

/**
 * Registers the hooks.
 */
function bootstrap() {
	add_action( 'admin_post_eizport_bundle_import', __NAMESPACE__ . '\handle_import' );
	add_action( 'admin_post_eizport_bundle_delete', __NAMESPACE__ . '\handle_delete' );
	add_filter( 'admin_body_class', __NAMESPACE__ . '\body_class' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue' );
}

/**
 * The folder holding the bundle's files when Settings → Export → "As plugin
 * holds the bundle as" says "in a bundle/ folder".
 */
const FOLDER = 'bundle';

/**
 * A plugin slug for an export's file name, ending in a random hash: no
 * file name (woocommerce, say) can make it the slug of another plugin.
 *
 * @param string $name File name, without extension.
 * @param string $hash Random letters and digits (the copy's), or '' for new ones.
 * @return string
 */
function slug( $name, $hash = '' ) {
	$slug = sanitize_title( $name );
	$slug = preg_match( '/[a-z]/', $slug ) ? $slug : 'import-' . $slug;
	$hash = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $hash ) );

	return $slug . '-' . ( '' === $hash ? strtolower( wp_generate_password( 16, false ) ) : $hash );
}

/**
 * The random part of a copy's file name (name-<16 letters and digits>.zip).
 *
 * @param string $path Copy file.
 * @return string The hash, or ''.
 */
function copy_hash( $path ) {
	return preg_match( '/-([A-Za-z0-9]{16})\.[a-z0-9]+$/i', basename( $path ), $match ) ? $match[1] : '';
}

/**
 * The texts of a bundle plugin (templates/bundle-plugin.php.tpl) in this site's
 * language, for the plugin to carry: it shows them on a site in the same
 * language, English elsewhere. Empty for an English site.
 *
 * @return array<string, array<string, string>> Locale => English => translation.
 */
function template_translations() {
	$locale = get_locale();
	if ( 'en_US' === $locale ) {
		return array();
	}
	$switched = switch_to_locale( $locale );
	$texts    = array(
		'Activate Eizport to import'  => __( 'Activate Eizport to import', 'eizport' ),
		'Eizport is needed to import' => __( 'Eizport is needed to import', 'eizport' ),
		'This plugin holds content exported with Eizport. Eizport imports it, and then this plugin can go.' => __( 'This plugin holds content exported with Eizport. Eizport imports it, and then this plugin can go.', 'eizport' ),
		'Activate Eizport'            => __( 'Activate Eizport', 'eizport' ),
		'Install Now'                 => __( 'Install Now', 'eizport' ),
		'Installing…'                 => __( 'Installing…', 'eizport' ),
		'Activating…'                 => __( 'Activating…', 'eizport' ),
		/* translators: %s: error message. */
		'Installation failed: %s'     => __( 'Installation failed: %s', 'eizport' ),
		'Eizport is active.'          => __( 'Eizport is active.', 'eizport' ),
		'Continue'                    => __( 'Continue', 'eizport' ),
		'Update Eizport to import'    => __( 'Update Eizport to import', 'eizport' ),
		/* translators: 1: the oldest Eizport version that reads the bundle, 2: this site's Eizport version. */
		'This plugin holds content exported with a newer Eizport: it needs Eizport %1$s or newer, and this site has %2$s.' => __( 'This plugin holds content exported with a newer Eizport: it needs Eizport %1$s or newer, and this site has %2$s.', 'eizport' ),
		'Update now'                  => __( 'Update now', 'eizport' ),
		'Import'                      => __( 'Import', 'eizport' ),
		'open the import screen'      => __( 'open the import screen', 'eizport' ),
	);
	if ( $switched ) {
		restore_previous_locale();
	}
	$texts = array_filter(
		$texts,
		function ( $translation, $text ) {
			return $translation !== $text;
		},
		ARRAY_FILTER_USE_BOTH
	);

	return $texts ? array( $locale => $texts ) : array();
}

/**
 * Writes the plugin .zip: <slug>/<slug>.php and <slug>/<slug>.zip (or .wxrz,
 * as the bundle is: Settings → Export → "Bundle as .zip") — or,
 * as Settings → Export → "As plugin holds the bundle as" says, the bundle's
 * files (manifest.json, the WXR, uploads/…) next to <slug>.php or in
 * <slug>/bundle/ (FOLDER).
 *
 * @param string $target File to write.
 * @param string $bundle The bundle (.zip or .wxrz).
 * @param string $name   The export's file name, without extension.
 * @return bool
 */
function build_zip( $target, $bundle, $name ) {
	// The copy's random part, lowercase: the plugin's folder and file share it.
	$hash   = copy_hash( $target );
	$slug   = slug( $name, '' === $hash ? (string) ( $GLOBALS['eizport_copy_hash'] ?? '' ) : $hash );
	$mode   = \Eizport\Settings\get()['plugin_content'];
	$prefix = 'folder' === $mode ? FOLDER . '/' : '';
	$file   = 'wxrz' === $mode ? $slug . '.' . ( \Eizport\Bundle\is_zip( $bundle ) ? 'zip' : \Eizport\Wxrz\EXTENSION ) : $prefix . \Eizport\Bundle\MANIFEST;
	$header = get_file_data(
		dirname( __DIR__ ) . '/eizport.php',
		array(
			'wp'  => 'Requires at least',
			'php' => 'Requires PHP',
		)
	);
	$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$site   = '' === trim( $site ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : $site;
	// One line each, nothing that ends the comment.
	$line = function ( $text ) {
		return trim( str_replace( array( "\r", "\n", '*/' ), ' ', (string) $text ) );
	};
	// Named after the export's file, as it was downloaded.
	$title = $line(
		/* translators: %s: the export's file name. */
		sprintf( __( 'Import: %s', 'eizport' ), '' === trim( (string) $name ) ? $slug : $name )
	);
	$code = strtr(
		(string) file_get_contents( dirname( __DIR__ ) . '/templates/bundle-plugin.php.tpl' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our template.
		array(
			'EIZPORT_PLUGIN_NAME'          => $title,
			'EIZPORT_PLUGIN_DESCRIPTION'   => $line(
				/* translators: %s: site title. */
				sprintf( __( 'The content exported from %s, with Eizport. Open its screen under Tools to import it.', 'eizport' ), $site )
			),
			'Requires at least: 7.0'       => 'Requires at least: ' . $line( $header['wp'] ),
			'Requires PHP:      7.4'       => 'Requires PHP:      ' . $line( $header['php'] ),
			'EIZPORT_BUNDLE_FILE'          => $file,
			// The oldest Eizport that reads its bundle: an older one is asked to update first.
			'EIZPORT_REQUIRES'             => \Eizport\Bundle\REQUIRES,
			// Whether its screen starts with "Update items that already exist" checked.
			'EIZPORT_UPDATE_EXISTING'      => ! empty( $GLOBALS['eizport_update_existing'] ) ? 'yes' : 'no',
			// Whether its screen still offers Import again once it imported.
			'EIZPORT_IMPORT_AGAIN'         => ! empty( \Eizport\Settings\get()['plugin_import_again'] ) ? 'yes' : 'no',
			// Its text domain: its own folder's name.
			'EIZPORT_TEXT_DOMAIN'          => $slug,
			"'EIZPORT_MENU_TITLE'"         => var_export( $title, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
			// Its own texts in this site's language, when that isn't English.
			'array( /* EIZPORT_I18N */ );' => var_export( template_translations(), true ) . ';', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP array literal.
		)
	);

	// A short readme.txt, as WordPress.org's Plugin Check asks of any plugin.
	$readme  = strtr(
		(string) file_get_contents( dirname( __DIR__ ) . '/templates/bundle-readme.txt.tpl' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our template.
		array(
			'EIZPORT_PLUGIN_NAME'       => $title,
			'EIZPORT_REQUIRES_WP'       => $line( $header['wp'] ),
			'EIZPORT_TESTED_UP_TO'      => $line( implode( '.', array_slice( explode( '.', (string) get_bloginfo( 'version' ) ), 0, 2 ) ) ),
			'EIZPORT_REQUIRES_PHP'      => $line( $header['php'] ),
			'EIZPORT_SHORT_DESCRIPTION' => $line(
				/* translators: %s: site title. */
				sprintf( __( 'The content exported from %s, with Eizport. Open its screen under Tools to import it.', 'eizport' ), $site )
			),
		)
	);
	$entries = array(
		$slug . '/' . $slug . '.php' => array( 'data' => $code ),
		$slug . '/readme.txt'        => array( 'data' => $readme ),
	);
	if ( 'wxrz' === $mode ) {
		$entries[ $slug . '/' . $file ] = array( 'path' => $bundle );

		return \Eizport\Playground\create_zip( $target, $entries );
	}

	// The bundle's entries, the WXR formatted line by line (as wp eizport export --dir writes them).
	$dir = \Eizport\Files\tmp_folder( wp_generate_password( 24, false ) );
	if ( ! $dir || is_wp_error( \Eizport\Folder\extract( $bundle, $dir ) ) ) {
		$dir && \Eizport\Bundle\delete_folder( $dir );
		return false;
	}
	foreach ( \Eizport\Folder\entries( $dir ) as $name => $path ) {
		$entries[ $slug . '/' . $prefix . $name ] = array( 'path' => $path );
	}
	$written = \Eizport\Playground\create_zip( $target, $entries );
	\Eizport\Bundle\delete_folder( $dir );

	return $written;
}

/**
 * Whether a .zip is one of these plugins: <slug>/<slug>.php and, in the same
 * folder, a .wxrz or the bundle's manifest (there or in bundle/).
 *
 * @param string $path .zip file.
 * @return bool
 */
function is_plugin_zip( $path ) {
	if ( ! class_exists( 'ZipArchive' ) || ! is_file( $path ) ) {
		return false;
	}
	$zip = new \ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return false;
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's.
		$names[] = (string) $zip->getNameIndex( $i );
	}
	$zip->close();
	foreach ( $names as $name ) {
		if ( preg_match( '#^([^/]+)/\1\.php$#', $name, $match ) && preg_grep( '#^' . preg_quote( $match[1], '#' ) . '/([^/]+\.(wxrz|zip)|(' . FOLDER . '/)?' . preg_quote( \Eizport\Bundle\MANIFEST, '#' ) . ')$#', $names ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Takes the bundle out of one of these plugins' .zip, for the WordPress
 * Importer and `wp eizport import`: the .wxrz (or .zip) its header names, or
 * the folder holding its manifest.
 *
 * @param string $path .zip file (is_plugin_zip()).
 * @return string|WP_Error A temporary file, or a bundle folder (delete it with
 *                         \Eizport\Bundle\delete_folder()).
 */
function unwrap_zip( $path ) {
	$none = new WP_Error( 'eizport_bundle_plugin', __( 'The plugin holds no bundle to import.', 'eizport' ) );
	if ( ! class_exists( 'ZipArchive' ) ) {
		return $none;
	}
	$zip = new \ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return $none;
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's.
		$names[] = (string) $zip->getNameIndex( $i );
	}
	$slug = '';
	foreach ( $names as $name ) {
		if ( preg_match( '#^([^/]+)/\1\.php$#', $name, $match ) ) {
			$slug = $match[1];
			break;
		}
	}
	// The header, read as get_file_data() does.
	$main   = '' === $slug ? '' : (string) $zip->getFromName( $slug . '/' . $slug . '.php', 8192 );
	$bundle = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Eizport Bundle:(.*)$/mi', $main, $match ) ? trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ) : '';
	$result = $none;

	if ( in_array( $bundle, array( \Eizport\Bundle\MANIFEST, FOLDER . '/' . \Eizport\Bundle\MANIFEST ), true ) ) {
		// A folder bundle: its files, under the folder the manifest is in.
		$prefix = $slug . '/' . ( FOLDER . '/' . \Eizport\Bundle\MANIFEST === $bundle ? FOLDER . '/' : '' );
		$dir    = \Eizport\Bundle\create_folder();
		$left   = \Eizport\Wxrz\max_bytes();
		$result = $dir;
		foreach ( $names as $i => $name ) {
			$entry = substr( $name, strlen( $prefix ) );
			if ( is_wp_error( $dir ) || 0 !== strpos( $name, $prefix ) || ! \Eizport\Bundle\is_bundle_entry( $entry ) ) {
				continue;
			}
			$left  -= (int) $zip->statIndex( $i )['size'];
			$target = $dir . '/' . $entry;
			if ( $left < 0 || ! wp_mkdir_p( dirname( $target ) ) || false === file_put_contents( $target, (string) $zip->getFromIndex( $i ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				\Eizport\Bundle\delete_folder( $dir );
				$result = new WP_Error( 'eizport_bundle_plugin', __( 'The bundle could not be taken out of the plugin.', 'eizport' ) );
				break;
			}
		}
	} elseif ( preg_match( '#^[^/\\\\]+\.(wxrz|zip)$#i', $bundle ) && in_array( $slug . '/' . $bundle, $names, true ) ) {
		// A .wxrz (or .zip) next to the main file.
		$stat   = $zip->statIndex( (int) $zip->locateName( $slug . '/' . $bundle ) );
		$from   = $zip->getStream( $slug . '/' . $bundle );
		$file   = \Eizport\Files\temp_file( 'eizport-plugin-bundle' );
		$to     = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$copied = $from && $to && (int) $stat['size'] <= \Eizport\Wxrz\max_bytes() && false !== stream_copy_to_stream( $from, $to );
		if ( $from ) {
			fclose( $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $to ) {
			fclose( $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $copied ) {
			wp_delete_file( $file );
		}
		$result = $copied ? $file : new WP_Error( 'eizport_bundle_plugin', __( 'The bundle could not be taken out of the plugin.', 'eizport' ) );
	}
	$zip->close();

	return $result;
}

/**
 * The bundle an installed plugin holds: the file its header names, in its
 * own folder — or, when its header names manifest.json (or
 * bundle/manifest.json), the folder holding it.
 *
 * @param string $plugin_file The plugin's main file.
 * @return string|WP_Error Path.
 */
function bundle_of( $plugin_file ) {
	$plugin_file = (string) realpath( (string) $plugin_file );
	$plugins     = (string) realpath( WP_PLUGIN_DIR );
	if ( '' === $plugin_file || '' === $plugins || 0 !== strpos( $plugin_file, $plugins . DIRECTORY_SEPARATOR ) ) {
		return new WP_Error( 'eizport_bundle_plugin', __( 'This is not an installed plugin.', 'eizport' ) );
	}
	$name = (string) get_file_data( $plugin_file, array( 'bundle' => 'Eizport Bundle' ) )['bundle'];
	$dir  = dirname( $plugin_file );
	if ( in_array( $name, array( \Eizport\Bundle\MANIFEST, FOLDER . '/' . \Eizport\Bundle\MANIFEST ), true ) ) {
		$manifest = (string) realpath( $dir . '/' . $name );
		if ( '' !== $manifest && is_file( $manifest ) && ( dirname( $manifest ) === $dir || dirname( $manifest ) === $dir . DIRECTORY_SEPARATOR . FOLDER ) ) {
			return dirname( $manifest );
		}
		return new WP_Error( 'eizport_bundle_plugin', __( 'The plugin holds no bundle to import.', 'eizport' ) );
	}
	$path = (string) realpath( $dir . '/' . wp_basename( $name ) );
	if ( '' === $name || '' === $path || dirname( $path ) !== $dir || ! is_file( $path ) || ! preg_match( '/\.(wxrz|zip)$/i', $path ) ) {
		return new WP_Error( 'eizport_bundle_plugin', __( 'The plugin holds no bundle to import.', 'eizport' ) );
	}

	return $path;
}

/**
 * The installed plugin with this folder (slug) whose header names a bundle.
 *
 * @param string $slug Folder.
 * @return string Main file, or ''.
 */
function plugin_file( $slug ) {
	$slug = sanitize_title( $slug );
	$file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';

	return '' !== $slug && is_file( $file ) && ! is_wp_error( bundle_of( $file ) ) ? $file : '';
}

/**
 * The screen's slug for a plugin.
 *
 * @param string $slug Plugin folder.
 * @return string
 */
function page( $slug ) {
	return 'eizport-bundle-' . $slug;
}

/**
 * Whether one of these plugins' screens is open.
 *
 * @return bool
 */
function is_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
	return isset( $_GET['page'] ) && 0 === strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'eizport-bundle-' );
}

/**
 * The screen looks like the styled importer (assets/importer.css).
 *
 * @param string $classes Body classes.
 * @return string
 */
function body_class( $classes ) {
	return is_screen() ? $classes . ' eizport-import' : $classes;
}

/**
 * Loads the importer's look, and the busy state of "Run import".
 */
function enqueue() {
	if ( ! is_screen() ) {
		return;
	}
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/eizport.php' );
	$dir  = dirname( __DIR__ ) . '/assets/';
	wp_enqueue_style( 'eizport-importer', $base . 'importer.css', array(), (string) filemtime( $dir . 'importer.css' ) );
	// "Install Now" installs and activates the WordPress Importer here, with core's updates script.
	wp_enqueue_script( 'eizport-bundle-plugin', $base . 'bundle-plugin.js', array( 'updates' ), (string) filemtime( $dir . 'bundle-plugin.js' ), array( 'in_footer' => true ) );
	wp_add_inline_script(
		'eizport-bundle-plugin',
		'window.eizportBundlePlugin = ' . wp_json_encode(
			array(
				'importing'  => __( 'Importing…', 'eizport' ),
				'installing' => __( 'Installing…', 'eizport' ),
				'activating' => __( 'Activating…', 'eizport' ),
				/* translators: %s: plugin name. */
				'installed'  => __( '%s was installed and activated.', 'eizport' ),
				/* translators: %s: error message. */
				'failed'     => __( 'Installation failed: %s', 'eizport' ),
			)
		) . ';',
		'before'
	);
	add_action( 'admin_footer', __NAMESPACE__ . '\install_templates' );
}

/**
 * The notices and the credentials form core's updates script needs.
 */
function install_templates() {
	wp_print_admin_notice_templates();
	wp_print_request_filesystem_credentials_modal();
}

/**
 * Whether the WordPress Importer is installed (the import loads it).
 *
 * @return bool
 */
function importer_installed() {
	return is_file( WP_PLUGIN_DIR . '/' . Import\IMPORTER_PLUGIN );
}

/**
 * Draws a plugin's import screen.
 *
 * @param string $plugin_file The plugin's main file.
 */
function render( $plugin_file ) {
	$slug   = basename( dirname( (string) $plugin_file ) );
	$bundle = bundle_of( $plugin_file );
	$holds  = is_wp_error( $bundle ) ? null : contents( $bundle );
	if ( isset( $holds['error'] ) ) {
		$bundle = new \WP_Error( 'eizport_bundle_newer', $holds['error'] );
		$holds  = null;
	}
	$data     = get_file_data( (string) $plugin_file, array( 'name' => 'Plugin Name' ) );
	$done     = (array) get_option( IMPORTED, array() );
	$result   = get_transient( 'eizport_bundle_result_' . get_current_user_id() . '_' . $slug );
	$old      = importer_installed() ? Import\importer_too_old() : null;
	$ready    = ! is_wp_error( $bundle ) && importer_installed() && ! $old && current_user_can( 'import' );
	$imported = isset( $done[ $slug ] ) || ( is_array( $result ) && empty( $result['error'] ) );
	// Import again, once imported: only when the export allowed it (Settings → Export).
	$again    = 'yes' === strtolower( trim( (string) get_file_data( (string) $plugin_file, array( 'again' => 'Eizport Again' ) )['again'] ) );
	$can_drop = current_user_can( 'delete_plugins' ) && current_user_can( 'deactivate_plugin', plugin_basename( (string) $plugin_file ) );
	$also     = $can_drop ? also_deletable( $slug ) : array();
	$check    = function ( $ok ) {
		return $ok
			? '<span class="eizport-check eizport-check--ok" aria-hidden="true">✓</span> '
			: '<span class="eizport-check eizport-check--no" aria-hidden="true">✗</span> ';
	};
	?>
	<div class="wrap">
		<div class="eizport-import__header">
			<div class="eizport-import__heading">
				<div class="eizport-import__title">
					<svg viewBox="0 0 64 64" width="24" height="24" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#3858e9"/><g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"><path stroke="#ffffff" d="M20 14 14 20l6 6M14 20h32L18 44"/><path stroke="#f2994a" d="M18 44h32m-6-6 6 6-6 6"/></g></svg>
					<h2><?php echo esc_html( $data['name'] ); ?></h2>
				</div>
				<p class="eizport-import__subtitle">
					<?php
					echo esc_html(
						$imported
							? __( 'Imported. This plugin did its job, and it holds a large file: you can remove it now.', 'eizport' )
							: __( 'Imports the content this plugin holds into this site: posts, pages, media and settings, as they were exported.', 'eizport' )
					);
					?>
				</p>
			</div>
			<div class="eizport-import__actions">
				<?php if ( ! $imported || $again ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eizport-bundle-import" id="eizport-bundle-import-form">
						<input type="hidden" name="action" value="eizport_bundle_import" />
						<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
						<?php wp_nonce_field( 'eizport_bundle_import_' . $slug ); ?>
						<button type="submit" class="button<?php echo $imported ? '' : ' button-primary'; ?>" <?php disabled( ! $ready ); ?><?php echo ! $ready && ! is_wp_error( $bundle ) && current_user_can( 'import' ) ? ' data-eizport-needs-importer' : ''; ?>><?php echo esc_html( $imported ? __( 'Import again', 'eizport' ) : __( 'Run import', 'eizport' ) ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( $imported && $can_drop ) : ?>
					<button type="submit" form="eizport-bundle-delete-form" class="button button-primary"><?php echo esc_html( array() !== $also ? __( 'Deactivate and delete', 'eizport' ) : __( 'Deactivate and delete this plugin', 'eizport' ) ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<hr class="wp-header-end">
		<div class="eizport-import__body">
			<?php if ( is_array( $result ) ) : ?>
				<div class="eizport-import__card">
					<h3><?php echo empty( $result['error'] ) ? esc_html__( 'Imported', 'eizport' ) : esc_html__( 'The import stopped', 'eizport' ); ?></h3>
					<?php if ( ! empty( $result['error'] ) ) : ?>
						<p><?php echo esc_html( $result['error'] ); ?></p>
					<?php else : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of posts. */
									_n( '%d item imported or already there.', '%d items imported or already there.', (int) $result['posts'], 'eizport' ),
									(int) $result['posts']
								)
							);
							if ( ! empty( $result['options'] ) ) {
								echo ' ' . esc_html(
									sprintf(
										/* translators: %s: option names. */
										__( 'Settings set: %s.', 'eizport' ),
										implode( ', ', $result['options'] )
									)
								);
							}
							?>
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Visit the site', 'eizport' ); ?></a>
						</p>
						<?php if ( '' !== trim( (string) $result['log'] ) ) : ?>
							<details class="eizport-import__log" open><summary><?php esc_html_e( 'Importer messages', 'eizport' ); ?></summary><pre style="white-space: pre-wrap;"><?php echo esc_html( $result['log'] ); ?></pre></details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $imported && $can_drop ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="eizport-bundle-delete-form" class="eizport-import__card">
					<input type="hidden" name="action" value="eizport_bundle_delete" />
					<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
					<?php wp_nonce_field( 'eizport_bundle_delete_' . $slug ); ?>
					<h3><?php esc_html_e( 'Remove this plugin', 'eizport' ); ?></h3>
					<?php if ( isset( $done[ $slug ] ) ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: date. */
									__( 'Imported on %s. Deactivate and delete removes this plugin and its file.', 'eizport' ),
									wp_date( get_option( 'date_format' ), (int) $done[ $slug ] )
								)
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( array() !== $also ) : ?>
						<fieldset class="eizport-bundle-also">
							<legend><?php esc_html_e( 'Also delete (checked: installed for this import)', 'eizport' ); ?></legend>
							<?php foreach ( $also as $other => $item ) : ?>
								<label>
									<input type="checkbox" name="also[]" value="<?php echo esc_attr( $other ); ?>" <?php checked( $item['checked'] ); ?> />
									<?php echo esc_html( $item['name'] ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
				</form>
			<?php endif; ?>
			<?php if ( $holds ) : ?>
				<details class="eizport-import__card eizport-import__again">
					<summary><?php esc_html_e( 'What it holds', 'eizport' ); ?></summary>
					<ul class="eizport-contents">
						<?php foreach ( $holds['types'] as $type => $titles ) : ?>
							<?php $type_object = get_post_type_object( $type ); ?>
							<li>
								<strong><?php echo esc_html( $type_object ? $type_object->labels->name : $type ); ?></strong> (<?php echo esc_html( number_format_i18n( count( $titles ) ) ); ?>):
								<?php
								$listed = array_slice( array_map( 'wp_strip_all_tags', $titles ), 0, 20 );
								echo esc_html( implode( ', ', $listed ) );
								if ( count( $titles ) > count( $listed ) ) {
									/* translators: %d: number of items not listed. */
									echo esc_html( sprintf( __( ' and %d more', 'eizport' ), count( $titles ) - count( $listed ) ) );
								}
								?>
							</li>
						<?php endforeach; ?>
						<?php if ( $holds['media'] ) : ?>
							<li>
								<strong><?php esc_html_e( 'Media files', 'eizport' ); ?></strong>:
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of files, 2: size. */
										_n( '%1$d file, %2$s', '%1$d files, %2$s', $holds['media'], 'eizport' ),
										$holds['media'],
										size_format( $holds['bytes'] )
									)
								);
								?>
							</li>
						<?php endif; ?>
						<?php if ( $holds['options'] ) : ?>
							<li><strong><?php esc_html_e( 'Options', 'eizport' ); ?></strong>: <?php echo esc_html( implode( ', ', $holds['options'] ) ); ?></li>
						<?php endif; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( ! $imported || $again ) : ?>
				<?php echo $imported ? '<details class="eizport-import__card eizport-import__again"><summary>' . esc_html__( 'Import again', 'eizport' ) . '</summary>' : '<div class="eizport-import__card"><h3>' . esc_html__( 'Before importing', 'eizport' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup with escaped text. ?>
				<ul class="eizport-checks">
					<li><?php echo $check( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?><?php esc_html_e( 'Eizport is active.', 'eizport' ); ?></li>
					<li class="eizport-requirement">
						<?php if ( $old ) : ?>
							<?php echo $check( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
							<?php echo esc_html( $old->get_error_message() ); ?>
							<?php if ( current_user_can( 'update_plugins' ) ) : ?>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( Import\IMPORTER_PLUGIN ) ), 'upgrade-plugin_' . Import\IMPORTER_PLUGIN ) ); ?>"><?php esc_html_e( 'Update now', 'eizport' ); ?></a>
							<?php endif; ?>
						<?php elseif ( importer_installed() ) : ?>
							<?php echo $check( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
							<?php esc_html_e( 'The WordPress Importer is installed.', 'eizport' ); ?>
							<?php if ( Import\importer_untested() ) : ?>
								<?php echo esc_html( Import\importer_untested_message() ); ?>
							<?php endif; ?>
						<?php else : ?>
							<span class="eizport-requirement__before">
								<?php echo $check( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
								<?php esc_html_e( 'The WordPress Importer is needed.', 'eizport' ); ?>
							</span>
							<span class="eizport-requirement__after" hidden>
								<?php echo $check( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
								<?php esc_html_e( 'The WordPress Importer is installed.', 'eizport' ); ?>
							</span>
							<?php if ( current_user_can( 'install_plugins' ) ) : ?>
								<?php // Installs and activates it here (bundle-plugin.js); without JavaScript, core's install screen. ?>
								<a class="button eizport-install-now" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=wordpress-importer' ), 'install-plugin_wordpress-importer' ) ); ?>" data-slug="wordpress-importer" data-plugin="<?php echo esc_attr( Import\IMPORTER_PLUGIN ); ?>" data-name="<?php esc_attr_e( 'WordPress Importer', 'eizport' ); ?>"><?php esc_html_e( 'Install Now', 'eizport' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</li>
					<li>
						<?php echo $check( ! is_wp_error( $bundle ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
						<?php if ( is_wp_error( $bundle ) ) : ?>
							<?php echo esc_html( $bundle->get_error_message() ); ?>
						<?php else : ?>
							<?php
							$shown = is_dir( $bundle ) ? wp_basename( $bundle ) . '/' : wp_basename( $bundle );
							$name  = (string) preg_replace( '/-[a-z0-9]{16}(?=[.\/]|$)/', '', $shown ); // Without the random part of its name.
							$size  = size_format( is_dir( $bundle ) ? array_sum( array_map( 'filesize', \Eizport\Folder\entries( $bundle ) ) ) : (int) filesize( $bundle ) );
							$date  = wp_date( get_option( 'date_format' ), (int) filemtime( $bundle ) );
							echo esc_html(
								empty( $holds['made'] )
									/* translators: 1: file name, 2: size, 3: date. */
									? sprintf( __( 'The bundle: %1$s, %2$s, made %3$s.', 'eizport' ), $name, $size, $date )
									/* translators: 1: file name, 2: size, 3: date, 4: Eizport version. */
									: sprintf( __( 'The bundle: %1$s, %2$s, made %3$s with Eizport %4$s.', 'eizport' ), $name, $size, $date, $holds['made'] )
							);
							?>
						<?php endif; ?>
					</li>
				</ul>
				<p>
					<label>
						<input type="checkbox" form="eizport-bundle-import-form" name="<?php echo esc_attr( Import\UPDATE_FIELD ); ?>" value="1" <?php checked( 'yes' === strtolower( trim( (string) get_file_data( (string) $plugin_file, array( 'update' => 'Eizport Update' ) )['update'] ) ) ); ?> />
						<?php esc_html_e( 'Update pages, posts and products that already exist (same slug) instead of adding copies', 'eizport' ); ?>
					</label>
				</p>
				<?php if ( $imported ) : ?>
					<p class="description"><?php esc_html_e( 'Importing again finds the same items and doesn’t duplicate them, but settings are set again.', 'eizport' ); ?></p>
				<?php endif; ?>
				<?php echo $imported ? '</details>' : '</div>'; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * What a bundle holds, for its plugin's screen: the items by type (titles),
 * the media files and the settings. Read once per bundle file (cached).
 *
 * @param string $bundle The bundle (.wxrz, .zip, or a folder).
 * @return array{types: array<string, string[]>, media: int, bytes: int, options: string[]}|null
 */
function contents( $bundle ) {
	$key    = 'eizport_bundle_contents_' . md5( $bundle . '|' . (int) filemtime( $bundle ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$tar    = \Eizport\Files\temp_file( 'eizport-contents' );
	$packed = is_dir( $bundle ) ? \Eizport\Folder\pack( $bundle, $tar ) : \Eizport\Folder\to_tar( $bundle, $tar );
	$found  = is_wp_error( $packed ) ? $packed : \Eizport\Bundle\read_manifest( $tar );
	if ( is_wp_error( $found ) ) {
		wp_delete_file( $tar );
		// Made by a newer Eizport: why it can't be imported here.
		return 'eizport_bundle_newer' === $found->get_error_code() ? array( 'error' => $found->get_error_message() ) : null;
	}
	$out = array(
		'types'   => array(),
		'media'   => 0,
		'bytes'   => 0,
		'options' => array(),
		'made'    => \Eizport\Bundle\made_with( $found ),
	);
	\Eizport\Tar\each_file(
		$tar,
		function ( $name, $size, $handle ) use ( $found, &$out ) {
			if ( 0 === strpos( $name, 'uploads/' ) ) {
				++$out['media'];
				$out['bytes'] += $size;
				return;
			}
			if ( \Eizport\Options\FILE === $name && $size <= MB_IN_BYTES ) {
				$data           = json_decode( (string) fread( $handle, $size ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$out['options'] = is_array( $data['options'] ?? null ) ? array_keys( $data['options'] ) : array();
				return;
			}
			if ( $found['wxr'][0] !== $name ) {
				return;
			}
			// Each item's title and post type, line by line.
			$left  = $size;
			$title = '';
			$rest  = '';
			while ( $left > 0 ) {
				$chunk = (string) fread( $handle, (int) min( 65536, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( '' === $chunk ) {
					break;
				}
				$left -= strlen( $chunk );
				$lines = explode( "\n", $rest . $chunk );
				$rest  = $left > 0 ? array_pop( $lines ) : '';
				foreach ( $lines as $line ) {
					if ( preg_match( '#^\s*<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>#', $line, $match ) ) {
						$title = html_entity_decode( $match[1], ENT_QUOTES );
					} elseif ( preg_match( '#<wp:post_type>(?:<!\[CDATA\[)?([a-z0-9_-]+)#', $line, $match ) ) {
						$out['types'][ $match[1] ][] = $title;
					}
				}
			}
		}
	);
	wp_delete_file( $tar );
	// Media is listed by its files; the rest by type.
	unset( $out['types']['attachment'] );
	set_transient( $key, $out, DAY_IN_SECONDS );

	return $out;
}

/**
 * The plugins a bundle plugin needed that can go with it: installed, and
 * deletable by the current user. Checked when they weren't there before the
 * bundle plugin was activated (installed for it); unchecked when they were,
 * or when that isn't known.
 *
 * @param string $slug Bundle plugin folder.
 * @return array<string, array{name: string, checked: bool}> Plugin file => what to show.
 */
function also_deletable( $slug ) {
	$before  = (array) ( ( (array) get_option( BEFORE, array() ) )[ $slug ] ?? array() );
	$plugins = array(
		plugin_basename( dirname( __DIR__ ) . '/eizport.php' ) => __( 'Eizport', 'eizport' ),
		Import\IMPORTER_PLUGIN => __( 'WordPress Importer', 'eizport' ),
	);
	$out     = array();
	foreach ( $plugins as $file => $name ) {
		if ( is_file( WP_PLUGIN_DIR . '/' . $file ) && current_user_can( 'deactivate_plugin', $file ) ) {
			$out[ $file ] = array(
				'name'    => $name,
				'checked' => array_key_exists( $file, $before ) && ! $before[ $file ],
			);
		}
	}

	return $out;
}

/**
 * The plugin a request is about, checked: nonce and its main file.
 *
 * @param string $action Nonce action prefix.
 * @return string Main file.
 */
function requested_plugin( $action ) {
	$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below.
	check_admin_referer( $action . $slug );
	$file = plugin_file( $slug );
	if ( '' === $file ) {
		wp_die( esc_html__( 'This is not an installed plugin.', 'eizport' ), 400 );
	}

	return $file;
}

/**
 * "Run import": imports the plugin's bundle, keeps the result for its
 * screen and goes back to it.
 */
function handle_import() {
	if ( ! current_user_can( 'import' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'eizport' ), 403 );
	}
	$file   = requested_plugin( 'eizport_bundle_import_' );
	$slug   = basename( dirname( $file ) );
	$bundle = bundle_of( $file );
	$result = array(
		'posts'   => 0,
		'options' => array(),
		'log'     => '',
	);
	if ( is_wp_error( $bundle ) ) {
		$result['error'] = $bundle->get_error_message();
	} else {
		$GLOBALS['eizport_applied_options'] = array();
		$imported                           = Import\import_file( $bundle, array( 'update_existing' => ! empty( $_POST[ Import\UPDATE_FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in requested_plugin().
		if ( is_wp_error( $imported ) ) {
			$result['error'] = $imported->get_error_message();
		} else {
			$result['posts']   = (int) $imported['posts'];
			$result['log']     = (string) $imported['log'];
			$result['options'] = array_values( (array) ( $GLOBALS['eizport_applied_options'] ?? array() ) );
			$done              = (array) get_option( IMPORTED, array() );
			$done[ $slug ]     = time();
			update_option( IMPORTED, $done, false );
		}
	}
	set_transient( 'eizport_bundle_result_' . get_current_user_id() . '_' . $slug, $result, HOUR_IN_SECONDS );
	wp_safe_redirect( admin_url( 'tools.php?page=' . page( $slug ) ) );
	exit;
}

/**
 * Forgets a bundle plugin that goes away: its line in the options that note
 * which plugins were there before it and that it was imported. An option
 * left empty is deleted.
 *
 * @param string $slug The plugin's folder.
 */
function forget( $slug ) {
	foreach ( array( BEFORE, IMPORTED ) as $option ) {
		$value = (array) get_option( $option, array() );
		unset( $value[ $slug ] );
		if ( $value ) {
			update_option( $option, $value, false );
		} else {
			delete_option( $option );
		}
	}
}

/**
 * "Deactivate and delete this plugin".
 */
function handle_delete() {
	$file = requested_plugin( 'eizport_bundle_delete_' );
	$base = plugin_basename( $file );
	if ( ! current_user_can( 'delete_plugins' ) || ! current_user_can( 'deactivate_plugin', $base ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to delete plugins.', 'eizport' ), 403 );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$slug = basename( dirname( $file ) );
	// The plugins it needed, as checked: only those it offered.
	$asked = isset( $_POST['also'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['also'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in requested_plugin().
	$also  = array_values( array_intersect( array_keys( also_deletable( $slug ) ), $asked ) );
	$files = array_merge( array( $base ), $also );
	delete_transient( 'eizport_bundle_result_' . get_current_user_id() . '_' . $slug );
	forget( $slug );
	deactivate_plugins( $files );
	// delete_plugins() asks for credentials where files can't be written directly: then the Plugins screen does it.
	$deleted = 'direct' === get_filesystem_method() ? delete_plugins( $files ) : false;
	wp_safe_redirect( admin_url( true === $deleted ? 'plugins.php?deleted=1' : 'plugins.php?deactivate=true' ) );
	exit;
}
