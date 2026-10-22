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

		$never = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', 'db_upgraded', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'recovery_keys', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'users_can_register', 'default_role', 'upload_path', 'upload_url_path', $wpdb->prefix . 'user_roles', 'eizport_settings' );

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
			// Widgets as options (bundles made before widgets had their own entry): pointed to this site.
			if ( 0 === strpos( $name, 'widget_' ) && is_array( $value ) ) {
				foreach ( $value as $number => $widget ) {
					if ( is_array( $widget ) ) {
						$value[ $number ] = eizport_map_widget( substr( $name, 7 ), $widget, $posts, $terms, $urls );
					}
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

		$widgets = isset( $data['widgets'] ) && is_array( $data['widgets'] ) ? eizport_apply_widgets( $data['widgets'], $posts, $terms, $urls ) : array();

		return array_merge( array_keys( $apply ), eizport_apply_look( $data, $urls ), $widgets ? array( 'widgets' ) : array() );
	}

	/**
	 * The importer's URL map with the source site's address too (plain and
	 * JSON-escaped), so links in widgets and theme mods point to this site.
	 *
	 * @param array  $urls Old URL => new (WP_Import::$url_remap).
	 * @param string $base The source site's address (WP_Import::$base_url).
	 * @return array
	 */
	function eizport_urls_with_source( $urls, $base ) {
		$from = untrailingslashit( $base );
		$to   = untrailingslashit( home_url() );
		if ( '' !== $from && $from !== $to ) {
			$urls[ $from ]                            = $to;
			$urls[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
		}

		return $urls;
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
	 * A stylesheet's top-level rules, in order: a rule set, an at-rule with
	 * its block (a whole `@media`), or a statement (`@import …;`), each with
	 * the comments right before it. Strings and comments may hold braces.
	 *
	 * @param string $css CSS.
	 * @return string[]
	 */
	function eizport_css_rules( $css ) {
		$css     = (string) $css;
		$rules   = array();
		$current = '';
		$depth   = 0;
		$length  = strlen( $css );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];
			// A comment: copied whole.
			if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end      = strpos( $css, '*/', $i + 2 );
				$end      = false === $end ? $length : $end + 2;
				$current .= substr( $css, $i, $end - $i );
				$i        = $end - 1;
				continue;
			}
			// A string: copied whole, escapes kept.
			if ( '"' === $char || "'" === $char ) {
				$j = $i + 1;
				while ( $j < $length && $css[ $j ] !== $char ) {
					$j += '\\' === $css[ $j ] ? 2 : 1;
				}
				$current .= substr( $css, $i, $j - $i + 1 );
				$i        = $j;
				continue;
			}
			$current .= $char;
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && $depth > 0 ) {
				--$depth;
				if ( 0 === $depth ) {
					$rules[] = trim( $current );
					$current = '';
				}
			} elseif ( ';' === $char && 0 === $depth ) {
				$rules[] = trim( $current );
				$current = '';
			}
		}
		if ( '' !== trim( $current ) ) {
			$rules[] = trim( $current );
		}

		return array_values( array_filter( $rules, 'strlen' ) );
	}

	/**
	 * A rule's text for comparing: whitespace collapsed.
	 *
	 * @param string $rule Rule.
	 * @return string
	 */
	function eizport_css_normalize( $rule ) {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $rule ) );
	}

	/**
	 * The pattern of the head/tail blocks with a name.
	 *
	 * @param string $id The block's name.
	 * @return string
	 */
	function eizport_css_block_pattern( $id ) {
		return '#(?:\n\n)?/\* head ' . preg_quote( $id, '#' ) . ' \*/.*?/\* tail ' . preg_quote( $id, '#' ) . ' \*/#s';
	}

	/**
	 * A block name, as it goes in the comments.
	 *
	 * @param string $id Name.
	 * @return string
	 */
	function eizport_css_block_id( $id ) {
		return trim( (string) preg_replace( '/[^A-Za-z0-9._ -]+/', '-', (string) $id ) );
	}

	/**
	 * Whether this site's CSS (the Customizer's or the Site Editor's) already
	 * has a head/tail block with a name: then an import may replace it.
	 *
	 * @param string $id Name.
	 * @return bool
	 */
	function eizport_css_has_block( $id ) {
		$id = eizport_css_block_id( $id );
		if ( '' === $id ) {
			return false;
		}
		$css = (string) wp_get_custom_css();
		if ( wp_is_block_theme() && class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false );
			$data = isset( $post['post_content'] ) ? json_decode( (string) $post['post_content'], true ) : null;
			$css .= (string) ( $data['styles']['css'] ?? '' );
		}

		return (bool) preg_match( eizport_css_block_pattern( $id ), $css );
	}

	/**
	 * This site's CSS with the file's rules in it.
	 *
	 * - With markers, the rules go between "head ID" and "tail ID" comments,
	 *   a mark of the file they came from (blocks aren't unique: each import
	 *   adds its own).
	 * - Without, rules this site already has (same text) aren't repeated.
	 * - Mode: "end" (after this site's CSS), "start" (before it),
	 *   "replace": the blocks this site has with the same name give way to
	 *   the new one, the rest of its CSS staying (with no such block, or
	 *   without markers, it's "end"), or "replace_all": only the file's.
	 *
	 * @param string $current This site's CSS.
	 * @param string $rules   The file's rules.
	 * @param string $mode    end, start or replace.
	 * @param bool   $markers Mark the block.
	 * @param string $id      The block's name.
	 * @return string
	 */
	function eizport_merge_css( $current, $rules, $mode, $markers, $id ) {
		$current = trim( (string) $current );
		$rules   = trim( (string) $rules );
		$id      = eizport_css_block_id( $id );
		if ( '' === $rules ) {
			return $current;
		}
		if ( $markers && '' !== $id ) {
			$block   = "/* head {$id} */\n{$rules}\n/* tail {$id} */";
			$pattern = eizport_css_block_pattern( $id );
			if ( 'replace' === $mode && preg_match( $pattern, $current ) ) {
				// The first one where it was, the others gone.
				$first = true;
				return trim(
					(string) preg_replace_callback(
						$pattern,
						function ( $found ) use ( $block, &$first ) {
							if ( ! $first ) {
								return '';
							}
							$first = false;
							return ( 0 === strpos( $found[0], "\n\n" ) ? "\n\n" : '' ) . $block;
						},
						$current
					)
				);
			}
		} else {
			$have  = array_map( 'eizport_css_normalize', eizport_css_rules( $current ) );
			$block = implode(
				"\n\n",
				array_filter(
					eizport_css_rules( $rules ),
					function ( $rule ) use ( $have ) {
						return ! in_array( eizport_css_normalize( $rule ), $have, true );
					}
				)
			);
			if ( '' === $block && 'replace_all' !== $mode ) {
				return $current;
			}
		}
		if ( '' === $current || 'replace_all' === $mode ) {
			return $block;
		}

		return 'start' === $mode ? $block . "\n\n" . $current : $current . "\n\n" . $block;
	}

	/**
	 * How an import adds the file's CSS: the file's choice (end or start),
	 * unless whoever imports picked otherwise (the importer's step 1,
	 * eizport/import, WP-CLI), who may also replace the block of the same name,
	 * or all of this site's CSS.
	 *
	 * @param array $entry The file's custom_css or global_css entry.
	 * @return array{0: string, 1: bool} Mode and markers.
	 */
	function eizport_css_choice( $entry ) {
		$asked   = (array) ( $GLOBALS['eizport_look_import'] ?? array() );
		$mode    = (string) ( $asked['css_mode'] ?? $entry['mode'] ?? 'end' );
		$markers = array_key_exists( 'css_markers', $asked ) ? (bool) $asked['css_markers'] : ! empty( $entry['markers'] );

		return array( in_array( $mode, array( 'end', 'start', 'replace', 'replace_all' ), true ) ? $mode : 'end', $markers );
	}

	/**
	 * A value at a dotted path ("styles.color"), or null.
	 *
	 * @param array  $data Data.
	 * @param string $path Path.
	 * @return mixed
	 */
	function eizport_path_get( $data, $path ) {
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * The data with a dotted path set (null: removed).
	 *
	 * @param array  $data  Data.
	 * @param string $path  Path.
	 * @param mixed  $value Value, or null to remove.
	 * @return array
	 */
	function eizport_path_set( $data, $path, $value ) {
		$keys = explode( '.', $path );
		$key  = array_shift( $keys );
		if ( ! $keys ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
			return $data;
		}
		$child = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
		$child = eizport_path_set( $child, implode( '.', $keys ), $value );
		if ( $child ) {
			$data[ $key ] = $child;
		} else {
			unset( $data[ $key ] );
		}

		return $data;
	}

	/**
	 * Whether an import updates what an earlier import of the same file
	 * brought (widgets), as "Update existing" asks.
	 *
	 * @return bool
	 */
	function eizport_look_updates_existing() {
		/**
		 * Filters whether an import updates the widgets an earlier import of
		 * the same file added, instead of adding them again. "Update
		 * existing" turns it on for its import.
		 *
		 * @param bool $update Whether to update them.
		 */
		return (bool) apply_filters( 'eizport_look_update_existing', false );
	}

	/**
	 * A widget's settings pointed to this site: its menu, its media, the
	 * URLs in it.
	 *
	 * @param string $id_base Widget type.
	 * @param array  $widget  Settings.
	 * @param array  $posts   Old post ID => new.
	 * @param array  $terms   Old term ID => new.
	 * @param array  $urls    Old URL => new.
	 * @return array
	 */
	function eizport_map_widget( $id_base, $widget, $posts, $terms, $urls ) {
		if ( 'nav_menu' === $id_base && isset( $widget['nav_menu'] ) ) {
			$widget['nav_menu'] = $terms[ (int) $widget['nav_menu'] ] ?? (int) $widget['nav_menu'];
		}
		if ( isset( $widget['attachment_id'] ) ) {
			$widget['attachment_id'] = $posts[ (int) $widget['attachment_id'] ] ?? (int) $widget['attachment_id'];
		}
		foreach ( array( 'url', 'content', 'text' ) as $key ) {
			if ( isset( $widget[ $key ] ) && is_string( $widget[ $key ] ) ) {
				$widget[ $key ] = eizport_remap_urls( $widget[ $key ], $urls );
			}
		}
		// The post IDs of a block widget's blocks (import-blocks.php, when it's loaded).
		if ( isset( $widget['content'] ) && is_string( $widget['content'] ) && function_exists( 'eizport_replace_block_ids' ) ) {
			$widget['content'] = eizport_replace_block_ids( $widget['content'], eizport_changed_ids( $posts ) );
		}

		return $widget;
	}

	/**
	 * Adds the file's widgets to this site's sidebars, keeping the ones it
	 * has: each gets a free number of its type and goes at the end of its
	 * sidebar (to the inactive widgets when the theme has no such sidebar).
	 * A widget an earlier import of the same file added is updated in place
	 * when the import updates existing items.
	 *
	 * @param array $entry The file's widgets entry: id, sidebars, instances.
	 * @param array $posts Old post ID => new.
	 * @param array $terms Old term ID => new.
	 * @param array $urls  Old URL => new.
	 * @return string[] This site's IDs of the widgets added or updated.
	 */
	function eizport_apply_widgets( $entry, $posts, $terms, $urls ) {
		global $wp_registered_sidebars;

		$instances = isset( $entry['instances'] ) && is_array( $entry['instances'] ) ? $entry['instances'] : array();
		if ( ! $instances ) {
			return array();
		}
		$source   = (string) ( $entry['id'] ?? '' );
		$sidebars = (array) get_option( 'sidebars_widgets', array() );
		$known    = (array) get_option( 'eizport_imported_widgets', array() );
		$update   = eizport_look_updates_existing();
		$where    = array();
		foreach ( (array) ( $entry['sidebars'] ?? array() ) as $sidebar => $ids ) {
			foreach ( (array) $ids as $old ) {
				$where[ (string) $old ] = (string) $sidebar;
			}
		}

		$done = array();
		foreach ( $instances as $old => $instance ) {
			$old = (string) $old;
			if ( ! preg_match( '/^(.+)-(\d+)$/', $old, $m ) || ! is_array( $instance ) ) {
				continue;
			}
			$id_base = $m[1];
			$option  = 'widget_' . $id_base;
			$all     = (array) get_option( $option, array() );
			$widget  = eizport_map_widget( $id_base, $instance, $posts, $terms, $urls );
			$mapped  = (string) ( $known[ $source ][ $old ] ?? '' );
			if ( $update && '' !== $mapped && preg_match( '/-(\d+)$/', $mapped, $n ) && isset( $all[ (int) $n[1] ] ) ) {
				$all[ (int) $n[1] ] = $widget;
				update_option( $option, $all );
				$done[] = $mapped;
				continue;
			}
			$numbers             = array_filter( array_keys( $all ), 'is_int' );
			$number              = $numbers ? max( $numbers ) + 1 : 2;
			$all[ $number ]      = $widget;
			$all['_multiwidget'] = 1;
			update_option( $option, $all );
			$new     = $id_base . '-' . $number;
			$sidebar = $where[ $old ] ?? 'wp_inactive_widgets';
			if ( 'wp_inactive_widgets' !== $sidebar && ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
				$sidebar = 'wp_inactive_widgets';
			}
			$sidebars[ $sidebar ]     = array_merge( (array) ( $sidebars[ $sidebar ] ?? array() ), array( $new ) );
			$known[ $source ][ $old ] = $new;
			$done[]                   = $new;
		}
		update_option( 'sidebars_widgets', $sidebars );
		if ( '' !== $source ) {
			update_option( 'eizport_imported_widgets', $known, false );
		}

		return $done;
	}

	/**
	 * Sets the look entries of an options file on the active theme: the
	 * Customizer's custom CSS and the Site Editor's global CSS (added as the
	 * file and whoever imports ask: see eizport_merge_css()), the Site
	 * Editor's global styles (the groups that came replace the same groups
	 * here; all of them when the file has no list) — a block theme only.
	 *
	 * @param array $data The options file's data.
	 * @param array $urls Old URL => new.
	 * @return string[] What was set: custom_css, global_styles, global_css.
	 */
	function eizport_apply_look( $data, $urls = array() ) {
		$set = array();
		if ( isset( $data['custom_css']['css'] ) && is_string( $data['custom_css']['css'] ) ) {
			list( $mode, $markers ) = eizport_css_choice( $data['custom_css'] );
			$css                    = eizport_merge_css( (string) wp_get_custom_css(), eizport_remap_urls( $data['custom_css']['css'], $urls ), $mode, $markers, (string) ( $data['custom_css']['id'] ?? '' ) );
			$result                 = wp_update_custom_css_post( $css, array( 'stylesheet' => get_stylesheet() ) );
			if ( ! is_wp_error( $result ) ) {
				$set[] = 'custom_css';
			}
		}

		$styles = isset( $data['global_styles']['content'] ) && is_array( $data['global_styles']['content'] ) ? $data['global_styles']['content'] : null;
		$css    = isset( $data['global_css']['css'] ) && is_string( $data['global_css']['css'] ) ? $data['global_css'] : null;
		if ( ( null === $styles && null === $css ) || ! wp_is_block_theme() || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return $set;
		}
		$post    = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$current = isset( $post['post_content'] ) ? json_decode( (string) $post['post_content'], true ) : null;
		$current = is_array( $current ) ? $current : array(
			'version'                     => 2,
			'isGlobalStylesUserThemeJSON' => true,
		);
		if ( null !== $styles ) {
			$styles = json_decode( eizport_remap_urls( (string) wp_json_encode( $styles, JSON_UNESCAPED_SLASHES ), $urls ), true );
			$styles = is_array( $styles ) ? $styles : array();
			$paths  = isset( $data['global_styles']['paths'] ) && is_array( $data['global_styles']['paths'] ) ? $data['global_styles']['paths'] : null;
			if ( null === $paths ) {
				// All of them: this site's styles become the file's, its own CSS kept.
				$keep    = $current['styles']['css'] ?? null;
				$current = array_merge( $styles, array( 'isGlobalStylesUserThemeJSON' => true ) );
				unset( $current['styles']['css'] );
				if ( null !== $keep ) {
					$current['styles']['css'] = $keep;
				}
			} else {
				foreach ( $paths as $path ) {
					$current = eizport_path_set( $current, (string) $path, eizport_path_get( $styles, (string) $path ) );
				}
			}
			$set[] = 'global_styles';
		}
		if ( null !== $css ) {
			list( $mode, $markers )   = eizport_css_choice( $css );
			$current['styles']['css'] = eizport_merge_css( (string) ( $current['styles']['css'] ?? '' ), eizport_remap_urls( (string) $css['css'], $urls ), $mode, $markers, (string) ( $css['id'] ?? '' ) );
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
