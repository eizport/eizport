<?php
/**
 * .wxrz bundles: a gzipped tar holding a manifest, the WXR and the files of
 * the exported attachments.
 *
 * Layout:
 *     manifest.json            format, version, WXR names, attachment URL → file
 *     <name>.xml               the WXR printed by export_wp()
 *     uploads/2026/10/a.jpg    optional: one entry per local attachment file
 *     post_content/<slug>.html optional: post content kept out of the WXR
 *     options.json             optional: settings, set after the import
 *
 * Export: the WXR is captured while export_wp() prints it, then on shutdown
 * (before WordPress flushes the output buffers) the archive is printed through
 * the gzip output buffer, so nothing is held in memory. A Playground .zip
 * gets the plain WXR instead, with the same media and content files.
 *
 * Import: the upload is unpacked into a private folder; the WXR, with the
 * post_content/ files merged back into it, goes to the importer, and its
 * attachment downloads are answered from that folder.
 *
 * @package Eizport
 */

namespace Eizport\Bundle;

use Eizport\Files;
use Eizport\Tar;
use Eizport\Wxrz;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const MANIFEST       = 'manifest.json';
const FORMAT_VERSION = 1;
const DIR_META       = '_eizport_bundle_dir';
const CONTENT_FOLDER = 'post_content';

/**
 * Post types whose content never goes to post_content/.
 */
const CONTENT_SKIP_TYPES = array( 'attachment', 'nav_menu_item' );

/**
 * Content collected during the export: file name => stream and item.
 *
 * @var array<string, array{stream: resource, item: array}>
 */
$GLOBALS['eizport_post_content'] = array();

/*
 * Export.
 */

/**
 * Captures the WXR export_wp() is about to print and sends a bundle instead.
 *
 * @param string $wxr_name Name of the WXR inside the bundle.
 * @param array  $options  `media` (bool, default true): add the attachment
 *                         files; `blueprint` (bool, default false): send a
 *                         Playground .zip with the plain WXR instead of the
 *                         bundle; `themes` and `plugins` (string[]): what
 *                         else that .zip holds; `download` (string): its
 *                         download name; `keep_copy` (bool): keep a copy
 *                         to open by link; `option_names` (string[]):
 *                         options for the options file; `linked` (bool):
 *                         add the options that point to the posts
 *                         exported (Options\linked_names()); `target`
 *                         (string): write the file there instead of
 *                         sending it, when finish_export() is called.
 */
function start_export( $wxr_name, $options = array() ) {
	$options                         = wp_parse_args(
		$options,
		array(
			'media'        => true,
			'blueprint'    => false,
			'themes'       => array(),
			'plugins'      => array(),
			'landing'      => '/',
			'language'     => '',
			'keep_copy'    => false,
			'download'     => '',
			'option_names' => array(),
			'linked'       => false,
			'zip'          => false,
			'as_plugin'    => false,
			'target'       => '',
		)
	);
	$GLOBALS['eizport_exported_ids'] = array();
	if ( $options['linked'] ) {
		add_filter( 'the_content_export', __NAMESPACE__ . '\collect_id', 1 );
	}
	$capture = fopen( 'php://temp/maxmemory:' . ( 2 * MB_IN_BYTES ), 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$level   = ob_get_level();

	ob_start(
		function ( $buffer ) use ( $capture ) {
			fwrite( $capture, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return '';
		},
		64 * KB_IN_BYTES
	);

	if ( '' !== $options['target'] ) {
		$GLOBALS['eizport_finish_export'] = function () use ( $capture, $level, $wxr_name, $options ) {
			while ( ob_get_level() > $level ) {
				ob_end_flush();
			}
			$options = with_options_data( $options );
			return write_export( $options['target'], $capture, $wxr_name, $options );
		};
		return;
	}

	// Before wp_ob_end_flush_all() (priority 1) closes the buffers.
	add_action(
		'shutdown',
		function () use ( $capture, $level, $wxr_name, $options ) {
			while ( ob_get_level() > $level ) {
				ob_end_flush();
			}
			$options = with_options_data( $options );
			if ( ! $options['blueprint'] && $options['zip'] ) {
				$zip = Files\temp_file( 'eizport-zip' );
				if ( write_bundle( $zip, $capture, $wxr_name, $options['media'], $options['options'], true ) ) {
					readfile( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				}
				wp_delete_file( $zip );
				return;
			}
			if ( ! $options['blueprint'] ) {
				Wxrz\start_output_compression();
				print_archive( $capture, $wxr_name, $options['media'], $options['options'] );
				return;
			}

			// The Playground .zip holds the plain WXR.
			$wxr = Files\temp_file( 'eizport-wxr' );
			$out = Files\open_write( $wxr );
			if ( $out ) {
				rewind( $capture );
				$copied = false !== stream_copy_to_stream( $capture, $out );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zip = $copied ? \Eizport\Playground\build_zip( $wxr, $wxr_name, $options['themes'], $options['plugins'], $options['media'], $GLOBALS['eizport_post_content'], $options['options'], $options['landing'], $options['language'] ) : false;
				if ( $zip ) {
					\Eizport\Copies\cleanup();
					$copy = $options['keep_copy'] ? \Eizport\Copies\keep( $zip, $options['download'] ) : '';
					if ( '' !== $copy ) {
						\Eizport\Copies\schedule_cleanup();
					}
					\Eizport\Copies\announce( $options['download'], $copy );
					readfile( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
					wp_delete_file( $zip );
				}
			}
			wp_delete_file( $wxr );
		},
		0
	);
}

/**
 * Notes each post export_wp() writes, for the linked options.
 *
 * @param string $content Post content (untouched).
 * @return string
 */
function collect_id( $content ) {
	if ( isset( $GLOBALS['id'] ) ) {
		$GLOBALS['eizport_exported_ids'][] = (int) $GLOBALS['id'];
	}

	return $content;
}

/**
 * The options file's data, once the posts are written: the options asked
 * for and, with `linked`, the ones pointing to the posts exported.
 *
 * @param array $options As for start_export().
 * @return array The same, with `options` (Options\data(), or null).
 */
function with_options_data( $options ) {
	$names = (array) $options['option_names'];
	if ( $options['linked'] ) {
		remove_filter( 'the_content_export', __NAMESPACE__ . '\collect_id', 1 );
		$names = array_merge( $names, \Eizport\Options\linked_names( $GLOBALS['eizport_exported_ids'] ?? array() ) );
	}
	$GLOBALS['eizport_exported_ids'] = array();
	$options['options']              = $names ? \Eizport\Options\data( $names ) : null;

	// The look chosen on its own rows (Customizer, Site Editor, widgets) goes in the same file.
	$look = \Eizport\Appearance\data( (array) ( $options['appearance'] ?? array() ), (array) ( $options['css'] ?? array() ) );
	if ( $look['options'] || count( $look ) > 1 ) {
		$data            = $options['options'] ? $options['options'] : array(
			'format'   => 'eizport-options',
			'version'  => \Eizport\Options\VERSION,
			'options'  => array(),
			'post_ids' => array(),
		);
		$data['options'] = array_merge( $data['options'], $look['options'] );
		unset( $look['options'] );
		$options['options'] = array_merge( $data, $look );
	}

	return $options;
}

/**
 * Finishes an export started with a `target`: writes the file. Content
 * files collected for it are released.
 *
 * @return bool
 */
function finish_export() {
	$finish = $GLOBALS['eizport_finish_export'] ?? null;
	unset( $GLOBALS['eizport_finish_export'] );
	$written = is_callable( $finish ) && $finish();

	remove_filter( 'the_content_export', __NAMESPACE__ . '\collect_content' );
	$GLOBALS['eizport_post_content'] = array();

	return $written;
}

/**
 * Writes an export to a file: the .wxrz, or the Playground .zip.
 *
 * @param string   $target   File to write.
 * @param resource $wxr      The captured WXR.
 * @param string   $wxr_name Name of the WXR inside it.
 * @param array    $options  As for start_export().
 * @return bool
 */
function write_export( $target, $wxr, $wxr_name, $options ) {
	if ( ! empty( $options['as_plugin'] ) ) {
		// The bundle, inside a plugin that imports it (BundlePlugin).
		$bundle = Files\temp_file( 'eizport-bundle' );
		// A .zip or a .wxrz, as Settings → Export → "Bundle as .zip" says.
		$written = write_bundle( $bundle, $wxr, $wxr_name, $options['media'], $options['options'], \Eizport\Settings\get()['bundle_zip'] )
			&& \Eizport\BundlePlugin\build_zip( $target, $bundle, (string) preg_replace( '/\.[a-z0-9]+$/i', '', (string) $options['download'] ) );
		wp_delete_file( $bundle );

		return $written;
	}
	if ( ! $options['blueprint'] ) {
		return write_bundle( $target, $wxr, $wxr_name, $options['media'], $options['options'], ! empty( $options['zip'] ) );
	}

	$plain = Files\temp_file( 'eizport-wxr' );
	$out   = Files\open_write( $plain );
	$zip   = false;
	if ( $out ) {
		rewind( $wxr );
		$copied = false !== stream_copy_to_stream( $wxr, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip = $copied ? \Eizport\Playground\build_zip( $plain, $wxr_name, $options['themes'], $options['plugins'], $options['media'], $GLOBALS['eizport_post_content'], $options['options'], $options['landing'], $options['language'] ) : false;
	}
	wp_delete_file( $plain );

	$moved = $zip && copy( $zip, $target );
	if ( $zip ) {
		wp_delete_file( $zip );
	}

	return $moved;
}

/**
 * Writes a bundle to a file instead of the output.
 *
 * @param string     $target   File to write.
 * @param resource   $wxr      The WXR.
 * @param string     $wxr_name Name of the WXR inside the bundle.
 * @param bool       $media    Whether to add the attachment files.
 * @param array|null $options  The options file's data.
 * @param bool       $zip      A .zip instead of the gzipped tar.
 * @return bool
 */
function write_bundle( $target, $wxr, $wxr_name, $media = true, $options = null, $zip = false ) {
	if ( $zip ) {
		$tar     = Files\temp_file( 'eizport-tar' );
		$written = write_tar( $tar, $wxr, $wxr_name, $media, $options ) && tar_to_zip( $tar, $target );
		wp_delete_file( $tar );

		return $written;
	}

	$out = Files\open_write( $target );
	if ( ! $out ) {
		return false;
	}

	$written = true;
	ob_start(
		function ( $buffer ) use ( $out, &$written ) {
			$written = $written && strlen( $buffer ) === fwrite( $out, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return '';
		},
		64 * KB_IN_BYTES
	);
	Wxrz\start_output_compression();
	print_archive( $wxr, $wxr_name, $media, $options );
	ob_end_flush(); // Finishes the gzip stream.
	ob_end_flush();
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return $written;
}

/**
 * Writes a bundle's uncompressed tar to a file.
 *
 * @param string     $target   File to write.
 * @param resource   $wxr      The WXR.
 * @param string     $wxr_name Name of the WXR inside the bundle.
 * @param bool       $media    Whether to add the attachment files.
 * @param array|null $options  The options file's data.
 * @return bool
 */
function write_tar( $target, $wxr, $wxr_name, $media, $options ) {
	$out = Files\open_write( $target );
	if ( ! $out ) {
		return false;
	}

	$written = true;
	ob_start(
		function ( $buffer ) use ( $out, &$written ) {
			$written = $written && strlen( $buffer ) === fwrite( $out, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return '';
		},
		64 * KB_IN_BYTES
	);
	print_archive( $wxr, $wxr_name, $media, $options );
	ob_end_flush();
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return $written;
}

/**
 * Keeps each exported post's content out of the WXR, for post_content/.
 */
function start_content_files() {
	add_filter( 'the_content_export', __NAMESPACE__ . '\collect_content' );
}

/**
 * `the_content_export`: stores the content of the post being exported (set
 * up by export_wp() through setup_postdata(), which fills the global `$id`)
 * and leaves it empty in the WXR.
 *
 * @param string $content Post content.
 * @return string
 */
function collect_content( $content ) {
	$post = get_post( isset( $GLOBALS['id'] ) ? (int) $GLOBALS['id'] : 0 );
	if ( ! $post || '' === $content || in_array( $post->post_type, CONTENT_SKIP_TYPES, true ) ) {
		return $content;
	}

	$stream = fopen( 'php://temp/maxmemory:' . MB_IN_BYTES, 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

	$GLOBALS['eizport_post_content'][ content_file_name( $post ) ] = array(
		'stream' => $stream,
		'item'   => array(
			'id'   => (int) $post->ID,
			'type' => $post->post_type,
			'slug' => $post->post_name,
		),
	);

	return '';
}

/**
 * "<slug>.html"; when the slug is taken (or empty, or has characters a file
 * name shouldn't), "<type>-<id>-<slug>.html".
 *
 * @param \WP_Post $post Post.
 * @return string
 */
function content_file_name( $post ) {
	$name = $post->post_name . '.html';
	if ( '' === $post->post_name || ! is_content_name( $name ) || isset( $GLOBALS['eizport_post_content'][ $name ] ) ) {
		$name = $post->post_type . '-' . $post->ID . ( is_content_name( $post->post_name . '.html' ) ? '-' . $post->post_name : '' ) . '.html';
	}

	return $name;
}

/**
 * Whether a post_content/ file name is safe: one level, no hidden files.
 *
 * @param string $name File name.
 * @return bool
 */
function is_content_name( $name ) {
	return (bool) preg_match( '/^[A-Za-z0-9%_-][A-Za-z0-9%._-]*\.html$/', $name );
}

/**
 * Prints the archive (tar), to be compressed by an output buffer.
 *
 * @param resource $wxr      The captured WXR.
 * @param string   $wxr_name Name of the WXR inside the bundle.
 * @param bool     $media    Whether to add the attachment files.
 * @param array    $options  The options file's data, or null.
 */
function print_archive( $wxr, $wxr_name, $media = true, $options = null ) {
	$files = $media ? media_files( $wxr ) : array();

	$manifest = array(
		'format'    => Wxrz\EXTENSION,
		'version'   => FORMAT_VERSION,
		'generator' => 'Eizport ' . \Eizport\VERSION,
		'site'      => home_url( '/' ),
		'created'   => gmdate( 'c' ),
		'wxr'       => array( $wxr_name ),
		'files'     => array_map(
			function ( $file ) {
				return $file['entry'];
			},
			$files
		),
	);
	if ( $options ) {
		$manifest['options'] = \Eizport\Options\FILE;
	}
	if ( ! empty( $GLOBALS['eizport_update_existing'] ) ) {
		// The importer updates the items that already exist (same slug), unless told otherwise.
		$manifest['update_existing'] = true;
	}
	$term_media = term_media_of( $wxr );
	if ( $term_media ) {
		// Term meta holding an attachment ID (a product category's image), mapped on import.
		$manifest['term_media'] = $term_media;
	}
	if ( $GLOBALS['eizport_post_content'] ) {
		$manifest['post_content'] = array_map(
			function ( $file ) {
				return $file['item'];
			},
			$GLOBALS['eizport_post_content']
		);
	}

	$json = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fwrite( $json, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

	Tar\print_entry( MANIFEST, $json );
	Tar\print_entry( $wxr_name, $wxr );
	if ( $options ) {
		$stream = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $stream, (string) wp_json_encode( $options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		Tar\print_entry( \Eizport\Options\FILE, $stream );
	}
	foreach ( $GLOBALS['eizport_post_content'] as $name => $file ) {
		Tar\print_entry( CONTENT_FOLDER . '/' . $name, $file['stream'] );
	}
	foreach ( $files as $file ) {
		Tar\print_entry( $file['entry'], $file['path'] );
	}
	Tar\print_end();
}

/**
 * Local files of the attachments in a WXR, keyed by attachment URL.
 *
 * @param resource $wxr The WXR.
 * @return array<string, array{entry: string, path: string}>
 */
function media_files( $wxr ) {
	$uploads = wp_get_upload_dir();
	$baseurl = trailingslashit( $uploads['baseurl'] );
	$basedir = realpath( $uploads['basedir'] );
	$files   = array();

	rewind( $wxr );
	while ( ! feof( $wxr ) ) {
		$line = fgets( $wxr );
		if ( false === $line || ! preg_match( '#<wp:attachment_url><!\[CDATA\[(.+?)\]\]></wp:attachment_url>#', $line, $match ) ) {
			continue;
		}
		$url = $match[1];

		// Only files in this site's uploads; others (offloaded media) are left to the importer.
		if ( 0 !== strpos( $url, $baseurl ) ) {
			continue;
		}
		$relative = rawurldecode( substr( $url, strlen( $baseurl ) ) );
		$path     = realpath( $uploads['basedir'] . '/' . $relative );
		$entry    = 'uploads/' . $relative;

		if ( ! $path || 0 !== strpos( $path, $basedir . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) || ! is_file( $path ) ) {
			continue;
		}
		if ( false === Tar\header_block( $entry, 0, 0 ) ) {
			continue; // Name too long for ustar: the importer can still download it.
		}

		$files[ $url ] = array(
			'entry' => $entry,
			'path'  => $path,
		);
	}

	return $files;
}

/*
 * Import.
 */

/**
 * Unpacks a bundle: the WXR into `$wxr_target`, the attachment files into a
 * new private folder (with a copy of the manifest).
 *
 * @param string $tar        Decompressed bundle.
 * @param string $wxr_target File to write the WXR to.
 * @return string|WP_Error Folder holding the files.
 */
function unpack_bundle( $tar, $wxr_target ) {
	$manifest = read_manifest( $tar );
	if ( is_wp_error( $manifest ) ) {
		return $manifest;
	}

	$dir = create_folder();
	if ( is_wp_error( $dir ) ) {
		return $dir;
	}

	$wxr_name  = $manifest['wxr'][0];
	$entries   = array_flip( array_filter( $manifest['files'], __NAMESPACE__ . '\is_safe_entry' ) );
	$found_wxr = false;
	$failed    = false;

	$ok = Tar\each_file(
		$tar,
		function ( $name, $size, $handle ) use ( $wxr_name, $wxr_target, $entries, $dir, &$found_wxr, &$failed ) {
			if ( $name === $wxr_name ) {
				$found_wxr = Tar\copy_to( $handle, $size, $wxr_target );
				$failed    = $failed || ! $found_wxr;
			} elseif ( isset( $entries[ $name ] ) || is_content_entry( $name ) || \Eizport\Options\FILE === $name ) {
				$target = $dir . '/' . $name;
				$failed = $failed || ! wp_mkdir_p( dirname( $target ) ) || ! Tar\copy_to( $handle, $size, $target );
			}
		}
	);

	if ( ! $ok || $failed || ! $found_wxr ) {
		delete_folder( $dir );
		return new WP_Error( 'eizport_bundle_corrupt', __( 'The .wxrz bundle is damaged or incomplete.', 'eizport' ) );
	}

	$merged = merge_post_content( $wxr_target, $dir, $manifest );
	if ( is_wp_error( $merged ) ) {
		delete_folder( $dir );
		return $merged;
	}

	file_put_contents( $dir . '/' . MANIFEST, wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	return $dir;
}

/**
 * Whether a bundle entry is a post_content/ file.
 *
 * @param string $name Entry name.
 * @return bool
 */
function is_content_entry( $name ) {
	return 0 === strpos( $name, CONTENT_FOLDER . '/' ) && is_content_name( substr( $name, strlen( CONTENT_FOLDER ) + 1 ) );
}

/**
 * Writes the post_content/ files back into the WXR, item by item, before
 * the importer reads it: a new WXR is generated in place of the old one.
 *
 * Each item takes the file the manifest names for its original ID, or else
 * `post_content/<slug>.html` (so hand-added files work too). The
 * `eizport_post_content` filter can change or provide any item's content.
 *
 * @param string $wxr      WXR file, rewritten in place.
 * @param string $dir      Bundle folder.
 * @param array  $manifest Bundle manifest.
 * @return true|WP_Error
 */
function merge_post_content( $wxr, $dir, $manifest ) {
	$by_id = array();
	foreach ( (array) ( $manifest['post_content'] ?? array() ) as $file => $item ) {
		if ( is_array( $item ) && isset( $item['id'] ) && is_content_name( (string) $file ) ) {
			$by_id[ (int) $item['id'] ] = $file;
		}
	}
	if ( ! is_dir( $dir . '/' . CONTENT_FOLDER ) && ! has_filter( 'eizport_post_content' ) ) {
		return true;
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions
	$merged = Files\temp_file( 'wxrz-merged' );
	$in     = Files\open_read( $wxr );
	$out    = Files\open_write( $merged );
	if ( ! $in || ! $out ) {
		$in && fclose( $in );
		$out && fclose( $out );
		return new WP_Error( 'eizport_bundle_merge', __( 'The content of the .wxrz bundle could not be merged.', 'eizport' ) );
	}

	$item = null;
	while ( ! feof( $in ) ) {
		$line = fgets( $in );
		if ( false === $line ) {
			break;
		}
		if ( null === $item ) {
			if ( preg_match( '#^\s*<item>\s*$#', $line ) ) {
				$item = $line;
			} else {
				fwrite( $out, $line );
			}
			continue;
		}

		$item .= $line;
		if ( preg_match( '#^\s*</item>\s*$#', $line ) ) {
			fwrite( $out, merge_item( $item, $dir, $by_id ) );
			$item = null;
		}
	}
	if ( null !== $item ) {
		fwrite( $out, $item );
	}

	fclose( $in );
	fclose( $out );
	$ok = copy( $merged, $wxr );
	// phpcs:enable
	wp_delete_file( $merged );

	return $ok ? true : new WP_Error( 'eizport_bundle_merge', __( 'The content of the .wxrz bundle could not be merged.', 'eizport' ) );
}

/**
 * Puts one item's content file (or filtered content) into its
 * <content:encoded>.
 *
 * @param string $xml   The <item> element.
 * @param string $dir   Bundle folder.
 * @param array  $by_id Original post ID => post_content/ file name.
 * @return string
 */
function merge_item( $xml, $dir, $by_id ) {
	$item = array(
		'id'   => preg_match( '#<wp:post_id>(\d+)</wp:post_id>#', $xml, $m ) ? (int) $m[1] : 0,
		'type' => preg_match( '#<wp:post_type><!\[CDATA\[(.*?)\]\]></wp:post_type>#', $xml, $m ) ? $m[1] : '',
		'slug' => preg_match( '#<wp:post_name><!\[CDATA\[(.*?)\]\]></wp:post_name>#', $xml, $m ) ? $m[1] : '',
	);
	if ( in_array( $item['type'], CONTENT_SKIP_TYPES, true ) ) {
		return $xml;
	}

	$file    = $by_id[ $item['id'] ] ?? ( '' === $item['slug'] ? '' : $item['slug'] . '.html' );
	$path    = is_content_name( $file ) ? $dir . '/' . CONTENT_FOLDER . '/' . $file : '';
	$content = $path && is_file( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	/**
	 * Filters the content an imported item gets from a .wxrz bundle.
	 *
	 * @param string|null $content Content of its post_content/ file, or null
	 *                             to keep the content in the WXR.
	 * @param array       $item    `id` (original ID), `type` and `slug`.
	 * @param string      $dir     Folder the bundle was unpacked into.
	 */
	$content = apply_filters( 'eizport_post_content', $content, $item, $dir );
	if ( ! is_string( $content ) ) {
		return $xml;
	}

	$cdata = '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $content ) . ']]>';

	return preg_replace_callback(
		'#<content:encoded>(?:<!\[CDATA\[.*?\]\]>)*</content:encoded>#s',
		function () use ( $cdata ) {
			return '<content:encoded>' . $cdata . '</content:encoded>';
		},
		$xml,
		1
	);
}

/**
 * Reads and validates the manifest of a bundle.
 *
 * @param string $tar Decompressed bundle.
 * @return array|WP_Error
 */
function read_manifest( $tar ) {
	$json = null;
	Tar\each_file(
		$tar,
		function ( $name, $size, $handle ) use ( &$json ) {
			if ( MANIFEST !== $name ) {
				return;
			}
			$json = $size <= MB_IN_BYTES ? (string) fread( $handle, $size ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			return false;
		}
	);

	$manifest = is_string( $json ) ? json_decode( $json, true ) : null;
	$valid    = is_array( $manifest )
		&& FORMAT_VERSION === ( $manifest['version'] ?? null )
		&& ! empty( $manifest['wxr'][0] ) && is_string( $manifest['wxr'][0] )
		&& isset( $manifest['files'] ) && is_array( $manifest['files'] );

	if ( ! $valid ) {
		return new WP_Error( 'eizport_bundle_manifest', __( 'The .wxrz bundle has no valid manifest.', 'eizport' ) );
	}

	return $manifest;
}

/**
 * Whether a bundle entry may be written: under uploads/, no traversal, and a
 * file type WordPress allows to be uploaded (never .php and the like).
 *
 * @param mixed $entry Entry name from the manifest.
 * @return bool
 */
function is_safe_entry( $entry ) {
	if ( ! is_string( $entry ) || 0 !== strpos( $entry, 'uploads/' ) || preg_match( '#(^|/)\.\.?(/|$)|\\\\|\x00|//#', $entry ) ) {
		return false;
	}
	$type = wp_check_filetype( basename( $entry ) );

	return ! empty( $type['ext'] ) && ! empty( $type['type'] );
}

/**
 * Creates a folder with an unguessable name for one bundle's files.
 *
 * @return string|WP_Error
 */
function create_folder() {
	$dir = Files\tmp_folder( wp_generate_password( 24, false ) );

	if ( ! $dir ) {
		return new WP_Error( 'eizport_bundle_folder', __( 'Could not create a folder for the .wxrz bundle.', 'eizport' ) );
	}

	return $dir;
}

/**
 * Folders of bundles being imported (or abandoned).
 *
 * @return string[]
 */
function folders() {
	$folders = array();
	foreach ( (array) glob( Files\tmp_path() . '/*', GLOB_ONLYDIR ) as $dir ) {
		if ( preg_match( '/^[A-Za-z0-9]{24}$/', basename( (string) $dir ) ) ) {
			$folders[] = $dir;
		}
	}

	return $folders;
}

/**
 * Deletes a bundle folder, only if it is one of ours: in uploads/eizport/tmp/;
 * then tmp/ itself, if nothing else is left in it.
 *
 * @param string $dir Folder.
 */
function delete_folder( $dir ) {
	$root = realpath( Files\tmp_path() );
	$real = realpath( $dir );
	if ( ! $real || ! $root || dirname( $real ) !== $root ) {
		return;
	}

	Files\delete_tree( $real );
	Files\delete_tmp_if_empty();
}

/**
 * Answers the importer's attachment downloads from a bundle folder. URLs not
 * in the bundle go to the network as usual.
 *
 * @param string $dir Bundle folder.
 * @return callable The `pre_http_request` filter added, to remove it later.
 */
function serve_files( $dir ) {
	$manifest = json_decode( (string) file_get_contents( $dir . '/' . MANIFEST ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$files    = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : array();

	$filter = function ( $response, $args, $url ) use ( $dir, $files ) {
		if ( false !== $response || ! isset( $files[ $url ] ) || ! is_safe_entry( $files[ $url ] ) ) {
			return $response;
		}
		$path = $dir . '/' . $files[ $url ];
		if ( ! is_file( $path ) ) {
			return $response;
		}

		$body = '';
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $path, $args['filename'] );
		} else {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return array(
			'headers'  => array(
				'content-type'   => wp_check_filetype( $path )['type'],
				'content-length' => (string) filesize( $path ),
			),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );

	return $filter;
}

/**
 * The options file of an unpacked bundle.
 *
 * @param string $dir Bundle folder.
 * @return array|null
 */
function read_options( $dir ) {
	$path = $dir . '/' . \Eizport\Options\FILE;
	$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return is_array( $data ) && isset( $data['options'] ) && is_array( $data['options'] ) ? $data : null;
}

/**
 * Sets a bundle's options when the import ends, with the IDs they hold
 * mapped to the imported items. The data is read before: the importer
 * deletes the bundle folder before `import_end`.
 *
 * @param array|null $data The options file's data.
 * @return callable|null The action added, to remove it later.
 */
function apply_options_at_end( $data ) {
	if ( ! $data ) {
		return null;
	}
	require_once __DIR__ . '/apply-options.php';

	$action = function () use ( $data ) {
		$importer = $GLOBALS['wp_import'] ?? null;
		// Names of the options set, for whoever runs the import.
		$GLOBALS['eizport_applied_options'] = eizport_apply_options(
			$data,
			is_object( $importer ) ? (array) $importer->processed_posts : array(),
			is_object( $importer ) ? (array) $importer->processed_terms : array(),
			is_object( $importer ) ? eizport_urls_with_source( (array) $importer->url_remap, function_exists( 'eizport_source_home' ) ? eizport_source_home( $importer ) : (string) $importer->base_url ) : array()
		);
	};
	add_action( 'import_end', $action );

	return $action;
}

/*
 * .zip bundles: the same entries as a .wxrz (manifest, WXR, options,
 * post_content/, uploads/) in a plain .zip, by Settings → Export → "Bundle
 * as .zip". Written by turning the tar into a .zip, read by turning the
 * .zip back into a tar, so both go through the same code.
 */

/**
 * Whether a file is a .zip (by its magic bytes).
 *
 * @param string $path File.
 * @return bool
 */
function is_zip( $path ) {
	$handle = Files\open_read( $path );
	if ( ! $handle ) {
		return false;
	}
	$magic = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return "PK\x03\x04" === $magic;
}

/**
 * Whether a bundle entry name may be unpacked: the manifest, a WXR at the
 * top, the options file, a post_content/ file or an uploads/ file.
 *
 * @param string $name Entry name.
 * @return bool
 */
function is_bundle_entry( $name ) {
	return MANIFEST === $name
		|| \Eizport\Options\FILE === $name
		|| (bool) preg_match( '/^[^\/\\\\]+\.xml$/', $name )
		|| is_content_entry( $name )
		|| is_safe_entry( $name );
}

/**
 * The term meta holding an attachment ID of the terms a WXR's items use
 * (taxonomies and keys from the `eizport_term_media` filter).
 *
 * @param resource $wxr The captured WXR.
 * @return array Taxonomy => term slug => meta key => attachment ID.
 */
function term_media_of( $wxr ) {
	$keys = \Eizport\Import\term_media_keys();
	if ( ! $keys ) {
		return array();
	}
	$matches = array();
	rewind( $wxr );
	while ( ! feof( $wxr ) ) {
		$line = fgets( $wxr );
		if ( false !== $line && preg_match_all( '/<category domain="([^"]+)" nicename="([^"]+)"/', $line, $found, PREG_SET_ORDER ) ) {
			$matches = array_merge( $matches, $found );
		}
	}
	rewind( $wxr );
	$media = array();
	foreach ( $matches as $match ) {
		$taxonomy = html_entity_decode( $match[1] );
		$slug     = rawurldecode( html_entity_decode( $match[2] ) );
		if ( empty( $keys[ $taxonomy ] ) || isset( $media[ $taxonomy ][ $slug ] ) ) {
			continue;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			continue;
		}
		foreach ( (array) $keys[ $taxonomy ] as $key ) {
			$id = (int) get_term_meta( $term->term_id, $key, true );
			if ( $id ) {
				$media[ $taxonomy ][ $slug ][ $key ] = $id;
			}
		}
	}

	return $media;
}

/**
 * Writes the entries of a tar bundle into a .zip.
 *
 * @param string $tar Uncompressed tar bundle.
 * @param string $zip File to write.
 * @return bool
 */
function tar_to_zip( $tar, $zip ) {
	$dir = Files\tmp_folder( wp_generate_password( 24, false ) );
	if ( ! $dir ) {
		return false;
	}
	$entries = array();
	$failed  = false;
	$ok      = Tar\each_file(
		$tar,
		function ( $name, $size, $handle ) use ( $dir, &$entries, &$failed ) {
			if ( ! is_bundle_entry( $name ) ) {
				return;
			}
			$path   = $dir . '/' . $name;
			$failed = $failed || ! wp_mkdir_p( dirname( $path ) ) || ! Tar\copy_to( $handle, $size, $path );

			$entries[ $name ] = array( 'path' => $path );
		}
	);
	$written = $ok && ! $failed && $entries && \Eizport\Playground\create_zip( $zip, $entries );
	delete_folder( $dir );

	return $written;
}

/**
 * Turns a .zip bundle into an uncompressed tar bundle, entry by entry (only
 * the bundle's own kinds of entries; within the size a .wxrz may expand to).
 *
 * @param string $zip .zip bundle.
 * @param string $tar File to write.
 * @return true|WP_Error
 */
function zip_to_tar( $zip, $tar ) {
	$damaged = new WP_Error( 'eizport_bundle_corrupt', __( 'The .zip bundle is damaged, incomplete or not a bundle.', 'eizport' ) );
	$dir     = Files\tmp_folder( wp_generate_password( 24, false ) );
	if ( ! $dir ) {
		return $damaged;
	}

	$names = unzip_entries( $zip, $dir );
	if ( ! in_array( MANIFEST, $names, true ) ) {
		delete_folder( $dir );
		return $damaged;
	}

	$out = Files\open_write( $tar );
	if ( ! $out ) {
		delete_folder( $dir );
		return $damaged;
	}
	ob_start(
		function ( $buffer ) use ( $out ) {
			fwrite( $out, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return '';
		},
		64 * KB_IN_BYTES
	);
	// The manifest first, as in a .wxrz.
	usort(
		$names,
		function ( $a, $b ) {
			return (int) ( MANIFEST !== $a ) - (int) ( MANIFEST !== $b );
		}
	);
	foreach ( $names as $name ) {
		Tar\print_entry( $name, $dir . '/' . $name );
	}
	Tar\print_end();
	ob_end_flush();
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	delete_folder( $dir );

	return true;
}

/**
 * Extracts a .zip's bundle entries into a folder, with ZipArchive or else
 * core's PclZip.
 *
 * @param string $zip .zip file.
 * @param string $dir Folder.
 * @return string[] Names extracted.
 */
function unzip_entries( $zip, $dir ) {
	$names = array();
	$left  = Wxrz\max_bytes();
	$save  = function ( $name, $data ) use ( $dir, &$names ) {
		$path = $dir . '/' . $name;
		if ( wp_mkdir_p( dirname( $path ) ) && false !== file_put_contents( $path, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$names[] = $name;
		}
	};

	if ( class_exists( 'ZipArchive' ) ) {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return array();
		}
		for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's.
			$stat = $archive->statIndex( $i );
			$name = (string) ( $stat['name'] ?? '' );
			if ( ! is_bundle_entry( $name ) ) {
				continue;
			}
			$left -= (int) $stat['size'];
			if ( $left < 0 ) {
				break;
			}
			$save( $name, (string) $archive->getFromIndex( $i ) );
		}
		$archive->close();

		return $names;
	}

	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$archive = new \PclZip( $zip );
	foreach ( (array) $archive->listContent() as $entry ) {
		$name = (string) ( $entry['filename'] ?? '' );
		if ( ! is_bundle_entry( $name ) ) {
			continue;
		}
		$left -= (int) $entry['size'];
		if ( $left < 0 ) {
			break;
		}
		$found = $archive->extractByIndex( (int) $entry['index'], PCLZIP_OPT_EXTRACT_AS_STRING );
		if ( is_array( $found ) && isset( $found[0]['content'] ) ) {
			$save( $name, $found[0]['content'] );
		}
	}

	return $names;
}

/**
 * Whether a .zip is a bundle (it has a manifest), not a Playground .zip.
 *
 * @param string $zip .zip file.
 * @return bool
 */
function is_zip_bundle( $zip ) {
	if ( ! is_zip( $zip ) ) {
		return false;
	}
	if ( class_exists( 'ZipArchive' ) ) {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return false;
		}
		$bundle = false !== $archive->locateName( MANIFEST ) && false === $archive->locateName( 'blueprint.json' );
		$archive->close();

		return $bundle;
	}
	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$names = wp_list_pluck( (array) ( new \PclZip( $zip ) )->listContent(), 'filename' );

	return in_array( MANIFEST, $names, true ) && ! in_array( 'blueprint.json', $names, true );
}
