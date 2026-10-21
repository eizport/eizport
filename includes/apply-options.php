<?php
/**
 * Applies the options of a bundle after its import, with the IDs they hold
 * mapped to the imported items — never the ones that would break, move or
 * open the site (eizport_never_set_option()): used by the plugin, and written by a
 * Playground blueprint as the temporary must-use plugin
 * wp-content/mu-plugins/eizport-options.php (so it can't use the plugin's
 * namespace or other files).
 *
 * The options file (options.json) holds `options` (name => value) and
 * `post_ids` (names of options holding a post ID, like page_on_front).
 *
 * @package Eizport
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eizport_never_set_option' ) ) {
	/**
	 * Whether an option is never exported nor set by an import, whatever the
	 * bundle holds: it would break the site, move it, or open it (URLs, keys,
	 * active plugins, roles, who may register and as what, transients).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function eizport_never_set_option( $name ) {
		global $wpdb;

		$never = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', 'db_upgraded', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'recovery_keys', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'users_can_register', 'default_role', $wpdb->prefix . 'user_roles', 'eizport_settings' );

		return in_array( $name, $never, true ) || (bool) preg_match( '/^_(site_)?transient_/', $name );
	}
}

if ( ! function_exists( 'eizport_options_to_apply' ) ) {
	/**
	 * The options to set, with post and term IDs mapped to the imported
	 * ones. An option holding a post that wasn't imported is left out (and
	 * show_on_front = page with it, when it's the homepage); a theme mod
	 * holding one loses that key.
	 *
	 * @param array $data  The options file's data.
	 * @param array $posts Old post ID => new ID (WP_Import::$processed_posts).
	 * @param array $terms Old term ID => new ID (WP_Import::$processed_terms).
	 * @param array $urls  Old URL => new (WP_Import::$url_remap): media URLs in theme mods and widgets.
	 * @return array Option name => value.
	 */
	function eizport_options_to_apply( $data, $posts, $terms, $urls = array() ) {
		$options  = isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array();
		$post_ids = isset( $data['post_ids'] ) && is_array( $data['post_ids'] ) ? $data['post_ids'] : array();
		$apply    = array();

		foreach ( $options as $name => $value ) {
			$name = (string) $name;
			// A bundle is a file anyone could have written: these are never set.
			if ( eizport_never_set_option( $name ) ) {
				continue;
			}
			if ( in_array( $name, $post_ids, true ) && is_scalar( $value ) ) {
				$old = (int) $value;
				if ( $old && ! isset( $posts[ $old ] ) ) {
					continue;
				}
				$value = $old ? (int) $posts[ $old ] : 0;
			}
			if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
				if ( isset( $value['custom_logo'] ) ) {
					$old = (int) $value['custom_logo'];
					if ( isset( $posts[ $old ] ) ) {
						$value['custom_logo'] = (int) $posts[ $old ];
					} else {
						unset( $value['custom_logo'] );
					}
				}
				// The header image's attachment, and the media URLs (the header's data is an object in core).
				if ( isset( $value['header_image_data'] ) ) {
					$header = (array) $value['header_image_data'];
					if ( isset( $header['attachment_id'] ) ) {
						$old                     = (int) $header['attachment_id'];
						$header['attachment_id'] = $posts[ $old ] ?? $old;
					}
					foreach ( array( 'url', 'thumbnail_url' ) as $key ) {
						if ( isset( $header[ $key ] ) ) {
							$header[ $key ] = eizport_remap_urls( (string) $header[ $key ], $urls );
						}
					}
					$value['header_image_data'] = (object) $header;
				}
				foreach ( array( 'header_image', 'background_image' ) as $key ) {
					if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
						$value[ $key ] = eizport_remap_urls( $value[ $key ], $urls );
					}
				}
				// The custom CSS comes as its own entry: this site's post, not the other's.
				unset( $value['custom_css_post_id'] );
				if ( isset( $value['nav_menu_locations'] ) && is_array( $value['nav_menu_locations'] ) ) {
					foreach ( $value['nav_menu_locations'] as $location => $menu ) {
						if ( isset( $terms[ (int) $menu ] ) ) {
							$value['nav_menu_locations'][ $location ] = (int) $terms[ (int) $menu ];
						} else {
							unset( $value['nav_menu_locations'][ $location ] );
						}
					}
				}
				// Merged into this site's theme mods: what the file doesn't hold stays (menu locations too).
				$current = (array) get_option( $name, array() );
				if ( isset( $value['nav_menu_locations'], $current['nav_menu_locations'] ) && is_array( $current['nav_menu_locations'] ) ) {
					$value['nav_menu_locations'] = array_merge( $current['nav_menu_locations'], $value['nav_menu_locations'] );
				}
				$value = array_merge( $current, $value );
			}
			// Widgets: the menu of a Navigation Menu widget, the media of media widgets, the URLs in block widgets.
			if ( 0 === strpos( $name, 'widget_' ) && is_array( $value ) ) {
				foreach ( $value as $number => $widget ) {
					if ( ! is_array( $widget ) ) {
						continue;
					}
					if ( 'widget_nav_menu' === $name && isset( $widget['nav_menu'] ) ) {
						$widget['nav_menu'] = $terms[ (int) $widget['nav_menu'] ] ?? (int) $widget['nav_menu'];
					}
					if ( isset( $widget['attachment_id'] ) ) {
						$widget['attachment_id'] = $posts[ (int) $widget['attachment_id'] ] ?? (int) $widget['attachment_id'];
					}
					foreach ( array( 'url', 'content' ) as $key ) {
						if ( isset( $widget[ $key ] ) && is_string( $widget[ $key ] ) ) {
							$widget[ $key ] = eizport_remap_urls( $widget[ $key ], $urls );
						}
					}
					$value[ $number ] = $widget;
				}
			}
			$apply[ $name ] = $value;
		}

		// A static homepage needs its page.
		if ( isset( $apply['show_on_front'] ) && 'page' === $apply['show_on_front'] && empty( $apply['page_on_front'] ) && in_array( 'page_on_front', $post_ids, true ) && isset( $options['page_on_front'] ) ) {
			unset( $apply['show_on_front'] );
		}

		return $apply;
	}

	/**
	 * Sets the options of a bundle's options file.
	 *
	 * @param array $data  The options file's data.
	 * @param array $posts Old post ID => new ID.
	 * @param array $terms Old term ID => new ID.
	 * @param array $urls  Old URL => new (WP_Import::$url_remap).
	 * @return string[] Names of the options set, and custom_css, global_styles, global_css when set.
	 */
	function eizport_apply_options( $data, $posts, $terms, $urls = array() ) {
		$apply = eizport_options_to_apply( $data, $posts, $terms, $urls );
		foreach ( $apply as $name => $value ) {
			update_option( $name, $value );
		}

		return array_merge( array_keys( $apply ), eizport_apply_look( $data, $urls ) );
	}

	/**
	 * Old URLs replaced by the new ones, longest first (as the importer does).
	 *
	 * @param string $text Text.
	 * @param array  $urls Old URL => new.
	 * @return string
	 */
	function eizport_remap_urls( $text, $urls ) {
		if ( ! $urls || '' === $text ) {
			return $text;
		}
		uksort(
			$urls,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return str_replace( array_keys( $urls ), array_values( $urls ), $text );
	}

	/**
	 * Sets the look entries of an options file on the active theme: the
	 * Customizer's custom CSS, and the Site Editor's global styles and
	 * global CSS (a block theme only; each leaves the other's part as it is).
	 *
	 * @param array $data The options file's data.
	 * @param array $urls Old URL => new.
	 * @return string[] What was set: custom_css, global_styles, global_css.
	 */
	function eizport_apply_look( $data, $urls = array() ) {
		$set = array();
		if ( isset( $data['custom_css']['css'] ) && is_string( $data['custom_css']['css'] ) ) {
			$result = wp_update_custom_css_post( eizport_remap_urls( $data['custom_css']['css'], $urls ), array( 'stylesheet' => get_stylesheet() ) );
			if ( ! is_wp_error( $result ) ) {
				$set[] = 'custom_css';
			}
		}

		$styles = isset( $data['global_styles']['content'] ) && is_array( $data['global_styles']['content'] ) ? $data['global_styles']['content'] : null;
		$css    = isset( $data['global_css']['css'] ) && is_string( $data['global_css']['css'] ) ? $data['global_css']['css'] : null;
		if ( ( null === $styles && null === $css ) || ! wp_is_block_theme() || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return $set;
		}
		$post    = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$current = isset( $post['post_content'] ) ? json_decode( (string) $post['post_content'], true ) : null;
		$current = is_array( $current ) ? $current : array(
			'version'                     => 2,
			'isGlobalStylesUserThemeJSON' => true,
		);
		$keep    = $current['styles']['css'] ?? null;
		if ( null !== $styles ) {
			$styles  = json_decode( eizport_remap_urls( (string) wp_json_encode( $styles, JSON_UNESCAPED_SLASHES ), $urls ), true );
			$current = array_merge( is_array( $styles ) ? $styles : array(), array( 'isGlobalStylesUserThemeJSON' => true ) );
			// The styles leave this site's own CSS as it was.
			unset( $current['styles']['css'] );
			if ( null !== $keep ) {
				$current['styles']['css'] = $keep;
			}
			$set[] = 'global_styles';
		}
		if ( null !== $css ) {
			$current['styles']['css'] = eizport_remap_urls( $css, $urls );
			$set[]                    = 'global_css';
		}
		if ( empty( $post['ID'] ) ) {
			return array();
		}
		// Core adds the theme to a new styles post only for a user who may (tax_input): a CLI import has none.
		wp_set_object_terms( (int) $post['ID'], get_stylesheet(), 'wp_theme' );
		wp_update_post(
			array(
				'ID'           => (int) $post['ID'],
				'post_content' => wp_slash( (string) wp_json_encode( $current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();

		return $set;
	}
}
