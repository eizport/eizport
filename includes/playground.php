<?php
/**
 * Playground .zip: a WordPress Playground blueprint bundle holding the
 * export as a plain WXR, imported with Playground's own importWxr step.
 *
 * Layout:
 *     blueprint.json      installs the plugins and themes, then imports the WXR
 *     <name>.xml          the WXR
 *     uploads/2026/10/…   optional: the attachment files; the importer's
 *                         downloads are answered from them by a temporary
 *                         must-use plugin the blueprint adds and removes
 *     post_content/…      optional: each post's content as <slug>.html,
 *                         put back into its item by the same plugin
 *
 * Settings (options) are written by the blueprint itself and set when the
 * import ends, with their IDs mapped, by a second temporary plugin.
 *     themes/<slug>.zip   optional: themes to install (a child theme brings
 *                         its parent) that aren't in the WordPress.org directory
 *     plugins/<slug>.zip  optional: plugins to install and activate, same
 *     languages/…         optional: this site's translations of those themes
 *                         and plugins, in the language Playground opens in
 *     mu-plugins/…        optional: must-use plugins to write, each file as
 *                         is and each folder one loads as <folder>.zip
 *
 * Themes and plugins from the WordPress.org directory aren't in the .zip:
 * the blueprint installs them from there, in the version this site has.
 *
 * Run it with `npx @wp-playground/cli server --blueprint=<name>.zip`, or
 * open a copy kept on the site by link (see copies.php), to get a site
 * with the exported content.
 *
 * @package Eizport
 */

namespace Eizport\Playground;

use Eizport\Files;

defined( 'ABSPATH' ) || exit;

const BLUEPRINT = 'blueprint.json';

/**
 * Where the blueprint writes the attachment files inside Playground.
 */
const MEDIA_DIR = '/wordpress/wp-content/eizport-media'; // playground-media.php looks there too.

/**
 * The temporary must-use plugin that serves them to the importer.
 */
const MEDIA_PLUGIN = '/wordpress/wp-content/mu-plugins/eizport-media.php';

/**
 * Folder of the content files, in the .zip and in MEDIA_DIR.
 */
const CONTENT_FOLDER = \Eizport\Bundle\CONTENT_FOLDER;

/**
 * The temporary must-use plugin that sets the options (apply-options.php).
 */
const OPTIONS_PLUGIN = '/wordpress/wp-content/mu-plugins/eizport-options.php';

/**
 * Folder of the must-use plugins, in the .zip and in Playground.
 */
const MU_FOLDER = 'mu-plugins';

/**
 * Must-use plugins the platform puts there (WordPress Studio, hosts), never
 * offered: file or folder names in WPMU_PLUGIN_DIR.
 */
const PLATFORM_MU_PLUGINS = array(
	'99-studio-loader.php',
	'sqlite-database-integration',
	'eizport-media.php',
	'eizport-options.php',
	'wpcomsh-loader.php',
	'wpcomsh',
	'wpcom-vip-loader.php',
	'mu-plugin.php',
	'wpengine-common',
	'wpe-wp-sign-on-plugin.php',
	'wpe-wp-sign-on-plugin',
	'wpe-elasticpress-autosuggest-logger.php',
	'force-strong-passwords',
	'slt-force-strong-passwords.php',
	'wpe-cache-plugin.php',
	'wpe-cache-plugin',
	'kinsta-mu-plugins.php',
	'kinsta-mu-plugins',
	'pantheon.php',
	'pantheon',
	'pantheon-mu-plugin',
	'pressable-cache-management.php',
	'pressable-onboarding.php',
	'sg-security.php',
	'sg-cachepress.php',
	'gd-system-plugin.php',
	'gd-system-plugin',
	'endurance-page-cache.php',
	'endurance-php-edge.php',
	'endurance-browser-cache.php',
	'bluehost-sso.php',
);

/**
 * Builds the Playground .zip in a temporary file, for the caller to send
 * (and delete).
 *
 * @param string   $wxr      The WXR file.
 * @param string   $wxr_name Its name inside the .zip.
 * @param string[] $themes   Stylesheets of the themes to add (parents are added).
 * @param string[] $plugins  Plugin files (as get_plugins() keys) to add.
 * @param bool     $media    Whether to add the attachment files.
 * @param array    $content  Content files: name => `stream` and `item`, as
 *                           Bundle\collect_content() keeps them.
 * @param array    $options  The options file's data, or null.
 * @param string   $landing  Where Playground opens (landing_page()'s path).
 * @param string   $language Its language (language()'s locale), or '' for English.
 * @param string[] $mu_plugins Must-use plugin files (as get_mu_plugins() keys) to add.
 * @return string|false The .zip.
 */
function build_zip( $wxr, $wxr_name, $themes = array(), $plugins = array(), $media = false, $content = array(), $options = null, $landing = '/', $language = '', $mu_plugins = array() ) {
	$files = array();
	if ( $media ) {
		$handle = Files\open_read( $wxr );
		if ( $handle ) {
			$files = \Eizport\Bundle\media_files( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	$zip     = Files\temp_file( 'eizport-playground' );
	$entries = array(
		BLUEPRINT => array(
			'data' => wp_json_encode(
				blueprint( $wxr_name, $themes, $plugins, wp_list_pluck( $files, 'entry' ), wp_list_pluck( $content, 'item' ), $options, $landing, $language, $mu_plugins ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			),
		),
		$wxr_name => array( 'path' => $wxr ),
	);
	foreach ( $files as $file ) {
		$entries[ $file['entry'] ] = array( 'path' => $file['path'] );
	}
	foreach ( language_files( $themes, $plugins, $language ) as $entry => $path ) {
		$entries[ $entry ] = array( 'path' => $path );
	}
	foreach ( mu_plugin_files( $mu_plugins ) as $file ) {
		$entries[ MU_FOLDER . '/' . $file ] = array( 'path' => WPMU_PLUGIN_DIR . '/' . $file );
	}
	foreach ( $content as $name => $file ) {
		rewind( $file['stream'] );
		$entries[ CONTENT_FOLDER . '/' . $name ] = array( 'data' => (string) stream_get_contents( $file['stream'] ) );
	}

	// Only what the blueprint can't take from the WordPress.org directory.
	$parts = array();
	foreach ( with_parent_themes( $themes ) as $stylesheet ) {
		if ( '' === directory_theme_zip( $stylesheet ) ) {
			$parts[ theme_zip( $stylesheet ) ] = theme_entries( $stylesheet );
		}
	}
	foreach ( $plugins as $plugin ) {
		if ( '' === directory_plugin_zip( $plugin ) ) {
			$parts[ plugin_zip( $plugin ) ] = other_plugin_entries( $plugin );
		}
	}
	foreach ( mu_plugin_folders_of( $mu_plugins ) as $folder ) {
		$parts[ MU_FOLDER . '/' . $folder . '.zip' ] = folder_entries( WPMU_PLUGIN_DIR . '/' . $folder, $folder );
	}

	$ok    = true;
	$temps = array( $zip );
	foreach ( $parts as $name => $part_entries ) {
		$part    = Files\temp_file( 'eizport-part' );
		$temps[] = $part;
		$ok      = $ok && $part_entries && create_zip( $part, $part_entries );

		$entries[ $name ] = array( 'path' => $part );
	}
	$ok = $ok && create_zip( $zip, $entries );

	array_map( 'wp_delete_file', $ok ? array_slice( $temps, 1 ) : $temps );

	return $ok ? $zip : false;
}

/**
 * Where the Playground .zip opens: the home page ("home", the default), the
 * dashboard ("admin"), a page of this site (`array( 'page' => ID )` or
 * "page:ID": `/?pagename=<its path>`, which finds it whatever the
 * permalinks and its ID over there) or a path ("/shop/"). A page that
 * isn't published, or a path that isn't one, opens the home page.
 *
 * @param mixed $choice The choice.
 * @return string Path, starting with "/".
 */
function landing_page( $choice ) {
	if ( is_string( $choice ) && preg_match( '/^page:(\d+)$/', $choice, $m ) ) {
		$choice = array( 'page' => (int) $m[1] );
	}
	if ( is_array( $choice ) && ! empty( $choice['page'] ) ) {
		$page = get_post( (int) $choice['page'] );
		return $page && 'page' === $page->post_type && 'publish' === $page->post_status ? '/?pagename=' . get_page_uri( $page ) : '/';
	}
	if ( 'admin' === $choice ) {
		return '/wp-admin/';
	}
	if ( is_string( $choice ) && 0 === strpos( $choice, '/' ) && 0 !== strpos( $choice, '//' ) ) {
		$path  = (string) wp_parse_url( $choice, PHP_URL_PATH );
		$query = (string) wp_parse_url( $choice, PHP_URL_QUERY );
		return '' === $path ? '/' : $path . ( '' === $query ? '' : '?' . $query );
	}

	return '/';
}

/**
 * The language the Playground .zip opens in: this site's ("site", the
 * default), the exporting user's ("user") or a locale ("pt_BR"). English,
 * and a language this site doesn't have installed (its translations may not
 * exist on WordPress.org, and Playground's setSiteLanguage step fails
 * without them), give ''.
 *
 * @param string $choice The choice.
 * @return string Locale, or '' for English.
 */
function language( $choice ) {
	$choice = (string) $choice;
	$locale = 'site' === $choice || '' === $choice ? get_locale() : ( 'user' === $choice ? get_user_locale() : $choice );

	return 'en_US' !== $locale && in_array( $locale, get_available_languages(), true ) ? $locale : '';
}

/**
 * The language choices, for the screens: "site" (always: English when this
 * site's language is, or isn't installed), "user" (only when it is another
 * one) and "en_US" (only when the site's isn't English), each with its
 * locale and its name in that language ("Português do Brasil").
 *
 * @return array<int, array{value: string, locale: string, name: string}>
 */
function language_choices() {
	$site    = language( 'site' );
	$user    = language( 'user' );
	$locales = array( 'site' => '' === $site ? 'en_US' : $site );
	if ( '' !== $user && $user !== $site ) {
		$locales['user'] = $user;
	}
	if ( '' !== $site ) {
		$locales['en_US'] = 'en_US';
	}

	$names = array( 'en_US' => array( 'native_name' => 'English (United States)' ) );
	if ( '' !== $site || '' !== $user ) {
		// Cached by core for a while (Settings → General asks for the same list).
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$names += wp_get_available_translations();
	}

	$choices = array();
	foreach ( $locales as $value => $locale ) {
		$choices[] = array(
			'value'  => $value,
			'locale' => $locale,
			'name'   => (string) ( $names[ $locale ]['native_name'] ?? $locale ),
		);
	}

	return $choices;
}

/**
 * This site's translations of the themes (with their parents) and plugins
 * that go inside the .zip — the ones from the WordPress.org directory get
 * theirs from there, through setSiteLanguage — in that language:
 * "languages/plugins/<text domain>-<locale>.mo" (and .l10n.php, and the
 * scripts' .json) => file.
 *
 * @param string[] $themes   Stylesheets chosen.
 * @param string[] $plugins  Plugin files chosen.
 * @param string   $language Locale, or ''.
 * @return array<string, string>
 */
function language_files( $themes, $plugins, $language ) {
	if ( '' === $language ) {
		return array();
	}
	$domains = array();
	foreach ( with_parent_themes( $themes ) as $stylesheet ) {
		if ( '' === directory_theme_zip( $stylesheet ) ) {
			$domains[] = array( 'themes', (string) wp_get_theme( $stylesheet )->get( 'TextDomain' ) );
		}
	}
	foreach ( $plugins as $plugin ) {
		if ( '' === directory_plugin_zip( $plugin ) ) {
			$domains[] = array( 'plugins', (string) ( plugin_choices()[ $plugin ]['TextDomain'] ?? '' ) );
		}
	}

	$files = array();
	foreach ( $domains as list( $kind, $domain ) ) {
		if ( '' === $domain ) {
			continue;
		}
		// Not "pt_BR_formal" for "pt_BR", nor the .po (GLOB_BRACE isn't everywhere).
		$name = '/^' . preg_quote( $domain . '-' . $language, '/' ) . '(\.mo|\.l10n\.php|-[0-9a-f]{32}\.json)$/';
		foreach ( array_filter( (array) glob( WP_LANG_DIR . '/' . $kind . '/' . $domain . '-' . $language . '*' ) ) as $path ) {
			if ( preg_match( $name, basename( $path ) ) ) {
				$files[ 'languages/' . $kind . '/' . basename( $path ) ] = $path;
			}
		}
	}

	return $files;
}

/**
 * The blueprint: installs the chosen plugins and themes (from the .zip),
 * activates a theme, then imports the WXR with Playground's importWxr step.
 * With attachment files or content files, they are written into
 * Playground first, with a must-use plugin that answers the importer's
 * downloads from the attachment files and puts each content file into its
 * item; both are removed after the import.
 *
 * The theme activated is this site's, when it was chosen; else the first
 * one chosen. Parents of child themes are installed too.
 *
 * @param string   $wxr_name Name of the WXR inside the .zip.
 * @param string[] $themes   Stylesheets of the themes chosen.
 * @param string[] $plugins  Plugin files of the plugins in the .zip.
 * @param string[] $files    Attachment URL => file in the .zip ("uploads/…").
 * @param array    $content  Content file name ("<slug>.html", in
 *                           post_content/) => item: original `id`, `type`
 *                           and `slug`.
 * @param array    $options  The options file's data, or null.
 * @param string   $landing  Where Playground opens (landing_page()'s path).
 * @param string   $language Its language (language()'s locale), or '' for English.
 * @param string[] $mu_plugins Must-use plugin files to write (with the folders they load).
 * @return array
 */
function blueprint( $wxr_name, $themes = array(), $plugins = array(), $files = array(), $content = array(), $options = null, $landing = '/', $language = '', $mu_plugins = array() ) {
	$steps = array();
	foreach ( $plugins as $plugin ) {
		$directory = directory_plugin_zip( $plugin );
		$steps[]   = array(
			'step'       => 'installPlugin',
			'pluginData' => '' !== $directory
				? array(
					'resource' => 'wordpress.org/plugins',
					'slug'     => $directory,
				)
				: array(
					'resource' => 'bundled',
					'path'     => '/' . plugin_zip( $plugin ),
				),
			// Active or inactive, as on this site (Playground activates by default).
			'options'    => array( 'activate' => is_plugin_active( $plugin ) ),
		);
	}

	// Names of this site's translations in the .zip, before $themes holds the parents too.
	$translations = array_keys( language_files( $themes, $plugins, $language ) );

	$all      = with_parent_themes( $themes );
	$activate = theme_to_activate( array_intersect( $themes, $all ) );
	$themes   = $all;
	// Parents before children, the activated theme last.
	$rank = function ( $stylesheet ) use ( $activate ) {
		return $stylesheet === $activate ? 2 : (int) (bool) wp_get_theme( $stylesheet )->parent();
	};
	usort(
		$themes,
		function ( $a, $b ) use ( $rank ) {
			return $rank( $a ) - $rank( $b );
		}
	);
	foreach ( $themes as $stylesheet ) {
		$directory = directory_theme_zip( $stylesheet );
		$steps[]   = array(
			'step'      => 'installTheme',
			'themeData' => '' !== $directory
				? array(
					'resource' => 'wordpress.org/themes',
					'slug'     => $directory,
				)
				: array(
					'resource' => 'bundled',
					'path'     => '/' . theme_zip( $stylesheet ),
				),
			'options'   => array( 'activate' => $stylesheet === $activate ),
		);
	}

	if ( '' !== $language ) {
		// After the installs: it fetches WordPress's translations and the installed themes' and plugins'.
		$steps[] = array(
			'step'     => 'setSiteLanguage',
			'language' => $language,
		);
		// Then the ones only this site has (setSiteLanguage made the folders).
		foreach ( $translations as $entry ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => '/wordpress/wp-content/' . $entry,
				'data' => array(
					'resource' => 'bundled',
					'path'     => '/' . $entry,
				),
			);
		}
	}

	// Must-use plugins before the import: what they register (post types, taxonomies) is there for it.
	$mu_files   = mu_plugin_files( $mu_plugins );
	$mu_folders = mu_plugin_folders_of( $mu_plugins );
	if ( $mu_files || $mu_folders ) {
		$steps[] = array(
			'step' => 'mkdir',
			'path' => '/wordpress/wp-content/' . MU_FOLDER,
		);
	}
	foreach ( $mu_folders as $folder ) {
		$steps[] = array(
			'step'          => 'unzip',
			'zipFile'       => array(
				'resource' => 'bundled',
				'path'     => '/' . MU_FOLDER . '/' . $folder . '.zip',
			),
			'extractToPath' => '/wordpress/wp-content/' . MU_FOLDER,
		);
	}
	foreach ( $mu_files as $file ) {
		$steps[] = array(
			'step' => 'writeFile',
			'path' => '/wordpress/wp-content/' . MU_FOLDER . '/' . $file,
			'data' => array(
				'resource' => 'bundled',
				'path'     => '/' . MU_FOLDER . '/' . $file,
			),
		);
	}

	$entries = array_merge( array_values( $files ), preg_filter( '#^#', CONTENT_FOLDER . '/', array_keys( $content ) ) );
	$temp    = $entries || $options;
	if ( $temp ) {
		// writeFile doesn't create folders (mu-plugins/ may be made already, for the must-use plugins).
		$folders = array_unique( array_merge( $mu_files || $mu_folders ? array() : array( dirname( MEDIA_PLUGIN ) ), array_map( 'dirname', preg_filter( '#^#', MEDIA_DIR . '/', $entries ) ) ) );
		foreach ( $folders as $folder ) {
			$steps[] = array(
				'step' => 'mkdir',
				'path' => $folder,
			);
		}
		foreach ( $entries as $entry ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => MEDIA_DIR . '/' . $entry,
				'data' => array(
					'resource' => 'bundled',
					'path'     => '/' . $entry,
				),
			);
		}
		if ( $files ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => MEDIA_DIR . '/map.json',
				'data' => wp_json_encode( $files, JSON_UNESCAPED_SLASHES ),
			);
		}
		if ( $content ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => MEDIA_DIR . '/content.json',
				'data' => wp_json_encode( $content, JSON_UNESCAPED_SLASHES ),
			);
		}
		if ( $options ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => MEDIA_DIR . '/' . \Eizport\Options\FILE,
				'data' => wp_json_encode( $options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			);
			$steps[] = array(
				'step' => 'writeFile',
				'path' => OPTIONS_PLUGIN,
				'data' => options_plugin_code(),
			);
		}
		$steps[] = array(
			'step' => 'writeFile',
			'path' => MEDIA_PLUGIN,
			'data' => media_plugin_code(),
		);
	}

	$steps[] = array(
		'step' => 'importWxr',
		'file' => array(
			'resource' => 'bundled',
			'path'     => '/' . $wxr_name,
		),
	);

	if ( $temp ) {
		if ( $options ) {
			$steps[] = array(
				'step' => 'rm',
				'path' => OPTIONS_PLUGIN,
			);
		}
		$steps[] = array(
			'step' => 'rm',
			'path' => MEDIA_PLUGIN,
		);
		$steps[] = array(
			'step' => 'rmdir',
			'path' => MEDIA_DIR,
		);
	}

	$blueprint = array(
		'$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
		'landingPage' => '' !== (string) $landing ? (string) $landing : '/',
		'login'       => true,
		'steps'       => $steps,
	);

	/**
	 * Filters the Playground blueprint of an export.
	 *
	 * @param array  $blueprint Blueprint.
	 * @param string $wxr_name  Name of the WXR inside the .zip.
	 */
	return apply_filters( 'eizport_playground_blueprint', $blueprint, $wxr_name );
}

/**
 * Code of the temporary must-use plugin (includes/playground-media.php):
 * answers the importer's attachment downloads from the files the blueprint
 * wrote to MEDIA_DIR, and puts the content files into their items; then
 * includes/import-menus.php (menu locations, existing menus, navigation refs).
 *
 * @return string
 */
function media_plugin_code() {
	// With the menus' import code (import-menus.php) after it: one must-use plugin, one PHP file.
	$code = (string) file_get_contents( __DIR__ . '/playground-media.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	foreach ( array( 'import-menus.php', 'import-blocks.php', 'import-links.php', 'import-advanced-custom-fields.php' ) as $file ) {
		$code .= "\n" . preg_replace( '/^<\?php\s*/', '', (string) file_get_contents( __DIR__ . '/' . $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	return $code;
}

/**
 * Code of the temporary must-use plugin that sets the options
 * (includes/apply-options.php).
 *
 * @return string
 */
function options_plugin_code() {
	return (string) file_get_contents( __DIR__ . '/apply-options.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * The themes, each followed by its parent when it is a child theme.
 *
 * @param string[] $themes Stylesheets.
 * @return string[]
 */
function with_parent_themes( $themes ) {
	$all = array();
	foreach ( $themes as $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			continue;
		}
		$all[] = $theme->get_stylesheet();
		if ( $theme->parent() ) {
			$all[] = $theme->parent()->get_stylesheet();
		}
	}

	return array_values( array_unique( $all ) );
}

/**
 * The theme the blueprint activates, among the ones chosen (not the parents
 * added for them): this site's theme, when chosen; else the first one.
 *
 * @param string[] $themes Stylesheets chosen.
 * @return string Stylesheet, or '' to keep Playground's default.
 */
function theme_to_activate( $themes ) {
	$themes = array_values( array_filter( $themes, 'is_string' ) );

	return in_array( get_stylesheet(), $themes, true ) ? get_stylesheet() : ( $themes[0] ?? '' );
}

/**
 * Name of a theme's .zip inside the Playground .zip.
 *
 * @param string $stylesheet Stylesheet.
 * @return string
 */
function theme_zip( $stylesheet ) {
	return 'themes/' . sanitize_file_name( $stylesheet ) . '.zip';
}

/**
 * Name of a plugin's .zip inside the Playground .zip.
 *
 * @param string $plugin Plugin file.
 * @return string
 */
function plugin_zip( $plugin ) {
	return 'plugins/' . sanitize_file_name( plugin_slug( $plugin ) ) . '.zip';
}

/**
 * A plugin's file in the WordPress.org directory, in the version this site
 * has — "woocommerce.11.2.1.zip", which Playground's wordpress.org/plugins
 * resource downloads as is — or '' when it isn't from the directory (custom,
 * private, premium) and goes in the .zip.
 *
 * @param string $plugin Plugin file.
 * @return string
 */
function directory_plugin_zip( $plugin ) {
	$item    = directory_item( 'plugins', $plugin );
	$version = (string) ( plugin_choices()[ $plugin ]['Version'] ?? '' );
	$slug    = $item && 0 === strpos( (string) ( $item['id'] ?? '' ), 'w.org/plugins/' ) ? (string) ( $item['slug'] ?? '' ) : '';

	return directory_zip( $slug, $version, 'plugin', $plugin );
}

/**
 * A theme's file in the WordPress.org directory, in the version this site
 * has ("twentytwentyfive.1.3.zip"), or '' when it goes in the .zip.
 *
 * @param string $stylesheet Stylesheet.
 * @return string
 */
function directory_theme_zip( $stylesheet ) {
	$item    = directory_item( 'themes', $stylesheet );
	$theme   = wp_get_theme( $stylesheet );
	$version = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	$slug    = $item && 0 === strpos( (string) ( $item['package'] ?? '' ), 'https://downloads.wordpress.org/theme/' ) ? (string) ( $item['theme'] ?? '' ) : '';

	return directory_zip( $slug, $version, 'theme', $stylesheet );
}

/**
 * "<slug>.<version>.zip", when both are plain enough for a download URL.
 *
 * @param string $slug    Directory slug, or ''.
 * @param string $version Installed version.
 * @param string $type    "plugin" or "theme".
 * @param string $id      Plugin file or stylesheet.
 * @return string
 */
function directory_zip( $slug, $version, $type, $id ) {
	$zip = preg_match( '/^[a-z0-9-]+$/', $slug ) && preg_match( '/^[0-9A-Za-z.+-]+$/', $version ) ? $slug . '.' . $version . '.zip' : '';

	/**
	 * Filters the WordPress.org file a Playground .zip installs a theme or
	 * plugin from ("woocommerce.11.2.1.zip"); '' puts it in the .zip.
	 *
	 * @param string $zip  Directory file, or ''.
	 * @param string $type "plugin" or "theme".
	 * @param string $id   Plugin file or stylesheet.
	 */
	return (string) apply_filters( 'eizport_playground_from_directory', $zip, $type, $id );
}

/**
 * What WordPress's own update check knows of an installed plugin or theme
 * from the WordPress.org directory (the update_plugins / update_themes
 * transients), or null when it isn't from there. A check that hasn't run
 * yet, or doesn't know the item, runs once (core's wp_update_plugins() /
 * wp_update_themes(), as Dashboard → Updates does).
 *
 * @param string $kind "plugins" or "themes".
 * @param string $id   Plugin file or stylesheet.
 * @return array|null
 */
function directory_item( $kind, $id ) {
	static $refreshed = array();

	$find = function () use ( $kind, $id ) {
		$updates = get_site_transient( 'update_' . $kind );
		if ( ! is_object( $updates ) ) {
			return array( null, false );
		}
		foreach ( array( 'response', 'no_update' ) as $list ) {
			if ( isset( $updates->{$list}[ $id ] ) ) {
				return array( (array) $updates->{$list}[ $id ], true );
			}
		}
		return array( null, isset( $updates->checked[ $id ] ) );
	};

	list( $item, $known ) = $find();
	if ( ! $known && empty( $refreshed[ $kind ] ) ) {
		$refreshed[ $kind ] = true;
		require_once ABSPATH . WPINC . '/update.php';
		'plugins' === $kind ? wp_update_plugins() : wp_update_themes();
		list( $item ) = $find();
	}

	return $item;
}

/**
 * "akismet/akismet.php" → "akismet"; "hello.php" → "hello".
 *
 * @param string $plugin Plugin file.
 * @return string
 */
function plugin_slug( $plugin ) {
	return false === strpos( $plugin, '/' ) ? basename( $plugin, '.php' ) : dirname( $plugin );
}

/**
 * Themes that can go in the .zip: stylesheet => theme.
 *
 * @return \WP_Theme[]
 */
function theme_choices() {
	return wp_get_themes( array( 'errors' => false ) );
}

/**
 * Plugins that can go in the .zip: every installed plugin.
 *
 * @return array<string, array> Plugin file => plugin data.
 */
function plugin_choices() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	return get_plugins();
}

/**
 * Must-use plugins that can go in the .zip: the site's, without the ones the
 * platform (WordPress Studio, hosts) and Eizport put there.
 *
 * @return array<string, array> File => plugin data, as get_mu_plugins() gives them.
 */
function mu_plugin_choices() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$choices = array_diff_key( get_mu_plugins(), array_flip( PLATFORM_MU_PLUGINS ) );

	/**
	 * Filters the must-use plugins a Playground .zip can take (a platform's
	 * loader out, say).
	 *
	 * @param array $choices File => plugin data.
	 */
	return (array) apply_filters( 'eizport_playground_mu_plugins', $choices );
}

/**
 * The folders of WPMU_PLUGIN_DIR a must-use plugin loads: the ones its code
 * requires or includes from its own folder (`__DIR__ . '/<folder>/…'`,
 * `dirname( __FILE__ ) . '/<folder>/…'`, `WPMU_PLUGIN_DIR . '/<folder>/…'`),
 * and `<name>/` next to `<name>.php`. A platform's folder never goes.
 *
 * @param string $file Must-use plugin file.
 * @return string[] Folder names.
 */
function mu_plugin_folders( $file ) {
	$path = WPMU_PLUGIN_DIR . '/' . $file;
	if ( ! is_file( $path ) ) {
		return array();
	}
	$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$folders = array( basename( $file, '.php' ) );
	preg_match_all( '#\b(?:require|include)(?:_once)?\s*\(?\s*(?:__DIR__|dirname\s*\(\s*__FILE__\s*\)|WPMU_PLUGIN_DIR)\s*\.\s*[\'"]/([^/\'"]+)/#', $code, $m );
	$folders = array_merge( $folders, $m[1] );

	return array_values(
		array_filter(
			array_unique( $folders ),
			function ( $folder ) {
				return '' !== $folder && '.' !== $folder[0] && ! in_array( $folder, PLATFORM_MU_PLUGINS, true ) && is_dir( WPMU_PLUGIN_DIR . '/' . $folder );
			}
		)
	);
}

/**
 * The must-use plugin files chosen that exist.
 *
 * @param string[] $mu_plugins Files chosen.
 * @return string[]
 */
function mu_plugin_files( $mu_plugins ) {
	return array_values( array_intersect( array_map( 'strval', (array) $mu_plugins ), array_keys( mu_plugin_choices() ) ) );
}

/**
 * The folders the must-use plugins chosen load, once each.
 *
 * @param string[] $mu_plugins Files chosen.
 * @return string[]
 */
function mu_plugin_folders_of( $mu_plugins ) {
	$folders = array();
	foreach ( mu_plugin_files( $mu_plugins ) as $file ) {
		$folders = array_merge( $folders, mu_plugin_folders( $file ) );
	}

	return array_values( array_unique( $folders ) );
}

/**
 * A theme's files for its .zip.
 *
 * @param string $stylesheet Stylesheet.
 * @return array<string, array{path: string}>
 */
function theme_entries( $stylesheet ) {
	$theme = wp_get_theme( $stylesheet );

	return $theme->exists() ? folder_entries( $theme->get_stylesheet_directory(), $theme->get_stylesheet() ) : array();
}

/**
 * Another plugin's files for its .zip: its folder, or its single file.
 *
 * @param string $plugin Plugin file.
 * @return array<string, array{path: string}>
 */
function other_plugin_entries( $plugin ) {
	if ( ! array_key_exists( $plugin, plugin_choices() ) ) {
		return array();
	}
	$path = WP_PLUGIN_DIR . '/' . $plugin;
	if ( false === strpos( $plugin, '/' ) ) {
		return is_file( $path ) ? array( $plugin => array( 'path' => $path ) ) : array();
	}

	return folder_entries( dirname( $path ), plugin_slug( $plugin ) );
}

/**
 * Every file in a folder, under `<slug>/`, leaving out hidden files and
 * version control and dependency folders.
 *
 * @param string $dir  Folder.
 * @param string $slug Folder name in the .zip.
 * @return array<string, array{path: string}>
 */
function folder_entries( $dir, $slug ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$entries = array();
	foreach ( (array) list_files( $dir, 100, array( 'node_modules' ) ) as $path ) {
		if ( is_file( $path ) ) {
			$entries[ $slug . substr( $path, strlen( $dir ) ) ] = array( 'path' => $path );
		}
	}
	ksort( $entries );

	return $entries;
}

/**
 * Creates a .zip, with ZipArchive or else core's PclZip.
 *
 * @param string                                             $target  File to create (replaced).
 * @param array<string, array{path?: string, data?: string}> $entries Name in the .zip => file path or data.
 * @return bool
 */
function create_zip( $target, $entries ) {
	wp_delete_file( $target );

	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target, \ZipArchive::CREATE ) ) {
			return false;
		}
		foreach ( $entries as $name => $entry ) {
			$added = isset( $entry['path'] ) ? $zip->addFile( $entry['path'], $name ) : $zip->addFromString( $name, (string) $entry['data'] );
			if ( ! $added ) {
				$zip->close();
				return false;
			}
		}
		return $zip->close();
	}

	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$list = array();
	foreach ( $entries as $name => $entry ) {
		$list[] = isset( $entry['path'] )
			? array(
				PCLZIP_ATT_FILE_NAME          => $entry['path'],
				PCLZIP_ATT_FILE_NEW_FULL_NAME => $name,
			)
			: array(
				PCLZIP_ATT_FILE_NAME    => $name,
				PCLZIP_ATT_FILE_CONTENT => (string) $entry['data'],
			);
	}

	return 0 !== ( new \PclZip( $target ) )->create( $list );
}
