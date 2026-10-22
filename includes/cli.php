<?php
/**
 * WP-CLI: `wp eizport export` and `wp eizport import <file|folder>`.
 *
 * @package Eizport
 */

namespace Eizport\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the command.
 */
function bootstrap() {
	WP_CLI::add_command( 'eizport import', __NAMESPACE__ . '\import_command' );
	WP_CLI::add_command( 'eizport export', __NAMESPACE__ . '\export_command' );
}

/**
 * Imports a .wxrz bundle (or a gzipped or plain WXR) with the WordPress
 * Importer. Attachment files come from the bundle; posts go to the user
 * given with --user, or else to the first administrator.
 *
 * ## OPTIONS
 *
 * <file>
 * : The .wxrz, .zip (a bundle, or one exported --as-plugin), .xml.gz or
 * .xml file, or a bundle folder (wp eizport export --dir).
 *
 * [--[no-]update-existing]
 * : Update the posts that already exist (same type and slug) instead of
 * adding copies. Default: as the bundle asks, else no.
 *
 * [--css-mode=<mode>]
 * : How the bundle's CSS rules are added: at the end of this site's CSS,
 * at its start, replacing the head/tail blocks of the same name this site
 * already has (replace; with none, at the end), or replacing all of this
 * site's CSS (replace_all). Default: as the bundle asks.
 * ---
 * options:
 *   - end
 *   - start
 *   - replace
 *   - replace_all
 * ---
 *
 * [--[no-]css-markers]
 * : Put the CSS rules between "head" and "tail" comments named after the
 * file. Default: as the bundle asks.
 *
 * ## EXAMPLES
 *
 *     wp eizport import site.wxrz --user=admin
 *     wp eizport import site.wxrz --update-existing
 *
 * @param string[] $args       Positional arguments.
 * @param array    $assoc_args Flags.
 */
function import_command( $args, $assoc_args = array() ) {
	$import = array();
	if ( array_key_exists( 'update-existing', $assoc_args ) ) {
		$import['update_existing'] = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'update-existing' );
	}
	if ( isset( $assoc_args['css-mode'] ) ) {
		$import['css_mode'] = (string) $assoc_args['css-mode'];
	}
	if ( array_key_exists( 'css-markers', $assoc_args ) ) {
		$import['css_markers'] = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'css-markers' );
	}
	$result = \Eizport\Import\import_file( $args[0], $import );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result );
	}

	WP_CLI::log( $result['log'] );
	/* translators: %d: number of posts. */
	WP_CLI::success( sprintf( _n( '%d post imported.', '%d posts imported.', $result['posts'], 'eizport' ), $result['posts'] ) );
}

/**
 * The eizport/export input for the command's flags.
 *
 * @param array $assoc_args Flags.
 * @return array
 */
function export_input( $assoc_args ) {
	$flag  = function ( $name, $fallback = false ) use ( $assoc_args ) {
		// WP-CLI passes --no-<flag> as <flag> => false.
		return array_key_exists( $name, $assoc_args ) ? (bool) $assoc_args[ $name ] : $fallback;
	};
	$input = array(
		'media' => $flag( 'media', true ),
	);
	// --types=page:43+44,product  (a type alone: all of it).
	$types = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['types'] ?? '' ) ) ) ) as $part ) {
		list( $type, $ids )             = array_pad( explode( ':', $part, 2 ), 2, '' );
		$types[ sanitize_key( $type ) ] = '' === $ids ? array() : array( 'ids' => array_values( array_filter( array_map( 'absint', explode( '+', $ids ) ) ) ) );
	}
	if ( $types ) {
		$input['types'] = $types;
	}
	// --options=basics,page_on_front  (groups by key, the rest by name).
	$groups = array_keys( \Eizport\Options\groups() );
	$chosen = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['options'] ?? '' ) ) ) );
	if ( $chosen ) {
		$input['options'] = array(
			'groups' => array_values( array_intersect( $chosen, $groups ) ),
			'names'  => array_values( array_diff( $chosen, $groups ) ),
		);
	}
	// The look: "all", or rows, each whole or with its items (theme_mods:custom_logo+background_color,custom_css:r:1a2b3c4d5e,widgets).
	$look = (string) ( $assoc_args['appearance'] ?? '' );
	if ( 'all' === $look ) {
		$input['appearance'] = \Eizport\Appearance\KEYS;
	} elseif ( '' !== $look ) {
		$input['appearance'] = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $look ) ) ) as $part ) {
			list( $row, $keys )                          = array_pad( explode( ':', $part, 2 ), 2, '' );
			$input['appearance'][ sanitize_key( $row ) ] = '' === $keys || 'all' === $keys ? 'all' : array_values( array_filter( explode( '+', $keys ) ) );
		}
	}
	if ( isset( $assoc_args['css-mode'] ) ) {
		$input['css_mode'] = (string) $assoc_args['css-mode'];
	}
	if ( array_key_exists( 'css-markers', $assoc_args ) ) {
		$input['css_markers'] = $flag( 'css-markers', true );
	}
	// The menus flag: all of them, or classic menu and navigation IDs.
	$menus = (string) ( $assoc_args['menus'] ?? '' );
	if ( 'all' === $menus ) {
		$input['menus'] = array( 'all' => true );
	} elseif ( '' !== $menus ) {
		$input['menus'] = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $menus ) ) ) as $part ) {
			list( $kind, $ids ) = array_pad( explode( ':', $part, 2 ), 2, '' );
			if ( in_array( $kind, array( 'classic', 'navigation' ), true ) ) {
				$input['menus'][ $kind ] = array_values( array_filter( array_map( 'absint', explode( '+', $ids ) ) ) );
			}
		}
	}
	foreach ( array( 'zip', 'as-plugin', 'update-existing' ) as $name ) {
		if ( $flag( $name ) ) {
			$input[ str_replace( '-', '_', $name ) ] = true;
		}
	}
	// --playground: a Playground .zip, with --themes, --plugins, --landing and --language.
	if ( $flag( 'playground' ) ) {
		$list    = function ( $name ) use ( $assoc_args ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args[ $name ] ?? '' ) ) ) ) );
		};
		$landing = (string) ( $assoc_args['landing'] ?? 'home' );
		if ( preg_match( '/^page:(\d+)$/', $landing, $m ) ) {
			$landing = array( 'page' => (int) $m[1] );
		}
		$input['blueprint'] = array(
			'themes'       => $list( 'themes' ),
			'plugins'      => $list( 'plugins' ),
			'landing_page' => $landing,
			'language'     => (string) ( $assoc_args['language'] ?? 'site' ),
		);
	}
	if ( isset( $assoc_args['filename'] ) ) {
		$input['filename'] = (string) $assoc_args['filename'];
	}

	return $input;
}

/**
 * Exports, as the Export screen does, to a copy kept on the site, and
 * prints its path. With --dir, also writes the bundle's entries to a
 * folder (the WXR formatted line by line), to keep a delivery under version
 * control and compare it with the last one.
 *
 * ## OPTIONS
 *
 * [--types=<types>]
 * : Content types, comma-separated; `type:ID+ID` for some items, the type
 * alone for all of it. None: all content.
 *
 * [--options=<options>]
 * : Option groups (like basics) and option names, comma-separated.
 *
 * [--menus=<menus>]
 * : Navigation menus: `all`, or `classic:ID+ID` (menu term IDs, with their
 * items and theme locations) and `navigation:ID+ID` (wp_navigation IDs),
 * comma-separated. Default: none (all content already has them).
 *
 * [--appearance=<parts>]
 * : The site's look: `all`, or rows comma-separated — theme_mods
 * (Customizer), custom_css (Customizer's Additional CSS), global_styles and
 * global_css (Site Editor, block themes) and widgets — each whole, or with
 * the keys of its items after a colon, joined by "+"
 * (theme_mods:custom_logo+background_color). eizport/get-export-options
 * lists the items.
 *
 * [--css-mode=<mode>]
 * : How the CSS rules are added on import: at the end of the other site's
 * CSS, at its start, replacing the head/tail blocks of the same name it
 * already has (replace; with none, at the end), or replacing all its CSS
 * (replace_all). Default: end.
 * ---
 * options:
 *   - end
 *   - start
 *   - replace
 *   - replace_all
 * ---
 *
 * [--[no-]css-markers]
 * : Put the CSS rules between "head" and "tail" comments named after the
 * file. Default: Settings → Export.
 *
 * [--[no-]media]
 * : Put the media files inside. Default: yes.
 *
 * [--zip]
 * : A .zip bundle instead of .wxrz.
 *
 * [--as-plugin]
 * : A plugin .zip that imports its bundle on the other site.
 *
 * [--update-existing]
 * : Ask the import to update the items that already exist (same slug).
 *
 * [--playground]
 * : A WordPress Playground .zip (blueprint, plain WXR, media and content
 * files) instead of a bundle; not with --as-plugin or --update-existing.
 *
 * [--themes=<stylesheets>]
 * : With --playground: themes to install, comma-separated (a child theme
 * brings its parent; this site's theme is activated).
 *
 * [--plugins=<files>]
 * : With --playground: plugins to install, comma-separated plugin files
 * (woocommerce/woocommerce.php), active or inactive as on this site.
 *
 * [--landing=<where>]
 * : With --playground: where it opens — home (default), admin, page:ID (a
 * published page, saved as /?pagename=<its path>) or a path (/shop/).
 *
 * [--language=<language>]
 * : With --playground: the language it opens in — site (this site's, the
 * default), user (yours), en_US or an installed locale (pt_BR).
 *
 * [--filename=<name>]
 * : File name, without extension. With --dir, also the WXR's name there.
 *
 * [--dir=<folder>]
 * : Also write the bundle's files to this folder, replacing the ones a
 * previous export wrote there (other files stay). The WXR there is
 * <--filename>.xml, else <site>.WordPress.xml: the same name every time.
 *
 * ## EXAMPLES
 *
 *     wp eizport export --types=page:43,product --options=basics
 *     wp eizport export --types=page:43 --as-plugin --update-existing
 *     wp eizport export --types=page:43,product --dir=deliverables/bundle
 *     wp eizport export --types=page:43 --playground --themes=twentytwentyfive --plugins=woocommerce/woocommerce.php --landing=page:43 --language=pt_BR
 *
 * @param string[] $args       Positional arguments (none).
 * @param array    $assoc_args Flags.
 */
function export_command( $args, $assoc_args = array() ) {
	unset( $args );
	$copy = \Eizport\Abilities\export( export_input( $assoc_args ) );
	if ( is_wp_error( $copy ) ) {
		WP_CLI::error( $copy );
	}
	$path = \Eizport\Copies\path( $copy['name'] );
	WP_CLI::log( $path );

	if ( ! empty( $assoc_args['dir'] ) ) {
		if ( ! empty( $assoc_args['playground'] ) ) {
			WP_CLI::error( __( '--dir writes a bundle, not a Playground .zip: leave out --playground.', 'eizport' ) );
		}
		if ( ! empty( $assoc_args['as-plugin'] ) ) {
			WP_CLI::error( __( '--dir writes a bundle, not a plugin: leave out --as-plugin.', 'eizport' ) );
		}
		// The WXR keeps one name from one delivery to the next: --filename, else the site's (no date).
		$name     = isset( $assoc_args['filename'] ) ? (string) $assoc_args['filename'] : preg_replace( '/\.\d{4}-\d{2}-\d{2}$/', '', \Eizport\Export\default_filename() );
		$manifest = \Eizport\Folder\extract( $path, (string) $assoc_args['dir'], $name );
		if ( is_wp_error( $manifest ) ) {
			WP_CLI::error( $manifest );
		}
		/* translators: %s: folder. */
		WP_CLI::success( sprintf( __( 'Bundle written to %s.', 'eizport' ), $assoc_args['dir'] ) );
		return;
	}
	/* translators: %s: file name. */
	WP_CLI::success( sprintf( __( '%s is ready.', 'eizport' ), $copy['name'] ) );
}
