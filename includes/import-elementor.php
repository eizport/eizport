<?php
/**
 * Elementor on import, with Elementor's own importer: when the import ends,
 * the templates a bundle carries (Elementor's template file format, in the
 * options file's `elementor` entry) are created as Elementor's Kit import
 * does (documents->create(), then the document's import()), the site
 * settings go through Elementor's own Site_Settings runner (a new active
 * Kit, merged with the one there), and each imported page built with
 * Elementor gets its design — the post_content/<slug>.json of the bundle,
 * or its _elementor_data — through the document's import().
 *
 * Elementor's import downloads the images again unless it knows them: the
 * attachments the WordPress Importer brought get the
 * `_elementor_source_image_hash` Elementor looks for, so they're reused.
 * What it doesn't map — the template_id of template widgets and links to
 * the source site — is mapped in the data before. Without Elementor, the
 * design is written to the page's meta, with its media mapped here.
 *
 * Loaded by the plugin and appended to the temporary must-use plugin of a
 * Playground .zip (playground-media.php): no namespace, no other files.
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_elementor_start' ) ) {
	/**
	 * When an import starts: keeps the bundle's Elementor entry (templates
	 * and site settings) for the end.
	 *
	 * @param array|null $data The options file's data.
	 */
	function eizport_elementor_start( $data ) {
		$entry = is_array( $data ) && isset( $data['elementor'] ) && is_array( $data['elementor'] ) ? $data['elementor'] : null;
		if ( $entry ) {
			$GLOBALS['eizport_elementor_entry'] = $entry;
		}
	}

	/**
	 * Whether Elementor's document import is there.
	 *
	 * @return bool
	 */
	function eizport_elementor_active() {
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents );
	}

	/**
	 * The design data with what Elementor's import doesn't map: template
	 * widgets' `template_id`, and URLs of the source site (links) turned
	 * into this site's; without Elementor (`$media`), media too — `id` and
	 * `url` of each image, gallery item or background.
	 *
	 * @param mixed $data  Elements, settings, or any value in them.
	 * @param array $maps  `posts` (old ID => new), `templates` (old ID => new), `urls` (old URL => new, longest first).
	 * @param bool  $media Whether to map media here.
	 * @return mixed
	 */
	function eizport_elementor_map( $data, $maps, $media = false ) {
		if ( is_string( $data ) ) {
			return $maps['urls'] ? strtr( $data, $maps['urls'] ) : $data;
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$is_media = $media && isset( $data['id'], $data['url'] ) && is_numeric( $data['id'] );
		foreach ( $data as $key => $value ) {
			if ( 'template_id' === $key && is_numeric( $value ) ) {
				$old          = (int) $value;
				$new          = $maps['templates'][ $old ] ?? $maps['posts'][ $old ] ?? null;
				$data[ $key ] = null === $new ? $value : ( is_int( $value ) ? (int) $new : (string) $new );
				continue;
			}
			if ( $is_media && 'id' === $key ) {
				$new          = $maps['posts'][ (int) $value ] ?? null;
				$data[ $key ] = null === $new ? $value : ( is_int( $value ) ? (int) $new : (string) $new );
				continue;
			}
			$data[ $key ] = eizport_elementor_map( $value, $maps, $media );
		}

		return $data;
	}

	/**
	 * Old URL => new, longest first: the importer's media URLs, and the
	 * source site's address turned into this site's.
	 *
	 * @param object $importer WP_Import.
	 * @return array<string, string>
	 */
	function eizport_elementor_urls( $importer ) {
		$urls = array();
		foreach ( (array) ( $importer->url_remap ?? array() ) as $old => $new ) {
			if ( is_string( $old ) && '' !== $old && is_string( $new ) ) {
				$urls[ $old ] = $new;
			}
		}
		$source = function_exists( 'eizport_source_home' ) ? (string) eizport_source_home( $importer ) : (string) ( $importer->base_url ?? '' );
		$source = untrailingslashit( $source );
		if ( '' !== $source && untrailingslashit( home_url() ) !== $source ) {
			$urls[ $source ] = untrailingslashit( home_url() );
			// As Elementor saves them: slashes escaped.
			$urls[ str_replace( '/', '\/', $source ) ] = str_replace( '/', '\/', untrailingslashit( home_url() ) );
		}
		uksort(
			$urls,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $urls;
	}

	/**
	 * Lets Elementor's import find the attachments the WordPress Importer
	 * brought, by the hash of their source URL, instead of downloading them
	 * again.
	 *
	 * The design reaches Elementor with its URLs already turned into this
	 * site's (eizport_elementor_map()), so each attachment is known by the
	 * URL it had, the one it has, and the one the mapping gives.
	 *
	 * @param array<string, string> $url_remap Old URL => new (the importer's).
	 * @param array<string, string> $urls      eizport_elementor_urls().
	 */
	function eizport_elementor_mark_images( $url_remap, $urls ) {
		foreach ( $url_remap as $old => $new ) {
			$id = is_string( $new ) ? attachment_url_to_postid( $new ) : 0;
			if ( ! $id ) {
				continue;
			}
			$have = (array) get_post_meta( $id, '_elementor_source_image_hash', false );
			foreach ( array_unique( array( (string) $old, (string) $new, strtr( (string) $old, $urls ) ) ) as $url ) {
				if ( ! in_array( sha1( $url ), $have, true ) ) {
					add_post_meta( $id, '_elementor_source_image_hash', sha1( $url ) );
				}
			}
		}
	}

	/**
	 * A design in Elementor's template file format — `content`,
	 * `page_settings` (or `settings`), `type`, `title` — into a post: with
	 * Elementor, its document's import(); without, its meta.
	 *
	 * @param int   $post_id Post.
	 * @param array $file    The design.
	 * @return bool
	 */
	function eizport_elementor_apply( $post_id, $file ) {
		$content  = isset( $file['content'] ) && is_array( $file['content'] ) ? $file['content'] : null;
		$settings = $file['page_settings'] ?? $file['settings'] ?? array();
		if ( null === $content ) {
			return false;
		}
		if ( eizport_elementor_active() ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( $document ) {
				$document->import(
					array(
						'content'         => $content,
						'settings'        => is_array( $settings ) ? $settings : array(),
						'import_settings' => array( 'thumbnail' => '' ),
					)
				);
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				return true;
			}
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $content ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( is_array( $settings ) && $settings ) {
			update_post_meta( $post_id, '_elementor_page_settings', $settings );
		}
		if ( ! empty( $file['version'] ) ) {
			update_post_meta( $post_id, '_elementor_version', (string) $file['version'] );
		}
		if ( ! empty( $file['type'] ) && 'elementor_library' === get_post_type( $post_id ) ) {
			update_post_meta( $post_id, '_elementor_template_type', (string) $file['type'] );
		}

		return true;
	}

	/**
	 * The bundle's templates: each one created as Elementor's Kit import
	 * does, or — imported before from the same source — updated.
	 *
	 * @param array  $templates Old ID => template file.
	 * @param string $source    The source site's address.
	 * @return array<int, int> Old ID => new ID.
	 */
	function eizport_elementor_template_ids( $templates, $source ) {
		$ids = array();
		foreach ( $templates as $old => $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$key   = $source . '#' . (int) $old;
			$found = get_posts(
				array(
					'post_type'      => 'elementor_library',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_eizport_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once per template imported.
					'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- once per template imported.
				)
			);
			$id    = $found ? (int) $found[0] : 0;
			if ( ! $id && eizport_elementor_active() ) {
				$document = \Elementor\Plugin::$instance->documents->create(
					(string) ( $file['type'] ?? 'section' ),
					array(
						'post_title'  => (string) ( $file['title'] ?? '' ),
						'post_type'   => 'elementor_library',
						'post_status' => 'publish',
					)
				);
				$id       = is_wp_error( $document ) || ! $document ? 0 : (int) $document->get_main_id();
			}
			if ( ! $id ) {
				$id = (int) wp_insert_post(
					array(
						'post_title'  => (string) ( $file['title'] ?? '' ),
						'post_type'   => 'elementor_library',
						'post_status' => 'publish',
					)
				);
			}
			if ( $id ) {
				update_post_meta( $id, '_eizport_source', $key );
				$ids[ (int) $old ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Global colors or fonts (`custom_colors`, `custom_typography`): this
	 * site's, with the ones of the same `_id` replaced and the new ones
	 * added.
	 *
	 * @param array $current This site's.
	 * @param array $incoming The bundle's.
	 * @return array
	 */
	function eizport_elementor_merge_globals( $current, $incoming ) {
		$by_id = array();
		foreach ( array_merge( (array) $current, (array) $incoming ) as $entry ) {
			if ( is_array( $entry ) ) {
				$by_id[ (string) ( $entry['_id'] ?? count( $by_id ) ) ] = $entry;
			}
		}

		return array_values( $by_id );
	}

	/**
	 * The bundle's site settings: the first time, through Elementor's own
	 * Site_Settings runner (a new active Kit, merged with this site's), the
	 * Kit then marked with its source; a later delivery from the same
	 * source updates that Kit while it's active, its global colors and
	 * fonts replaced by `_id` instead of added again.
	 *
	 * @param array  $settings Kit settings (Elementor's export).
	 * @param string $title    The new Kit's title.
	 * @param string $source   The source site's address.
	 */
	function eizport_elementor_site_settings( $settings, $title, $source ) {
		$kits   = \Elementor\Plugin::$instance->kits_manager;
		$active = $kits->get_active_kit();
		if ( $active && $active->get_id() && get_post_meta( $active->get_id(), '_eizport_source', true ) === $source ) {
			$current = (array) $active->get_meta( '_elementor_page_settings' );
			$merged  = array_replace_recursive( $current, $settings );
			foreach ( array( 'custom_colors', 'custom_typography' ) as $key ) {
				if ( isset( $settings[ $key ] ) ) {
					// Lists: replaced whole, not item by index.
					$merged[ $key ] = eizport_elementor_merge_globals( $current[ $key ] ?? array(), $settings[ $key ] );
				}
			}
			$active->save( array( 'settings' => $merged ) );
			return;
		}
		if ( ! class_exists( '\Elementor\App\Modules\ImportExport\Runners\Import\Site_Settings' ) ) {
			return;
		}
		$runner = new \Elementor\App\Modules\ImportExport\Runners\Import\Site_Settings();
		$runner->import(
			array(
				'site_settings' => array( 'settings' => $settings ),
				'include'       => array( 'settings' ),
				'manifest'      => array( 'title' => $title ),
			),
			array()
		);
		$new = (int) $kits->get_active_id();
		if ( $new ) {
			update_post_meta( $new, '_eizport_source', $source );
		}
	}

	/**
	 * `import_end`: Elementor's templates, site settings and the imported
	 * pages' designs, through Elementor's own import; then its CSS rebuilt.
	 */
	function eizport_elementor_import_end() {
		$importer = $GLOBALS['wp_import'] ?? null;
		$entry    = (array) ( $GLOBALS['eizport_elementor_entry'] ?? array() );
		$designs  = (array) ( $GLOBALS['eizport_content_data'] ?? array() );
		unset( $GLOBALS['eizport_elementor_entry'], $GLOBALS['eizport_content_data'] );
		if ( ! is_object( $importer ) ) {
			return;
		}
		$posts = array();
		foreach ( (array) ( $importer->processed_posts ?? array() ) as $old => $new ) {
			if ( (int) $old && (int) $new ) {
				$posts[ (int) $old ] = (int) $new;
			}
		}
		$builder = array_filter(
			$posts,
			function ( $id ) {
				return 'builder' === get_post_meta( $id, '_elementor_edit_mode', true );
			}
		);
		if ( ! $entry && ! $designs && ! $builder ) {
			return;
		}

		$active    = eizport_elementor_active();
		$source    = function_exists( 'eizport_source_home' ) ? (string) eizport_source_home( $importer ) : (string) ( $importer->base_url ?? '' );
		$templates = (array) ( $entry['templates'] ?? array() );
		$maps      = array(
			'posts'     => $posts,
			'templates' => eizport_elementor_template_ids( $templates, $source ),
			'urls'      => eizport_elementor_urls( $importer ),
		);
		if ( $active ) {
			eizport_elementor_mark_images( (array) ( $importer->url_remap ?? array() ), $maps['urls'] );
		}

		foreach ( $templates as $old => $file ) {
			if ( is_array( $file ) && isset( $maps['templates'][ (int) $old ] ) ) {
				eizport_elementor_apply( $maps['templates'][ (int) $old ], eizport_elementor_map( $file, $maps, ! $active ) );
			}
		}

		$settings = $entry['site_settings']['settings'] ?? null;
		if ( is_array( $settings ) && $active ) {
			eizport_elementor_site_settings( eizport_elementor_map( $settings, $maps ), (string) ( $entry['title'] ?? 'Eizport' ), $source );
		}

		foreach ( $posts as $old => $new ) {
			$file = isset( $designs[ $old ] ) ? json_decode( (string) $designs[ $old ], true ) : null;
			if ( ! is_array( $file ) && isset( $builder[ $old ] ) ) {
				// Designs left in the WXR (no content files): their meta, through the same import.
				$content = json_decode( (string) get_post_meta( $new, '_elementor_data', true ), true );
				$file    = is_array( $content ) ? array(
					'content'       => $content,
					'page_settings' => get_post_meta( $new, '_elementor_page_settings', true ),
				) : null;
			}
			if ( is_array( $file ) ) {
				delete_post_meta( $new, '_elementor_css' );
				eizport_elementor_apply( $new, eizport_elementor_map( $file, $maps, ! $active ) );
			}
		}

		// The generated CSS is built again, as after Elementor's own import.
		if ( $active && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}
	add_action( 'import_end', 'eizport_elementor_import_end', 17 );
}
