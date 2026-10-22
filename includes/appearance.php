<?php
/**
 * The site's look, each on its own row of the Export screen: the
 * Customizer's theme mods and custom CSS, the Site Editor's global styles
 * and global CSS (block themes), and the widgets (classic and block ones).
 * They travel in the bundle's options file (options.json): the theme mods
 * and widget options as options, the rest under keys of their own, which
 * apply-options.php sets when the import ends.
 *
 * Each row lists its items (items()): a theme mod, a CSS rule, a group of
 * global styles, a widget. The export args carry the choice as
 * `eizport_appearance`: row => "all" or item keys (a plain list of rows
 * means each of them whole). Export\file_output() adds it to the options
 * file, and Selection\where() the media it uses.
 *
 * @package Eizport
 */

namespace Eizport\Appearance;

defined( 'ABSPATH' ) || exit;

// The CSS splitter and dotted paths, shared with the import (and Playground's must-use plugin).
require_once __DIR__ . '/apply-options.php';

/**
 * What can be exported, in the screen's order.
 */
const KEYS = array( 'theme_mods', 'custom_css', 'global_styles', 'global_css', 'widgets', 'advanced-custom-fields', 'elementor' );

/**
 * The rows a plugin's compatibility fills (through eizport_look_items,
 * eizport_look_data, eizport_look_suggested and eizport_look_media), with
 * that plugin active.
 */
const PLUGIN_ROWS = array( 'advanced-custom-fields', 'elementor' );

/**
 * What the site has, for the Export screen's rows: key => number of items
 * (0: the row isn't shown).
 *
 * @return array<string, int>
 */
function available() {
	$counts = array();
	foreach ( KEYS as $key ) {
		$counts[ $key ] = count( items( $key ) );
	}

	return $counts;
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
 * The groups of global styles, each one item: key => label and the dotted
 * paths it holds. A block with its own styles is a group of its own
 * ("block:core/button"); what no group holds is "Other".
 *
 * @param array $styles Decoded global styles.
 * @return array<string, array{label: string, paths: string[]}>
 */
function style_groups( $styles ) {
	$groups = array(
		'palette'    => array(
			'label' => __( 'Color palette', 'eizport' ),
			'paths' => array( 'settings.color.palette', 'settings.color.gradients', 'settings.color.duotone' ),
		),
		'colors'     => array(
			'label' => __( 'Site colors', 'eizport' ),
			'paths' => array( 'styles.color' ),
		),
		'typography' => array(
			'label' => __( 'Typography', 'eizport' ),
			'paths' => array( 'settings.typography', 'styles.typography' ),
		),
		'spacing'    => array(
			'label' => __( 'Spacing and layout', 'eizport' ),
			'paths' => array( 'settings.spacing', 'settings.layout', 'styles.spacing', 'styles.dimensions' ),
		),
		'elements'   => array(
			'label' => __( 'Elements: links, buttons, headings', 'eizport' ),
			'paths' => array( 'styles.elements' ),
		),
	);
	foreach ( array_keys( (array) ( $styles['styles']['blocks'] ?? array() ) ) as $block ) {
		$type                        = class_exists( 'WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $block ) : null;
		$groups[ 'block:' . $block ] = array(
			/* translators: %s: a block's name, like "Button". */
			'label' => sprintf( __( 'Block: %s', 'eizport' ), $type && $type->title ? $type->title : $block ),
			'paths' => array( 'styles.blocks.' . $block ),
		);
	}
	// Whatever else the styles hold.
	$covered = array_merge( ...array_values( wp_list_pluck( $groups, 'paths' ) ) );
	$other   = array();
	foreach ( array( 'settings', 'styles' ) as $top ) {
		foreach ( array_keys( (array) ( $styles[ $top ] ?? array() ) ) as $key ) {
			$path = $top . '.' . $key;
			if ( 'styles.css' === $path || 'styles.blocks' === $path || in_array( $path, $covered, true ) ) {
				continue;
			}
			foreach ( array_keys( is_array( $styles[ $top ][ $key ] ) ? $styles[ $top ][ $key ] : array( 0 ) ) as $sub ) {
				$full = is_array( $styles[ $top ][ $key ] ) ? $path . '.' . $sub : $path;
				if ( ! in_array( $full, $covered, true ) ) {
					$other[] = $full;
				}
			}
		}
	}
	if ( $other ) {
		$groups['other'] = array(
			'label' => __( 'Other styles', 'eizport' ),
			'paths' => array_values( array_unique( $other ) ),
		);
	}

	// Only the groups these styles have something in.
	return array_filter(
		$groups,
		function ( $group ) use ( $styles ) {
			foreach ( $group['paths'] as $path ) {
				if ( null !== eizport_path_get( $styles, $path ) ) {
					return true;
				}
			}
			return false;
		}
	);
}

/**
 * The widgets placed in the active theme's sidebars, as "id_base-N" =>
 * sidebar: a theme without sidebars (a block theme) has none, whatever an
 * earlier theme left.
 *
 * @return array<string, string>
 */
function widget_sidebars() {
	global $wp_registered_sidebars;

	// A block theme shows no sidebars: core only keeps the classic theme's registered to manage them.
	if ( wp_is_block_theme() ) {
		return array();
	}
	$ids = array();
	foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $widgets ) {
		if ( isset( $wp_registered_sidebars[ $sidebar ] ) && is_array( $widgets ) ) {
			foreach ( $widgets as $id ) {
				$ids[ (string) $id ] = (string) $sidebar;
			}
		}
	}

	return $ids;
}

/**
 * A widget's settings.
 *
 * @param string $id "id_base-N".
 * @return array|null
 */
function widget( $id ) {
	if ( ! preg_match( '/^(.+)-(\d+)$/', $id, $m ) ) {
		return null;
	}
	$all = (array) get_option( 'widget_' . $m[1], array() );

	return isset( $all[ (int) $m[2] ] ) && is_array( $all[ (int) $m[2] ] ) ? $all[ (int) $m[2] ] : null;
}

/**
 * A CSS rule's key: a hash of its text, so a choice saved as a default
 * still finds it when other rules move.
 *
 * @param string $rule Rule.
 * @return string
 */
function rule_key( $rule ) {
	return 'r:' . substr( md5( eizport_css_normalize( $rule ) ), 0, 10 );
}

/**
 * The CSS of a row: the Customizer's Additional CSS, or the Site Editor's.
 *
 * @param string $key custom_css or global_css.
 * @return string
 */
function css_of( $key ) {
	return 'custom_css' === $key ? (string) wp_get_custom_css() : (string) ( global_styles()['styles']['css'] ?? '' );
}

/**
 * Names for core's theme mods.
 *
 * @return array<string, string>
 */
function mod_labels() {
	return array(
		'custom_logo'       => __( 'Logo', 'eizport' ),
		'header_image'      => __( 'Header image', 'eizport' ),
		'header_image_data' => __( 'Header image details', 'eizport' ),
		'header_textcolor'  => __( 'Header text color', 'eizport' ),
		'background_image'  => __( 'Background image', 'eizport' ),
		'background_color'  => __( 'Background color', 'eizport' ),
	);
}

/**
 * A row's items, for its side panel: key, label, details (where it is,
 * which one) and a preview (`type` image, color, colors, code or text, and
 * its `value`).
 *
 * @param string $row theme_mods, custom_css, global_styles, global_css or widgets.
 * @return array<int, array{key: string, label: string, details: string, preview: array{type: string, value: mixed}}>
 */
function items( $row ) {
	$items = array();
	if ( 'theme_mods' === $row ) {
		$labels = mod_labels();
		foreach ( theme_mods() as $name => $value ) {
			$preview = array(
				'type'  => 'text',
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
			if ( 'custom_logo' === $name && wp_attachment_is_image( (int) $value ) ) {
				$preview = array(
					'type'  => 'image',
					'value' => (string) wp_get_attachment_image_url( (int) $value, 'thumbnail' ),
				);
			} elseif ( is_string( $value ) && preg_match( '#^https?://.+\.(png|jpe?g|gif|webp|avif|svg)$#i', $value ) ) {
				$preview = array(
					'type'  => 'image',
					'value' => $value,
				);
			} elseif ( is_string( $value ) && preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
				$preview = array(
					'type'  => 'color',
					'value' => '#' . ltrim( $value, '#' ),
				);
			}
			$items[] = array(
				'key'     => (string) $name,
				'label'   => $labels[ $name ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', (string) $name ) ),
				'details' => (string) $name,
				'preview' => $preview,
			);
		}
	} elseif ( 'custom_css' === $row || 'global_css' === $row ) {
		$rules = eizport_css_rules( css_of( $row ) );
		$total = count( $rules );
		$seen  = array();
		foreach ( $rules as $index => $rule ) {
			$key          = rule_key( $rule );
			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			$key         .= $seen[ $key ] > 1 ? '-' . $seen[ $key ] : '';
			$selector     = trim( (string) preg_replace( '#/\*.*?\*/#s', '', (string) strtok( $rule, '{' ) ) );
			$items[]      = array(
				'key'     => $key,
				'label'   => '' !== $selector ? wp_html_excerpt( $selector, 80, '…' ) : __( 'Comment', 'eizport' ),
				/* translators: 1: a CSS rule's position, 2: how many rules there are. */
				'details' => $index + 1 === $total && $total > 1 ? sprintf( __( 'Rule %1$d of %2$d (the last)', 'eizport' ), $index + 1, $total ) : sprintf( __( 'Rule %1$d of %2$d', 'eizport' ), $index + 1, $total ),
				'preview' => array(
					'type'  => 'code',
					'value' => $rule,
				),
			);
		}
	} elseif ( 'global_styles' === $row ) {
		$styles = global_styles();
		foreach ( style_groups( $styles ) as $key => $group ) {
			$values = array();
			foreach ( $group['paths'] as $path ) {
				$values[ $path ] = eizport_path_get( $styles, $path );
			}
			$text = (string) wp_json_encode( array_filter( $values, fn( $value ) => null !== $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			preg_match_all( '/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/i', $text, $colors );
			$items[] = array(
				'key'     => (string) $key,
				'label'   => $group['label'],
				'details' => implode( ', ', array_keys( array_filter( $values, fn( $value ) => null !== $value ) ) ),
				'preview' => $colors[0]
					? array(
						'type'  => 'colors',
						'value' => array_slice( array_values( array_unique( array_map( 'strtolower', $colors[0] ) ) ), 0, 8 ),
					)
					: array(
						'type'  => 'code',
						'value' => wp_html_excerpt( $text, 300, '…' ),
					),
			);
		}
	} elseif ( 'widgets' === $row ) {
		global $wp_registered_sidebars, $wp_widget_factory;
		$names = array();
		foreach ( (array) ( $wp_widget_factory->widgets ?? array() ) as $object ) {
			$names[ $object->id_base ] = $object->name;
		}
		foreach ( widget_sidebars() as $id => $sidebar ) {
			$settings = (array) widget( $id );
			$id_base  = (string) preg_replace( '/-\d+$/', '', $id );
			$text     = trim( wp_strip_all_tags( (string) ( $settings['content'] ?? $settings['text'] ?? '' ) ) );
			$image    = (string) ( $settings['url'] ?? '' );
			$items[]  = array(
				'key'     => $id,
				'label'   => trim( ( $names[ $id_base ] ?? ucfirst( str_replace( '_', ' ', $id_base ) ) ) . ( empty( $settings['title'] ) ? '' : ': ' . $settings['title'] ) ),
				'details' => (string) ( $wp_registered_sidebars[ $sidebar ]['name'] ?? $sidebar ),
				'preview' => '' !== $image ? array(
					'type'  => 'image',
					'value' => $image,
				) : array(
					'type'  => 'text',
					'value' => wp_html_excerpt( $text, 160, '…' ),
				),
			);
		}
	}

	/**
	 * Filters the items of a look row: a plugin fills its own row (the Advanced Custom Fields
	 * row: key, label, details and preview, as the others).
	 *
	 * @param array  $items Items.
	 * @param string $row   The row (a key of KEYS).
	 */
	return (array) apply_filters( 'eizport_look_items', $items, $row );
}

/**
 * The choice, cleaned: row => "all" or item keys. A plain list of rows
 * (bundles, defaults and calls from before items) means each row whole.
 *
 * @param mixed $value The choice.
 * @return array<string, string|string[]>
 */
function normalize( $value ) {
	$value = (array) $value;
	// array_is_list() is PHP 8.1.
	if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
		$value = array_fill_keys( array_map( 'strval', $value ), 'all' );
	}
	$out = array();
	foreach ( KEYS as $key ) {
		if ( ! isset( $value[ $key ] ) ) {
			continue;
		}
		if ( 'all' === $value[ $key ] || true === $value[ $key ] ) {
			$out[ $key ] = 'all';
		} elseif ( is_array( $value[ $key ] ) && $value[ $key ] ) {
			$out[ $key ] = array_values( array_unique( array_map( 'strval', $value[ $key ] ) ) );
		}
	}

	return $out;
}

/**
 * The choice in the export args, cleaned.
 *
 * @param array $args Export args.
 * @return array<string, string|string[]>
 */
function chosen( $args ) {
	return normalize( $args['eizport_appearance'] ?? array() );
}

/**
 * The keys chosen of a row, among the ones it has.
 *
 * @param array  $choice normalize()'s.
 * @param string $row    Row.
 * @return string[]
 */
function keys_of( $choice, $row ) {
	if ( ! isset( $choice[ $row ] ) ) {
		return array();
	}
	$have = wp_list_pluck( items( $row ), 'key' );

	return 'all' === $choice[ $row ] ? $have : array_values( array_intersect( $have, (array) $choice[ $row ] ) );
}

/**
 * The CSS of the rules chosen, in their order.
 *
 * @param string   $row  custom_css or global_css.
 * @param string[] $keys Rule keys.
 * @return string
 */
function css_chosen( $row, $keys ) {
	$keep = array();
	foreach ( items( $row ) as $item ) {
		if ( in_array( $item['key'], $keys, true ) ) {
			$keep[] = $item['preview']['value'];
		}
	}

	return implode( "\n\n", $keep );
}

/**
 * The block ID an export's CSS goes under: its file name (without
 * extension), the same for each delivery of the same file.
 *
 * @param string $filename The file's name, without extension.
 * @return string
 */
function css_id( $filename ) {
	$filename = '' !== (string) $filename ? (string) $filename : \Eizport\Export\default_filename();

	return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename ), '-' );
}

/**
 * The options file's data for the choice: options (theme mods), and the
 * custom_css, global_styles, global_css and widgets entries.
 *
 * @param array $choice normalize()'s (or a list of rows).
 * @param array $css    `mode` (end, start, replace, replace_all), `markers` and `filename`, for the CSS entries.
 * @return array{options: array, custom_css?: array, global_styles?: array, global_css?: array, widgets?: array}
 */
function data( $choice, $css = array() ) {
	$choice     = normalize( $choice );
	$stylesheet = get_stylesheet();
	$out        = array( 'options' => array() );
	$mode       = in_array( $css['mode'] ?? '', array( 'end', 'start', 'replace', 'replace_all' ), true ) ? $css['mode'] : 'end';
	$markers    = array_key_exists( 'markers', $css ) ? (bool) $css['markers'] : true;
	$id         = css_id( (string) ( $css['filename'] ?? '' ) );

	$mods = keys_of( $choice, 'theme_mods' );
	if ( $mods ) {
		$out['options'][ 'theme_mods_' . $stylesheet ] = array_intersect_key( theme_mods(), array_flip( $mods ) );
	}
	foreach ( array( 'custom_css', 'global_css' ) as $row ) {
		$rules = keys_of( $choice, $row );
		if ( $rules ) {
			$out[ $row ] = array(
				'stylesheet' => $stylesheet,
				'css'        => css_chosen( $row, $rules ),
				'mode'       => $mode,
				'markers'    => $markers,
				'id'         => $id,
			);
		}
	}
	$groups = keys_of( $choice, 'global_styles' );
	if ( $groups ) {
		$styles = global_styles();
		$all    = style_groups( $styles );
		$paths  = array();
		foreach ( $groups as $group ) {
			$paths = array_merge( $paths, $all[ $group ]['paths'] ?? array() );
		}
		$content = array(
			'version'                     => $styles['version'] ?? 2,
			'isGlobalStylesUserThemeJSON' => true,
		);
		foreach ( $paths as $path ) {
			$value = eizport_path_get( $styles, $path );
			if ( null !== $value ) {
				$content = eizport_path_set( $content, $path, $value );
			}
		}
		$out['global_styles'] = array(
			'stylesheet' => $stylesheet,
			'content'    => $content,
			'paths'      => array_values( array_unique( $paths ) ),
		);
	}
	$widgets = keys_of( $choice, 'widgets' );
	if ( $widgets ) {
		$where = widget_sidebars();
		$entry = array(
			'id'        => $id,
			'sidebars'  => array(),
			'instances' => array(),
		);
		foreach ( $widgets as $widget ) {
			$settings = widget( $widget );
			if ( null === $settings ) {
				continue;
			}
			$entry['instances'][ $widget ]            = $settings;
			$entry['sidebars'][ $where[ $widget ] ][] = $widget;
		}
		if ( $entry['instances'] ) {
			$out['widgets'] = $entry;
		}
	}

	// A plugin's row (Advanced Custom Fields) fills its own entry.
	foreach ( PLUGIN_ROWS as $row ) {
		$keys = keys_of( $choice, $row );
		if ( $keys ) {
			/**
			 * Filters the options file's data for a look row a plugin adds
			 * (the Advanced Custom Fields row): the row's entry goes in $out.
			 *
			 * @param array    $out  Data so far.
			 * @param string   $row  The row (a key of KEYS).
			 * @param string[] $keys Item keys chosen of that row.
			 */
			$out = (array) apply_filters( 'eizport_look_data', $out, $row, $keys );
		}
	}

	return $out;
}

/**
 * The attachments the choice uses: the logo, header and background images,
 * the media and block widgets' media, the images the styles and the CSS
 * point to.
 *
 * @param array $choice normalize()'s (or a list of rows).
 * @return int[] Attachment IDs.
 */
function media_ids( $choice ) {
	$data = data( $choice );
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
	}
	foreach ( (array) ( $data['widgets']['instances'] ?? array() ) as $widget ) {
		$ids[]  = (int) ( $widget['attachment_id'] ?? 0 );
		$urls[] = (string) ( $widget['url'] ?? '' );
		if ( isset( $widget['content'] ) ) {
			$ids = array_merge( $ids, \Eizport\Selection\ids_in_content( (string) $widget['content'], $base ) );
		}
	}
	$text = wp_json_encode( array( $data['custom_css'] ?? null, $data['global_styles'] ?? null, $data['global_css'] ?? null ), JSON_UNESCAPED_SLASHES );
	if ( preg_match_all( '#' . preg_quote( $base, '#' ) . '/[^"\'\s)]+#', (string) $text, $found ) ) {
		$urls = array_merge( $urls, $found[0] );
	}
	foreach ( array_filter( $urls ) as $url ) {
		$ids[] = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
	}

	/**
	 * Filters the attachments the look chosen uses: a plugin's row adds
	 * the ones its items use (the Elementor row: images of templates and
	 * site settings).
	 *
	 * @param int[] $ids    Attachment IDs.
	 * @param array $choice normalize()'s.
	 */
	$ids = (array) apply_filters( 'eizport_look_media', $ids, normalize( $choice ) );

	return array_values(
		array_filter(
			array_unique( array_map( 'intval', $ids ) ),
			function ( $id ) {
				return $id && 'attachment' === get_post_type( $id );
			}
		)
	);
}
