<?php
/**
 * The site's look, each on its own row of the Export screen: the
 * Customizer's theme mods and custom CSS, the Site Editor's global styles
 * and global CSS (block themes), and the widgets (classic and block ones).
 * They travel in the bundle's options file (options.json): the theme mods
 * and widget options as options, the rest under keys of their own, which
 * apply-options.php sets when the import ends.
 *
 * The export args carry the choice as `eizport_appearance` (the keys
 * below); Export\file_output() adds it to the options file, and
 * Selection\where() the media it uses.
 *
 * @package Eizport
 */

namespace Eizport\Appearance;

defined( 'ABSPATH' ) || exit;

/**
 * What can be exported, in the screen's order.
 */
const KEYS = array( 'theme_mods', 'custom_css', 'global_styles', 'global_css', 'widgets' );

/**
 * What the site has, for the Export screen's rows: key => count (0: the row
 * isn't shown).
 *
 * @return array<string, int>
 */
function available() {
	$styles = global_styles();
	unset( $styles['styles']['css'] );

	return array(
		'theme_mods'    => count( theme_mods() ) ? 1 : 0,
		'custom_css'    => '' !== trim( (string) wp_get_custom_css() ) ? 1 : 0,
		'global_styles' => has_styles( $styles ) ? 1 : 0,
		'global_css'    => '' !== trim( (string) ( global_styles()['styles']['css'] ?? '' ) ) ? 1 : 0,
		'widgets'       => count( widget_ids() ),
	);
}

/**
 * The active theme's Customizer settings worth carrying: its theme mods but
 * the ones that aren't a look (the custom CSS post, which has its own row;
 * the menus' locations, which go with the Menus row; core's bookkeeping).
 *
 * @return array
 */
function theme_mods() {
	$mods = (array) get_option( 'theme_mods_' . get_stylesheet(), array() );
	// Core keeps a switched-away theme's widgets and sidebars here too: not settings.
	unset( $mods['custom_css_post_id'], $mods['nav_menu_locations'], $mods['sidebars_widgets'], $mods['wp_classic_sidebars'], $mods[0] );

	return array_filter(
		$mods,
		function ( $value ) {
			return null !== $value && '' !== $value && false !== $value;
		}
	);
}

/**
 * Whether global styles hold more than their version and flag.
 *
 * @param array $styles Decoded wp_global_styles content.
 * @return bool
 */
function has_styles( $styles ) {
	unset( $styles['version'], $styles['isGlobalStylesUserThemeJSON'] );

	return (bool) array_filter( $styles );
}

/**
 * The user's global styles of the active theme (the Site Editor's), decoded;
 * none for a classic theme. Never creates the post, as core's getter does.
 *
 * @return array
 */
function global_styles() {
	if ( ! wp_is_block_theme() || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return array();
	}
	$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false );
	$data = isset( $post['post_content'] ) ? json_decode( (string) $post['post_content'], true ) : null;

	return is_array( $data ) ? $data : array();
}

/**
 * The widgets placed in the active theme's sidebars, as "id_base-N": a theme
 * without sidebars (a block theme) has none, whatever an earlier theme left.
 *
 * @return string[]
 */
function widget_ids() {
	global $wp_registered_sidebars;

	// A block theme shows no sidebars: core only keeps the classic theme's registered to manage them.
	if ( wp_is_block_theme() ) {
		return array();
	}
	$ids = array();
	foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $widgets ) {
		if ( isset( $wp_registered_sidebars[ $sidebar ] ) && is_array( $widgets ) ) {
			$ids = array_merge( $ids, array_map( 'strval', $widgets ) );
		}
	}

	return $ids;
}

/**
 * The widget options the placed widgets use (widget_block, widget_text…).
 *
 * @return string[]
 */
function widget_options() {
	$names = array();
	foreach ( widget_ids() as $id ) {
		if ( preg_match( '/^(.+)-\d+$/', $id, $m ) ) {
			$names[] = 'widget_' . $m[1];
		}
	}

	return array_values( array_unique( $names ) );
}

/**
 * The choice in the export args, cleaned.
 *
 * @param array $args Export args.
 * @return string[] Keys.
 */
function chosen( $args ) {
	return array_values( array_intersect( KEYS, array_map( 'strval', (array) ( $args['eizport_appearance'] ?? array() ) ) ) );
}

/**
 * The options file's data for the choice: options (theme mods, widget
 * options) and the custom_css, global_styles and global_css entries.
 *
 * @param string[] $keys Keys chosen.
 * @return array{options: array, custom_css?: array, global_styles?: array, global_css?: array}
 */
function data( $keys ) {
	$stylesheet = get_stylesheet();
	$out        = array( 'options' => array() );
	$names      = array();
	if ( in_array( 'theme_mods', $keys, true ) ) {
		$names[] = 'theme_mods_' . $stylesheet;
	}
	if ( in_array( 'widgets', $keys, true ) && widget_ids() ) {
		$names = array_merge( $names, array( 'sidebars_widgets' ), widget_options() );
	}
	foreach ( $names as $name ) {
		$value = get_option( $name, null );
		if ( null === $value ) {
			continue;
		}
		// Only the look: the custom CSS and the menus' locations have their own rows.
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			$value = theme_mods();
		}
		$out['options'][ $name ] = $value;
	}

	if ( in_array( 'custom_css', $keys, true ) && '' !== trim( (string) wp_get_custom_css() ) ) {
		$out['custom_css'] = array(
			'stylesheet' => $stylesheet,
			'css'        => (string) wp_get_custom_css(),
		);
	}
	$styles = global_styles();
	if ( in_array( 'global_css', $keys, true ) && '' !== trim( (string) ( $styles['styles']['css'] ?? '' ) ) ) {
		$out['global_css'] = array(
			'stylesheet' => $stylesheet,
			'css'        => (string) $styles['styles']['css'],
		);
	}
	unset( $styles['styles']['css'] );
	if ( in_array( 'global_styles', $keys, true ) && has_styles( $styles ) ) {
		$out['global_styles'] = array(
			'stylesheet' => $stylesheet,
			'content'    => $styles,
		);
	}

	return $out;
}

/**
 * The attachments the choice uses: the logo, header and background images,
 * the media and block widgets' media, the images the styles and the CSS
 * point to.
 *
 * @param string[] $keys Keys chosen.
 * @return int[] Attachment IDs.
 */
function media_ids( $keys ) {
	$data = data( $keys );
	$ids  = array();
	$urls = array();
	$base = wp_get_upload_dir()['baseurl'];
	foreach ( $data['options'] as $name => $value ) {
		if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
			$ids[] = (int) ( $value['custom_logo'] ?? 0 );
			// Core saves the header image's data as an object.
			$header = (array) ( $value['header_image_data'] ?? array() );
			$ids[]  = (int) ( $header['attachment_id'] ?? 0 );
			$urls[] = (string) ( $value['header_image'] ?? '' );
			$urls[] = (string) ( $value['background_image'] ?? '' );
		}
		if ( 0 === strpos( $name, 'widget_' ) && is_array( $value ) ) {
			foreach ( $value as $widget ) {
				if ( ! is_array( $widget ) ) {
					continue;
				}
				$ids[]  = (int) ( $widget['attachment_id'] ?? 0 );
				$urls[] = (string) ( $widget['url'] ?? '' );
				if ( isset( $widget['content'] ) ) {
					$ids = array_merge( $ids, \Eizport\Selection\ids_in_content( (string) $widget['content'], $base ) );
				}
			}
		}
	}
	$text = wp_json_encode( array( $data['custom_css'] ?? null, $data['global_styles'] ?? null, $data['global_css'] ?? null ), JSON_UNESCAPED_SLASHES );
	if ( preg_match_all( '#' . preg_quote( $base, '#' ) . '/[^"\'\s)]+#', (string) $text, $found ) ) {
		$urls = array_merge( $urls, $found[0] );
	}
	foreach ( array_filter( $urls ) as $url ) {
		$ids[] = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
	}

	return array_values(
		array_filter(
			array_unique( array_map( 'intval', $ids ) ),
			function ( $id ) {
				return $id && 'attachment' === get_post_type( $id );
			}
		)
	);
}
