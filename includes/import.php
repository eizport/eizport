<?php
/**
 * .wxrz uploads on the WordPress Importer screen.
 *
 * Core's import upload accepts any file type (it appends ".txt" to the name
 * and skips the type check), but the importer's parsers read the file with
 * file_get_contents(), which doesn't decompress gzip. So a gzip upload is
 * turned into a plain WXR in place before WordPress stores it, and the
 * importer reads that WXR at both of its steps:
 *
 * - a bundle (gzipped tar) is unpacked: its WXR replaces the upload and the
 *   attachment files go to a private folder recorded on the import
 *   attachment; at step 2 the importer's downloads are answered from it, and
 *   the folder goes away with that attachment;
 * - a gzipped WXR (.xml.gz) is just decompressed.
 *
 * @package Eizport
 */

namespace Eizport\Import;

use Eizport\Bundle;
use Eizport\Files;
use Eizport\Tar;
use Eizport\Wxrz;

defined( 'ABSPATH' ) || exit;

const IMPORTER_ID     = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the importer's ID, not the name.
const IMPORTER_PLUGIN = 'wordpress-importer/wordpress-importer.php';

/**
 * Step 1's "Update items that already exist" field.
 */
const UPDATE_FIELD = 'eizport_update_existing';

/**
 * Fields of the importer's step 1: how the look's CSS is added, and whether it's marked.
 */
const CSS_MODE_FIELD    = 'eizport_css_mode';
const CSS_MARKERS_FIELD = 'eizport_css_markers';

/**
 * Bundle folder unpacked during this request, until the import attachment
 * that records it is created.
 *
 * @var string|null
 */
$GLOBALS['eizport_unpacked_bundle'] = null;

/**
 * Import attachment of the bundle uploaded during this request (step 1).
 *
 * @var int
 */
$GLOBALS['eizport_bundle_import_id'] = 0;

/**
 * Registers the hooks.
 */
function bootstrap() {
	add_filter( 'wp_handle_upload_prefilter', __NAMESPACE__ . '\decompress_upload' );
	add_action( 'add_attachment', __NAMESPACE__ . '\record_bundle' );
	add_action( 'delete_attachment', __NAMESPACE__ . '\delete_bundle' );
	add_action( 'import_start', __NAMESPACE__ . '\serve_bundle' );
	add_action( 'load-importer-wordpress', __NAMESPACE__ . '\mention_wxrz' );
	add_action( 'load-import.php', __NAMESPACE__ . '\open_wordpress_importer' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\register_steps_script' );
	// Before the footer scripts are printed (priority 10).
	add_action( 'admin_print_footer_scripts', __NAMESPACE__ . '\check_fetch_attachments', 5 );
	add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/eizport.php' ), __NAMESPACE__ . '\action_links' );
}

/**
 * Whether the WordPress Importer is active. The export works without it;
 * importing needs it.
 *
 * @return bool
 */
function importer_active() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	return is_plugin_active( IMPORTER_PLUGIN );
}

/**
 * Where to import: the WordPress Importer screen, or, while it isn't
 * active, Tools → Import, where WordPress offers to install and run it.
 *
 * @return string
 */
function importer_url() {
	return importer_active() ? admin_url( 'admin.php?import=' . IMPORTER_ID ) : admin_url( 'import.php' );
}

/**
 * Adds "Import .wxrz" to the plugin's row on the Plugins screen.
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function action_links( $links ) {
	if ( current_user_can( 'import' ) ) {
		$label = importer_active()
			? __( 'Import .wxrz', 'eizport' )
			: __( 'Install the WordPress Importer', 'eizport' );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( importer_url() ), esc_html( $label ) ) );
	}

	return $links;
}

/**
 * Turns a gzip file, a .zip bundle or an "as plugin" .zip uploaded to the
 * WordPress Importer into a plain WXR. Detected by its contents, so `.wxrz`,
 * `.xml.gz`, `.zip` bundles and bundle plugins all work.
 *
 * @param array $file Entry of $_FILES being uploaded.
 * @return array
 */
function decompress_upload( $file ) {
	if ( ! empty( $file['error'] ) || ! is_importer_upload( $file ) || ! ( Wxrz\is_gzip( $file['tmp_name'] ) || Bundle\is_zip_bundle( $file['tmp_name'] ) || \Eizport\BundlePlugin\is_plugin_zip( $file['tmp_name'] ) ) ) {
		return $file;
	}

	$wxr    = Files\temp_file( 'wxrz-wxr' );
	$result = prepare_wxr( $file['tmp_name'], $wxr );
	if ( ! is_wp_error( $result ) && '' !== $result ) {
		$GLOBALS['eizport_unpacked_bundle'] = $result;
	}

	// Write the WXR over the uploaded file, so the upload checks still apply to it.
	if ( ! is_wp_error( $result ) && ! @copy( $wxr, $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = new \WP_Error( 'eizport_wxrz_unwritable', __( 'The decompressed .wxrz file could not be saved.', 'eizport' ) );
	}
	wp_delete_file( $wxr );

	if ( is_wp_error( $result ) ) {
		if ( $GLOBALS['eizport_unpacked_bundle'] ) {
			Bundle\delete_folder( $GLOBALS['eizport_unpacked_bundle'] );
			$GLOBALS['eizport_unpacked_bundle'] = null;
		}
		$file['error'] = $result->get_error_message();
		return $file;
	}

	clearstatcache( true, $file['tmp_name'] );
	$file['size'] = filesize( $file['tmp_name'] );
	$file['name'] = xml_name( $file['name'] );

	return $file;
}

/**
 * Gets the WXR out of a .wxrz or .zip bundle, an "as plugin" .zip, a gzipped
 * WXR or a plain WXR.
 *
 * @param string $source     File to read.
 * @param string $wxr_target File to write the WXR to.
 * @return string|\WP_Error Folder the bundle's files were unpacked into
 *                          (delete it with Bundle\delete_folder()), or ''
 *                          when it wasn't a bundle.
 */
function prepare_wxr( $source, $wxr_target ) {
	// A bundle folder (wp eizport export --dir) is packed back into the tar a .wxrz holds.
	if ( is_dir( $source ) ) {
		$tar    = Files\temp_file( 'eizport-tar' );
		$result = \Eizport\Folder\pack( $source, $tar );
		if ( ! is_wp_error( $result ) ) {
			$result = Bundle\unpack_bundle( $tar, $wxr_target );
		}
		wp_delete_file( $tar );

		return $result;
	}
	// An "as plugin" .zip: the bundle inside it.
	if ( \Eizport\BundlePlugin\is_plugin_zip( $source ) ) {
		$inner = \Eizport\BundlePlugin\unwrap_zip( $source );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}
		$result = prepare_wxr( $inner, $wxr_target );
		if ( is_dir( $inner ) ) {
			Bundle\delete_folder( $inner );
		} else {
			wp_delete_file( $inner );
		}

		return $result;
	}
	// A .zip bundle becomes the tar a .wxrz holds.
	if ( Bundle\is_zip( $source ) ) {
		$tar    = Files\temp_file( 'eizport-tar' );
		$result = Bundle\zip_to_tar( $source, $tar );
		if ( ! is_wp_error( $result ) ) {
			$result = Bundle\unpack_bundle( $tar, $wxr_target );
		}
		wp_delete_file( $tar );

		return $result;
	}
	if ( ! Wxrz\is_gzip( $source ) ) {
		return copy( $source, $wxr_target ) ? '' : new \WP_Error( 'eizport_wxrz_unreadable', __( 'The file could not be read.', 'eizport' ) );
	}

	$plain  = Files\temp_file( 'wxrz' );
	$result = Wxrz\decompress( $source, $plain );
	if ( ! is_wp_error( $result ) ) {
		$result = Tar\is_tar( $plain )
			? Bundle\unpack_bundle( $plain, $wxr_target )
			: ( copy( $plain, $wxr_target ) ? '' : new \WP_Error( 'eizport_wxrz_unwritable', __( 'The decompressed .wxrz file could not be saved.', 'eizport' ) ) );
	}
	wp_delete_file( $plain );

	return $result;
}

/**
 * Imports a .wxrz bundle (or a gzipped or plain WXR) with the WordPress
 * Importer, without its screens: attachments are imported (from the bundle
 * when it has them), and posts go to the current user, or else to the first
 * administrator. Used by `wp eizport import`.
 *
 * @param string $file File to import.
 * @param array  $args `update_existing` (bool): update the items that
 *                     already exist (same slug); null or missing: as the
 *                     bundle asks. `css_mode` (end, start, replace) and
 *                     `css_markers` (bool): how the look's CSS is added;
 *                     missing, as the bundle asks.
 * @return array{log: string, posts: int}|\WP_Error The importer's messages and how many posts it created or found.
 */
function import_file( $file, $args = array() ) {
	$importer = new_importer();
	if ( is_wp_error( $importer ) ) {
		return $importer;
	}
	if ( ! is_dir( $file ) && ( ! is_file( $file ) || ! is_readable( $file ) ) ) {
		return new \WP_Error( 'eizport_wxrz_unreadable', __( 'The file could not be read.', 'eizport' ) );
	}
	if ( ! get_current_user_id() ) {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		wp_set_current_user( $admins ? (int) $admins[0] : 0 );
	}

	look_import( $args );
	$wxr = Files\temp_file( 'eizport-wxr' );
	$dir = prepare_wxr( $file, $wxr );
	if ( is_wp_error( $dir ) ) {
		wp_delete_file( $wxr );
		return $dir;
	}
	$serve  = $dir ? Bundle\serve_files( $dir ) : null;
	$apply  = $dir ? Bundle\apply_options_at_end( Bundle\read_options( $dir ) ) : null;
	$media  = $dir ? term_media_at_end( $dir ) : null;
	$update = update_existing( isset( $args['update_existing'] ) ? (bool) $args['update_existing'] : bundle_updates_existing( (string) $dir ) );

	// The importer deletes its upload attachment ($id) at the end: there is none.
	$importer->id                = 0;
	$importer->fetch_attachments = true;
	$GLOBALS['wp_import']        = $importer; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the importer's own global, which other code reads.
	$GLOBALS['post']             = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_delete_attachment( 0 ) would fall back to it.

	ob_start();
	$importer->import( $wxr );
	$log = ob_get_clean();

	if ( $apply ) {
		remove_action( 'import_end', $apply );
	}
	if ( $media ) {
		remove_action( 'import_end', $media );
	}
	$update();
	unset( $GLOBALS['eizport_look_import'] );
	if ( $serve ) {
		remove_filter( 'pre_http_request', $serve );
		Bundle\delete_folder( $dir );
	}
	wp_delete_file( $wxr );

	return array(
		'log'   => trim( html_entity_decode( wp_strip_all_tags( str_replace( array( '<br />', '</p>' ), "\n", $log ) ) ) ),
		'posts' => count( $importer->processed_posts ),
	);
}

/**
 * The post of the same type and slug as an imported item, for "Update items
 * that already exist". Attachments and menu items keep the importer's own
 * matching.
 *
 * @param array $post The importer's item (post_type, post_name).
 * @return int Post ID, or 0.
 */
function existing_by_slug( $post ) {
	$type = (string) ( $post['post_type'] ?? '' );
	$name = (string) ( $post['post_name'] ?? '' );
	if ( '' === $name || '' === $type || in_array( $type, array( 'attachment', 'nav_menu_item' ), true ) ) {
		return 0;
	}
	$ids = get_posts(
		array(
			'name'             => $name,
			'post_type'        => $type,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	return $ids ? (int) $ids[0] : 0;
}

/**
 * Meta the importer's item brings that an update leaves alone.
 *
 * @return string[]
 */
function kept_meta() {
	return array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
}

/**
 * Updates an existing post with an imported item: title, content, excerpt,
 * order, custom fields and terms (the importer only maps it and moves on).
 *
 * @param int   $id   Existing post.
 * @param array $post The importer's item.
 */
function update_from_import( $id, $post ) {
	wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_title'   => (string) ( $post['post_title'] ?? '' ),
				'post_content' => (string) ( $post['post_content'] ?? '' ),
				'post_excerpt' => (string) ( $post['post_excerpt'] ?? '' ),
				'menu_order'   => (int) ( $post['menu_order'] ?? 0 ),
			)
		)
	);
	foreach ( (array) ( $post['postmeta'] ?? array() ) as $meta ) {
		$key = (string) ( $meta['key'] ?? '' );
		if ( '' === $key || in_array( $key, kept_meta(), true ) ) {
			continue;
		}
		update_post_meta( $id, wp_slash( $key ), wp_slash( maybe_unserialize( $meta['value'] ?? '' ) ) );
	}
	$terms = array();
	foreach ( (array) ( $post['terms'] ?? array() ) as $term ) {
		$taxonomy = 'tag' === ( $term['domain'] ?? '' ) ? 'post_tag' : (string) ( $term['domain'] ?? '' );
		$found    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) ( $term['slug'] ?? '' ), $taxonomy ) : false;
		if ( $found ) {
			$terms[ $taxonomy ][] = (int) $found->term_id;
		}
	}
	foreach ( $terms as $taxonomy => $ids ) {
		wp_set_object_terms( $id, $ids, $taxonomy );
	}
	/**
	 * Fires after an existing post was updated from an imported item.
	 *
	 * @param int   $id   The post.
	 * @param array $post The importer's item.
	 */
	do_action( 'eizport_updated_from_import', $id, $post );
}

/**
 * Points the old site's URLs of an attachment that already exists to its
 * URL here, as the importer does for the ones it adds (it skips existing
 * ones, so content updated from the bundle would keep the old URLs). Its
 * resized images too, by the URL without the extension.
 *
 * @param int   $id   Existing attachment.
 * @param array $post The importer's item.
 */
function remap_existing_attachment( $id, $post ) {
	$importer = $GLOBALS['wp_import'] ?? null;
	$old      = (string) ( $post['attachment_url'] ?? '' );
	$new      = (string) wp_get_attachment_url( $id );
	if ( ! is_object( $importer ) || '' === $old || '' === $new || $old === $new ) {
		return;
	}
	$importer->url_remap[ $old ] = $new;
	if ( ! empty( $post['guid'] ) ) {
		$importer->url_remap[ (string) $post['guid'] ] = $new;
	}
	$from = pathinfo( $old );
	$to   = pathinfo( $new );
	if ( wp_attachment_is_image( $id ) && ! empty( $from['extension'] ) && ! empty( $to['extension'] ) ) {
		$importer->url_remap[ $from['dirname'] . '/' . $from['filename'] ] = $to['dirname'] . '/' . $to['filename'];
	}
}

/**
 * "Update items that already exist" for the imports that follow: an item
 * with the slug of a post of its type maps to that post (no "home-2"), and
 * the post takes the item's content. Without it, WordPress's behavior: an
 * item already there (same title and date) is skipped.
 *
 * @param bool $on Whether to update.
 * @return callable Removes the hooks.
 */
function update_existing( $on ) {
	if ( ! $on ) {
		return '__return_null';
	}
	// The importer only maps an item that exists and moves on: it is updated here.
	$find = function ( $post_exists, $post ) {
		$post = (array) $post;
		$id   = $post_exists ? (int) $post_exists : existing_by_slug( $post );
		$type = (string) ( $post['post_type'] ?? '' );
		if ( $id && 'attachment' !== $type && get_post_type( $id ) === $type ) {
			update_from_import( $id, $post );
		}
		if ( $id && 'attachment' === $type ) {
			remap_existing_attachment( $id, $post );
		}

		return $id;
	};
	add_filter( 'wp_import_existing_post', $find, 20, 2 );
	// Classic menus the site has take the file's items instead of keeping theirs (import-menus.php).
	add_filter( 'eizport_menus_update_existing', '__return_true' );
	// Widgets an earlier import of the same file added are updated in place (apply-options.php).
	add_filter( 'eizport_look_update_existing', '__return_true' );

	return function () use ( $find ) {
		remove_filter( 'wp_import_existing_post', $find, 20 );
		remove_filter( 'eizport_menus_update_existing', '__return_true' );
		remove_filter( 'eizport_look_update_existing', '__return_true' );
	};
}

/**
 * Term meta that hold an attachment ID, by taxonomy: a category's image,
 * say. Plugins add theirs (the WooCommerce compatibility adds product
 * categories' thumbnail_id).
 *
 * @return array<string, string[]> Taxonomy => meta keys.
 */
function term_media_keys() {
	/**
	 * Filters the term meta that hold an attachment ID, by taxonomy: the
	 * attachments go with the export, and the IDs are mapped on import.
	 *
	 * @param array<string, string[]> $keys Taxonomy => meta keys.
	 */
	return (array) apply_filters( 'eizport_term_media', array() );
}

/**
 * The term media a bundle's manifest lists: taxonomy => term slug => meta
 * key => attachment ID on the exporting site.
 *
 * @param string $dir Unpacked bundle folder, or ''.
 * @return array
 */
function bundle_term_media( $dir ) {
	if ( '' === (string) $dir || ! is_file( $dir . '/' . Bundle\MANIFEST ) ) {
		return array();
	}
	$manifest = json_decode( (string) file_get_contents( $dir . '/' . Bundle\MANIFEST ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the bundle's folder.

	return is_array( $manifest['term_media'] ?? null ) ? $manifest['term_media'] : array();
}

/**
 * Points the term meta that hold an attachment ID (a product category's
 * image) to the imported attachments. The bundle's manifest lists them,
 * since a WXR of chosen items names its terms without their meta.
 *
 * @param array $media Taxonomy => term slug => meta key => old attachment ID.
 * @param array $posts Old post ID => new.
 * @return int How many values were set.
 */
function remap_term_media( $media, $posts ) {
	$keys = term_media_keys();
	$set  = 0;
	foreach ( (array) $media as $taxonomy => $terms ) {
		if ( empty( $keys[ $taxonomy ] ) || ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		foreach ( (array) $terms as $slug => $values ) {
			$term = get_term_by( 'slug', (string) $slug, $taxonomy );
			if ( ! $term ) {
				continue;
			}
			foreach ( (array) $values as $key => $old ) {
				$new = (int) ( $posts[ (int) $old ] ?? 0 );
				if ( $new && in_array( $key, (array) $keys[ $taxonomy ], true ) && 'attachment' === get_post_type( $new ) ) {
					update_term_meta( $term->term_id, $key, $new );
					++$set;
				}
			}
		}
	}

	return $set;
}

/**
 * Maps a bundle's term media when the import ends (the importer deletes
 * the bundle folder before, so the manifest is read now).
 *
 * @param string $dir Unpacked bundle folder, or ''.
 * @return callable|null The action added, to remove it later.
 */
function term_media_at_end( $dir ) {
	$media = bundle_term_media( $dir );
	if ( ! $media ) {
		return null;
	}
	$action = function () use ( $media ) {
		$importer = $GLOBALS['wp_import'] ?? null;
		remap_term_media( $media, is_object( $importer ) ? (array) $importer->processed_posts : array() );
	};
	add_action( 'import_end', $action );

	return $action;
}

/**
 * Whether a bundle asks to update the items that already exist (its
 * manifest, from "Update existing items on import" on export).
 *
 * @param string $dir Unpacked bundle folder, or ''.
 * @return bool
 */
function bundle_updates_existing( $dir ) {
	if ( '' === (string) $dir || ! is_file( $dir . '/' . Bundle\MANIFEST ) ) {
		return false;
	}
	$manifest = json_decode( (string) file_get_contents( $dir . '/' . Bundle\MANIFEST ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the bundle's folder.

	return is_array( $manifest ) && ! empty( $manifest['update_existing'] );
}

/**
 * A WP_Import, loading the WordPress Importer when it isn't loaded (it only
 * loads itself on the importer screens, when WP_LOAD_IMPORTERS is set).
 *
 * @return \WP_Import|\WP_Error
 */
function new_importer() {
	// post_exists(), media_handle_sideload() and the like, outside wp-admin.
	require_once ABSPATH . 'wp-admin/includes/admin.php';

	if ( ! class_exists( 'WP_Import' ) ) {
		$path = WP_PLUGIN_DIR . '/' . IMPORTER_PLUGIN;
		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'eizport_no_importer', __( 'The WordPress Importer plugin is not installed.', 'eizport' ) );
		}
		if ( function_exists( 'wordpress_importer_init' ) ) {
			// Active, but loaded without WP_LOAD_IMPORTERS: the file returned
			// early, though PHP had declared its functions already, so it
			// can't be included again. Load its classes as it would.
			load_importer_classes( dirname( $path ) );
		} else {
			if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
				define( 'WP_LOAD_IMPORTERS', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core constant the importer checks.
			}
			// Not include_once: when loaded as an inactive plugin's file, it may be in the included list.
			include $path;
		}
	}

	return class_exists( 'WP_Import' ) ? new \WP_Import() : new \WP_Error( 'eizport_no_importer', __( 'The WordPress Importer could not be loaded.', 'eizport' ) );
}

/**
 * Loads the WordPress Importer's classes from its folder, as its main file
 * does after its WP_LOAD_IMPORTERS check.
 *
 * @param string $dir The importer's folder.
 */
function load_importer_classes( $dir ) {
	if ( ! defined( 'IMPORT_DEBUG' ) ) {
		define( 'IMPORT_DEBUG', WP_DEBUG ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the importer's constant.
	}
	require_once ABSPATH . 'wp-admin/includes/import.php';
	if ( ! class_exists( 'WP_Importer' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	}

	$files = array_merge(
		array( $dir . '/compat.php' ),
		class_exists( 'WordPress\\XML\\XMLProcessor' ) ? array() : array( $dir . '/php-toolkit/load.php' ),
		(array) glob( $dir . '/parsers/*.php' ),
		array( $dir . '/class-wp-import.php' )
	);
	foreach ( $files as $file ) {
		if ( is_string( $file ) && is_file( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Records the unpacked bundle folder on the import attachment that
 * wp_import_handle_upload() creates right after the upload.
 *
 * @param int $attachment_id Attachment ID.
 */
function record_bundle( $attachment_id ) {
	if ( $GLOBALS['eizport_unpacked_bundle'] ) {
		update_post_meta( $attachment_id, Bundle\DIR_META, $GLOBALS['eizport_unpacked_bundle'] );
		$GLOBALS['eizport_unpacked_bundle']  = null;
		$GLOBALS['eizport_bundle_import_id'] = $attachment_id;
	}
}

/**
 * The importer deletes its attachment at the end (and core's scheduled
 * cleanup after a day, when an import is abandoned): the bundle folder goes
 * with it.
 *
 * @param int $attachment_id Attachment ID.
 */
function delete_bundle( $attachment_id ) {
	$dir = get_post_meta( $attachment_id, Bundle\DIR_META, true );
	if ( $dir ) {
		Bundle\delete_folder( $dir );
	}
}

/**
 * Step 2: answers the importer's attachment downloads from the bundle, and
 * sets its options when the import ends.
 */
function serve_bundle() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the importer checked its nonce.
	$import_id = isset( $_POST['import_id'] ) ? absint( $_POST['import_id'] ) : 0;
	if ( ! $import_id ) {
		// Not the importer's screen: import_file() (abilities, WP-CLI, a bundle plugin) sets these up itself.
		return;
	}
	$dir = get_post_meta( $import_id, Bundle\DIR_META, true );

	if ( $dir && is_dir( $dir ) ) {
		Bundle\serve_files( $dir );
		Bundle\apply_options_at_end( Bundle\read_options( $dir ) );
		term_media_at_end( $dir );
	}
	// "Update items that already exist", checked on step 1.
	update_existing( ! empty( $_POST[ UPDATE_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the importer checked its nonce.
	// How the look's CSS is added, as chosen on step 1 (when the bundle has some).
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the importer checked its nonce.
	if ( isset( $_POST[ CSS_MODE_FIELD ] ) ) {
		look_import(
			array(
				'css_mode'    => sanitize_key( wp_unslash( $_POST[ CSS_MODE_FIELD ] ) ),
				'css_markers' => ! empty( $_POST[ CSS_MARKERS_FIELD ] ),
			)
		);
	}
	// phpcs:enable
}

/**
 * How whoever imports wants the look's CSS added (css_mode: end, start or
 * replace; css_markers), read when the import ends (apply-options.php).
 *
 * @param array $args `css_mode` and `css_markers`, each optional: missing, as the bundle asks.
 */
function look_import( $args ) {
	$choice = array();
	if ( isset( $args['css_mode'] ) && in_array( $args['css_mode'], array( 'end', 'start', 'replace' ), true ) ) {
		$choice['css_mode'] = $args['css_mode'];
	}
	if ( array_key_exists( 'css_markers', $args ) && null !== $args['css_markers'] ) {
		$choice['css_markers'] = (bool) $args['css_markers'];
	}
	$GLOBALS['eizport_look_import'] = $choice;
}

/**
 * Registers assets/import-steps.js, which step 1's additions below fill
 * with data. They are known only once the importer has the file, while the
 * page is printed, so they enqueue it from the footer, before the footer
 * scripts are printed; importer.js depends on it (style()), so it runs
 * first.
 */
function register_steps_script() {
	$file = dirname( __DIR__ ) . '/assets/import-steps.js';
	wp_register_script( 'eizport-import-steps', plugins_url( 'assets/import-steps.js', dirname( __DIR__ ) . '/eizport.php' ), array(), (string) filemtime( $file ), array( 'in_footer' => true ) );
}

/**
 * Enqueues assets/import-steps.js with one more piece of its data.
 *
 * @param string $key   Key in window.eizportImportSteps.
 * @param mixed  $value Its value.
 */
function steps_script( $key, $value ) {
	wp_enqueue_script( 'eizport-import-steps' );
	wp_add_inline_script(
		'eizport-import-steps',
		sprintf( 'window.eizportImportSteps = Object.assign( window.eizportImportSteps || {}, %s );', wp_json_encode( array( $key => $value ) ) ),
		'before'
	);
}

/**
 * Step 1: "Update items that already exist", checked when the bundle asks
 * (it was exported with "Update existing items on import").
 */
function update_existing_field() {
	$dir = $GLOBALS['eizport_bundle_import_id'] ? (string) get_post_meta( $GLOBALS['eizport_bundle_import_id'], Bundle\DIR_META, true ) : '';
	steps_script(
		'updateExisting',
		array(
			'name'    => UPDATE_FIELD,
			'checked' => bundle_updates_existing( $dir ),
			'heading' => __( 'Existing items', 'eizport' ),
			'label'   => __( 'Update pages, posts and products that already exist (same slug) instead of adding copies', 'eizport' ),
		)
	);
}

/**
 * Step 1: how the bundle's CSS rules are added (at the end of this site's
 * CSS, at its start, or replacing it) and whether they're marked, as the
 * bundle asks; only when it has some.
 */
function css_field() {
	$dir  = $GLOBALS['eizport_bundle_import_id'] ? (string) get_post_meta( $GLOBALS['eizport_bundle_import_id'], Bundle\DIR_META, true ) : '';
	$data = $dir ? Bundle\read_options( $dir ) : null;
	$css  = is_array( $data ) ? ( $data['custom_css'] ?? $data['global_css'] ?? null ) : null;
	if ( ! is_array( $css ) ) {
		return;
	}
	steps_script(
		'css',
		array(
			'heading'      => __( 'Additional CSS', 'eizport' ),
			'modeName'     => CSS_MODE_FIELD,
			'modeLabel'    => __( 'The file\'s CSS rules', 'eizport' ),
			'mode'         => (string) ( $css['mode'] ?? 'replace' ),
			'modes'        => array(
				array(
					'value' => 'end',
					'label' => __( 'Add at the end of this site\'s CSS', 'eizport' ),
				),
				array(
					'value' => 'start',
					'label' => __( 'Add at the start of this site\'s CSS', 'eizport' ),
				),
				array(
					'value' => 'replace',
					'label' => __( 'Replace this site\'s CSS', 'eizport' ),
				),
			),
			'markersName'  => CSS_MARKERS_FIELD,
			'markers'      => ! empty( $css['markers'] ),
			/* translators: %s: the name the CSS rules are marked with, like "delivery 1a2b3c4d". */
			'markersLabel' => sprintf( __( 'Between "head" and "tail" comments (%s): importing this file again replaces them', 'eizport' ), (string) ( $css['id'] ?? '' ) ),
		)
	);
}

/**
 * Step 1 of a bundle: "Download and import file attachments" comes checked,
 * since the files are in the bundle and nothing is downloaded.
 */
function check_fetch_attachments() {
	if ( $GLOBALS['eizport_bundle_import_id'] ) {
		steps_script( 'importId', (int) $GLOBALS['eizport_bundle_import_id'] );
	}
}

/**
 * Whether the upload is the WordPress Importer's file (`import` field on
 * its screen) sent by someone who may import.
 *
 * @param array $file Entry of $_FILES being uploaded.
 * @return bool
 */
function is_importer_upload( $file ) {
	// phpcs:disable WordPress.Security.NonceVerification -- the importer checks its own nonce.
	return isset( $_GET['import'], $_FILES['import']['tmp_name'] )
		&& IMPORTER_ID === sanitize_key( wp_unslash( $_GET['import'] ) )
		&& $_FILES['import']['tmp_name'] === $file['tmp_name']
		&& current_user_can( 'import' );
	// phpcs:enable
}

/**
 * "site.wxrz.txt" → "site.xml.txt", "site.xml.gz.txt" → "site.xml.txt"
 * (core appends ".txt" to import uploads before this runs).
 *
 * @param string $name Uploaded file name.
 * @return string
 */
function xml_name( $name ) {
	$suffix = '';
	if ( '.txt' === substr( $name, -4 ) ) {
		$suffix = '.txt';
		$name   = substr( $name, 0, -4 );
	}

	$name = preg_replace( '/\.(' . Wxrz\EXTENSION . '|gz)$/i', '', $name );
	if ( ! preg_match( '/\.xml$/i', $name ) ) {
		$name .= '.xml';
	}

	return $name . $suffix;
}

/**
 * Says on the importer screen that .wxrz files are accepted and, with
 * Settings → General → "Style Tools → Import → WordPress" (on by default),
 * makes the importer look like the plugin's Export screen (style()).
 */
function mention_wxrz() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the step.
	if ( isset( $_GET['step'] ) && 1 === absint( $_GET['step'] ) ) {
		add_action( 'admin_print_footer_scripts', __NAMESPACE__ . '\\update_existing_field', 5 );
		add_action( 'admin_print_footer_scripts', __NAMESPACE__ . '\\css_field', 5 );
	}
	add_filter(
		'gettext_wordpress-importer',
		function ( $translation, $text ) {
			if ( 'Choose a WXR (.xml) file to upload, then click Upload file and import.' === $text ) {
				$translation .= ' ' . __( '.wxrz bundles, the .zip of a bundle exported as plugin and gzipped WXR files are accepted too: their attachment files come from the bundle.', 'eizport' );
			}
			return $translation;
		},
		10,
		2
	);
	if ( \Eizport\Settings\get()['style_import'] ) {
		add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\style' );
		add_filter( 'admin_body_class', __NAMESPACE__ . '\body_class' );
	} elseif ( \Eizport\Settings\get()['import_direct'] ) {
		// The styled screen has the link in its header.
		add_action( 'admin_notices', __NAMESPACE__ . '\all_importers_notice' );
	}
}

/**
 * The list of every importer, which Tools → Import skips (?all=1 shows it).
 *
 * @return string
 */
function all_importers_url() {
	return add_query_arg( 'all', '1', admin_url( 'import.php' ) );
}

/**
 * With Settings → General → "Open the WordPress Importer from Tools →
 * Import" (on by default), Tools → Import opens the WordPress Importer
 * directly, when it is active; the list of every importer stays at
 * import.php?all=1, linked from the importer's screen.
 */
function open_wordpress_importer() {
	// ?invalid=… comes back from admin.php when an importer isn't registered: never loop.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
	if ( isset( $_GET['all'] ) || isset( $_GET['invalid'] ) || ! \Eizport\Settings\get()['import_direct'] || ! current_user_can( 'import' ) ) {
		return;
	}
	if ( ! function_exists( 'get_importers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/import.php';
	}
	if ( ! isset( get_importers()[ IMPORTER_ID ] ) ) {
		return;
	}
	wp_safe_redirect( add_query_arg( 'import', IMPORTER_ID, admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * The link to every importer, on the WordPress Importer's screen without
 * the styling.
 */
function all_importers_notice() {
	echo '<div class="notice notice-info"><p><a href="' . esc_url( all_importers_url() ) . '">' . esc_html__( 'All importers', 'eizport' ) . '</a></p></div>';
}

/**
 * The importer's page class for importer.css.
 *
 * @param string $classes Body classes.
 * @return string
 */
function body_class( $classes ) {
	return $classes . ' eizport-import';
}

/**
 * Loads importer.css and importer.js: a header and cards like the plugin's
 * Export screen, and the upload step's file field as a WordPress button
 * (like the Site Icon's) instead of the browser's own "Choose File / No
 * file chosen", which isn't in the admin's language. The input stays
 * (hidden) and still sends the file, so the importer's flow doesn't
 * change; without JavaScript the importer looks as it is.
 */
function style() {
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/eizport.php' );
	$dir  = dirname( __DIR__ ) . '/assets/';
	wp_enqueue_style( 'eizport-importer', $base . 'importer.css', array(), (string) filemtime( $dir . 'importer.css' ) );
	wp_enqueue_script( 'eizport-importer', $base . 'importer.js', array( 'eizport-import-steps' ), (string) filemtime( $dir . 'importer.js' ), array( 'in_footer' => true ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the step.
	$step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0;
	$texts = array(
		'choose'   => __( 'Choose file', 'eizport' ),
		'change'   => __( 'Change file', 'eizport' ),
		'subtitle' => array(
			1 => __( 'Choose who the imported items belong to and whether their attachments come along.', 'eizport' ),
			2 => __( 'What was imported, and the importer’s messages.', 'eizport' ),
		)[ $step ] ?? '',
		'messages' => __( 'Importer messages', 'eizport' ),
		// Step 1 without its form: the file was refused.
		'failed'   => 1 === $step ? array(
			'subtitle' => __( 'The file could not be imported.', 'eizport' ),
			'label'    => __( 'Choose another file', 'eizport' ),
			'url'      => admin_url( 'admin.php?import=' . IMPORTER_ID ),
		) : null,
		// Tools → Import skips the list: the way back to it, on the upload step only.
		'all'      => 0 === $step && \Eizport\Settings\get()['import_direct'] ? array(
			'label' => __( 'All importers', 'eizport' ),
			'url'   => all_importers_url(),
		) : null,
	);
	wp_add_inline_script( 'eizport-importer', 'window.eizportImporter = ' . wp_json_encode( $texts ) . ';', 'before' );
}
