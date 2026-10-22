<?php
/**
 * Elementor compatibility, through the plugin's hooks only, with
 * Elementor's own exporter: the "Elementor" row of the Export screen, whose
 * items are the site settings (the active Kit, as Elementor's Kit export
 * writes them) and the templates (Elementor's template file format, as
 * "Export template" writes it), in the bundle's options file; each page
 * built with Elementor as post_content/<slug>.json, in that same format,
 * instead of its _elementor_data in the WXR (with content files on); the
 * images all of them use as media used; the templates and site settings
 * the pages use, suggested. On import, includes/import-elementor.php hands
 * them to Elementor's own importer.
 *
 * Hooked to `eizport_register_compat`, so another copy (Elementor's own)
 * replaces it with
 *
 *     remove_action( 'eizport_register_compat', 'Eizport\Compat\Elementor\register' );
 *
 * @package Eizport
 */

namespace Eizport\Compat\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The meta a page's design file replaces in the WXR.
 */
const DESIGN_META = array( '_elementor_data', '_elementor_page_settings', '_elementor_css' );

/**
 * Hooks the compatibility, when Elementor is active.
 */
function register() {
	// Its documents and Kits come on `init`, after this: they're checked when used (active()).
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		return;
	}
	add_filter( 'eizport_content_types', __NAMESPACE__ . '\content_types' );
	add_filter( 'eizport_look_items', __NAMESPACE__ . '\look_items', 10, 2 );
	add_filter( 'eizport_look_data', __NAMESPACE__ . '\look_data', 10, 3 );
	add_filter( 'eizport_look_suggested', __NAMESPACE__ . '\look_suggested', 10, 3 );
	add_filter( 'eizport_look_media', __NAMESPACE__ . '\look_media', 10, 2 );
	add_filter( 'eizport_media_used', __NAMESPACE__ . '\media_used', 10, 2 );
	add_filter( 'eizport_content_file', __NAMESPACE__ . '\content_file', 10, 2 );
	add_filter( 'wxr_export_skip_postmeta', __NAMESPACE__ . '\skip_design_meta', 10, 2 );
}

/**
 * Whether Elementor (its documents and Kits) is there.
 *
 * @return bool
 */
function active() {
	return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents, \Elementor\Plugin::$instance->kits_manager );
}

/**
 * Elementor's library (templates and Kits) travels in the Elementor row.
 *
 * @param array<string, string> $types Post type => value.
 * @return array<string, string>
 */
function content_types( $types ) {
	unset( $types['elementor_library'] );

	return $types;
}

/**
 * Whether a post is built with Elementor.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function is_built( $post_id ) {
	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

/**
 * The site's templates (not its Kits): ID => post.
 *
 * @return array<int, \WP_Post>
 */
function templates() {
	$out = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'elementor_library',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	) as $post ) {
		if ( 'kit' !== get_post_meta( $post->ID, '_elementor_template_type', true ) ) {
			$out[ $post->ID ] = $post;
		}
	}

	return $out;
}

/**
 * A post's design in Elementor's template file format — what Elementor's
 * "Export template" writes: `content`, `page_settings`, `version`, `title`
 * and `type` — from its document's export.
 *
 * @param int $post_id Post or template.
 * @return array|null
 */
function design( $post_id ) {
	$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
	if ( ! $document ) {
		return null;
	}
	$data = $document->get_export_data();
	if ( ! is_array( $data ) || empty( $data['content'] ) ) {
		return null;
	}

	return array(
		// As Elementor's local source exports it.
		'content'       => apply_filters( 'elementor/template_library/sources/local/export/elements', $data['content'] ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's own filter.
		'page_settings' => $data['settings'] ?? array(),
		'version'       => defined( '\Elementor\DB::DB_VERSION' ) ? \Elementor\DB::DB_VERSION : (string) get_post_meta( $post_id, '_elementor_version', true ),
		'title'         => get_the_title( $post_id ),
		'type'          => 'elementor_library' === get_post_type( $post_id )
			? (string) get_post_meta( $post_id, '_elementor_template_type', true )
			: $document->get_name(),
	);
}

/**
 * The active Kit's settings as Elementor's Kit export writes them (its
 * "site-settings"), without the site's identity (name, tagline, logo,
 * icon).
 *
 * @return array|null
 */
function site_settings() {
	$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
	if ( ! $kit || ! $kit->get_id() ) {
		return null;
	}
	$data = $kit->get_export_data();
	foreach ( array( 'site_name', 'site_description', 'site_logo', 'site_favicon' ) as $key ) {
		unset( $data['settings'][ $key ] );
	}

	return $data;
}

/**
 * The Elementor row's items: the site settings, and each template with its
 * type.
 *
 * @param array  $items Items so far.
 * @param string $row   The look row.
 * @return array
 */
function look_items( $items, $row ) {
	if ( 'elementor' !== $row || ! active() ) {
		return $items;
	}
	$settings = site_settings();
	if ( $settings ) {
		$colors  = array_filter( wp_list_pluck( array_merge( (array) ( $settings['settings']['system_colors'] ?? array() ), (array) ( $settings['settings']['custom_colors'] ?? array() ) ), 'color' ) );
		$items[] = array(
			'key'     => 'kit',
			'label'   => __( 'Site settings', 'eizport' ),
			'details' => __( 'Global colors, fonts, layout and theme style (the Kit)', 'eizport' ),
			'preview' => $colors
				? array(
					'type'  => 'colors',
					'value' => array_values( array_unique( $colors ) ),
				)
				: array(
					'type'  => 'text',
					'value' => get_the_title( \Elementor\Plugin::$instance->kits_manager->get_active_id() ),
				),
		);
	}
	foreach ( templates() as $id => $post ) {
		$items[] = array(
			'key'     => 't:' . $id,
			'label'   => '' === $post->post_title ? '#' . $id : $post->post_title,
			'details' => (string) get_post_meta( $id, '_elementor_template_type', true ),
			'preview' => array(
				'type'  => 'text',
				'value' => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 30 ),
			),
		);
	}

	return $items;
}

/**
 * The options file's `elementor` entry: `site_settings` (the Kit's), and
 * `templates` (old ID => template file).
 *
 * @param array    $data The look's data so far.
 * @param string   $row  The look row.
 * @param string[] $keys Item keys chosen.
 * @return array
 */
function look_data( $data, $row, $keys ) {
	if ( 'elementor' !== $row || ! active() ) {
		return $data;
	}
	$entry = array(
		'title'     => get_bloginfo( 'name' ),
		'templates' => array(),
	);
	if ( in_array( 'kit', $keys, true ) ) {
		$entry['site_settings'] = site_settings();
	}
	foreach ( $keys as $key ) {
		$id = 0 === strpos( (string) $key, 't:' ) ? (int) substr( (string) $key, 2 ) : 0;
		if ( $id && 'elementor_library' === get_post_type( $id ) ) {
			$file = design( $id );
			if ( $file ) {
				$entry['templates'][ $id ] = $file;
			}
		}
	}
	$data['elementor'] = $entry;

	return $data;
}

/**
 * The attachments and templates a design uses: the `id` of each value with
 * an `id` and a `url` (images, gallery items, backgrounds), and each
 * `template_id`.
 *
 * @param mixed $data Design data.
 * @return array{media: int[], templates: int[]}
 */
function used_in( $data ) {
	$found = array(
		'media'     => array(),
		'templates' => array(),
	);
	$walk  = function ( $value ) use ( &$walk, &$found ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( isset( $value['id'], $value['url'] ) && is_numeric( $value['id'] ) ) {
			$found['media'][] = (int) $value['id'];
		}
		if ( isset( $value['template_id'] ) && is_numeric( $value['template_id'] ) ) {
			$found['templates'][] = (int) $value['template_id'];
		}
		array_map( $walk, $value );
	};
	$walk( $data );

	return array_map( 'array_unique', $found );
}

/**
 * A post's design data, from its meta.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function data_of( $post_id ) {
	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );

	return array( is_array( $data ) ? $data : array(), get_post_meta( $post_id, '_elementor_page_settings', true ) );
}

/**
 * The images a page's design uses, as media used.
 *
 * @param int[]    $used Attachment IDs.
 * @param \WP_Post $post The post.
 * @return int[]
 */
function media_used( $used, $post ) {
	return is_built( $post->ID ) ? array_merge( (array) $used, used_in( data_of( $post->ID ) )['media'] ) : $used;
}

/**
 * The images the Elementor row's items chosen use.
 *
 * @param int[] $ids    Attachment IDs.
 * @param array $choice The look chosen.
 * @return int[]
 */
function look_media( $ids, $choice ) {
	if ( ! isset( $choice['elementor'] ) || ! active() ) {
		return $ids;
	}
	$keys = 'all' === $choice['elementor'] ? wp_list_pluck( look_items( array(), 'elementor' ), 'key' ) : (array) $choice['elementor'];
	foreach ( $keys as $key ) {
		if ( 'kit' === $key ) {
			$ids = array_merge( $ids, used_in( site_settings() )['media'] );
		} elseif ( 0 === strpos( (string) $key, 't:' ) ) {
			$ids = array_merge( $ids, used_in( data_of( (int) substr( (string) $key, 2 ) ) )['media'] );
		}
	}

	return $ids;
}

/**
 * The Elementor items the posts chosen use: the site settings when any is
 * built with Elementor, and the templates their template widgets show.
 *
 * @param string[]             $keys   Keys suggested so far.
 * @param string               $row    The look row.
 * @param array<string, int[]> $chosen Post type => IDs chosen.
 * @return string[]
 */
function look_suggested( $keys, $row, $chosen ) {
	if ( 'elementor' !== $row ) {
		return $keys;
	}
	foreach ( $chosen as $ids ) {
		foreach ( array_slice( (array) $ids, 0, 500 ) as $id ) {
			if ( ! is_built( (int) $id ) ) {
				continue;
			}
			$keys[] = 'kit';
			foreach ( used_in( data_of( (int) $id ) )['templates'] as $template ) {
				$keys[] = 't:' . $template;
			}
		}
	}

	return array_values( array_unique( (array) $keys ) );
}

/**
 * With content files on: a page built with Elementor goes as
 * post_content/<slug>.json, its design in Elementor's template file format.
 *
 * @param array|null $file Null, or `extension` and `data`.
 * @param \WP_Post   $post The post.
 * @return array|null
 */
function content_file( $file, $post ) {
	if ( null !== $file || ! is_built( $post->ID ) || ! active() ) {
		return $file;
	}
	$design = design( $post->ID );
	if ( ! $design ) {
		return $file;
	}
	$GLOBALS['eizport_elementor_designed'][ $post->ID ] = true;

	return array(
		'extension' => 'json',
		'data'      => (string) wp_json_encode( $design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
	);
}

/**
 * Leaves out of the WXR the design meta of a page whose design went to its
 * .json file (export_wp() prints the content before the meta).
 *
 * @param bool   $skip     Whether to skip.
 * @param string $meta_key Meta key.
 * @return bool
 */
function skip_design_meta( $skip, $meta_key ) {
	$post = get_post();

	return $skip || ( $post && isset( $GLOBALS['eizport_elementor_designed'][ $post->ID ] ) && in_array( $meta_key, DESIGN_META, true ) );
}
