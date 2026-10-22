<?php
/**
 * Plugin Name:       EIZPORT_PLUGIN_NAME
 * Description:       EIZPORT_PLUGIN_DESCRIPTION
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Eizport
 * Author URI:        https://eizport.github.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Eizport Bundle:    EIZPORT_BUNDLE_FILE
 * Eizport Update:    EIZPORT_UPDATE_EXISTING
 * Eizport Again:     EIZPORT_IMPORT_AGAIN
 * Text Domain:       EIZPORT_TEXT_DOMAIN
 *
 * Made by Eizport (https://eizport.github.io/): it carries a
 * bundle exported from another WordPress site and adds a screen — Run
 * import, on its row on Plugins — that imports it. Eizport draws that screen
 * and runs the import; without it, the same screen says what to get first. Once the content is
 * imported, the screen offers to deactivate and delete this plugin — and the
 * plugins it needed, when they weren't there before it was activated.
 *
 * @package EizportBundle
 */

defined( 'ABSPATH' ) || exit;

// Closures only: several of these plugins can be active at once.
( static function ( $file ) {
	// Its texts in the exporting site's language (filled in when it was made: locale => English => translation), through WordPress's own gettext filter.
	$i18n = array( /* EIZPORT_I18N */ );
	add_filter(
		'gettext_EIZPORT_TEXT_DOMAIN',
		static function ( $translation, $text ) use ( $i18n ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			return $translation === $text && isset( $i18n[ $locale ][ $text ] ) ? $i18n[ $locale ][ $text ] : $translation;
		},
		10,
		2
	);
	$slug  = basename( dirname( $file ) );
	$page  = 'eizport-bundle-' . $slug;
	$title = 'EIZPORT_MENU_TITLE';
	$main  = 'eizport/eizport.php';

	$requires = 'EIZPORT_REQUIRES';
	// What Eizport's screen needs that isn't there: '' (ready), 'install', 'activate' or 'update' (older than its bundle needs; 1.0.1 doesn't check).
	$missing = static function () use ( $main, $requires ) {
		$older = static function ( $version ) use ( $requires ) {
			return version_compare( (string) preg_replace( '/-.*$/', '', (string) $version ), $requires, '<' );
		};
		if ( function_exists( '\Eizport\BundlePlugin\render' ) ) {
			return defined( 'Eizport\VERSION' ) && $older( constant( 'Eizport\VERSION' ) ) ? 'update' : '';
		}
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $main ) ) {
			return 'install';
		}
		$installed = get_file_data( WP_PLUGIN_DIR . '/' . $main, array( 'version' => 'Version' ) )['version'];
		return $older( $installed ) ? 'update' : 'activate';
	};

	// One screen: Eizport's, or — while Eizport is missing, inactive or too old — the same frame, with Eizport as one more thing to get first.
	$screen = static function () use ( $file, $title, $main, $requires, $missing ) {
		$needs = $missing();
		if ( '' === $needs ) {
			\Eizport\BundlePlugin\render( $file );
			return;
		}
		$no       = '<span class="eizport-check eizport-check--no" aria-hidden="true">✗</span> ';
		$ok       = '<span class="eizport-check eizport-check--ok" aria-hidden="true">✓</span> ';
		$importer = 'wordpress-importer/wordpress-importer.php';

		// A button that installs, activates or updates a plugin on this screen (the script below); without JavaScript, core's own screen.
		$get = static function ( $mode, $plugin, $slug, $name, $url, $label ) {
			return ' <a class="button eizport-bundle-get" href="' . esc_url( $url ) . '" data-mode="' . esc_attr( $mode ) . '" data-plugin="' . esc_attr( $plugin ) . '" data-slug="' . esc_attr( $slug ) . '" data-name="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</a>';
		};
		$bundle = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( $file ) ) . '/' . get_file_data( $file, array( 'bundle' => 'Eizport Bundle' ) )['bundle'];
		$bundle = is_file( $bundle ) ? $bundle : dirname( $bundle );

		echo '<div class="wrap"><div class="eizport-import__header"><div class="eizport-import__heading"><div class="eizport-import__title">';
		echo '<svg viewBox="0 0 64 64" width="24" height="24" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#3858e9"/><g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"><path stroke="#ffffff" d="M20 14 14 20l6 6M14 20h32L18 44"/><path stroke="#f2994a" d="M18 44h32m-6-6 6 6-6 6"/></g></svg>';
		echo '<h2>' . esc_html( $title ) . '</h2></div>';
		echo '<p class="eizport-import__subtitle">' . esc_html( __( 'Imports the content this plugin holds into this site: posts, pages, media and settings, as they were exported.', 'EIZPORT_TEXT_DOMAIN' ) ) . '</p></div>';
		echo '<div class="eizport-import__actions"><button type="button" class="button button-primary" disabled>' . esc_html( __( 'Run import', 'EIZPORT_TEXT_DOMAIN' ) ) . '</button></div></div>';
		echo '<hr class="wp-header-end"><div class="eizport-import__body">';
		// What it holds: Eizport reads the bundle, so it waits for it.
		echo '<details class="eizport-import__card eizport-import__again eizport-import__waiting" aria-disabled="true"><summary tabindex="-1">' . esc_html( __( 'What it holds', 'EIZPORT_TEXT_DOMAIN' ) ) . '</summary></details>';
		echo '<div class="eizport-import__card"><h3>' . esc_html( __( 'Before importing', 'EIZPORT_TEXT_DOMAIN' ) ) . '</h3><ul class="eizport-checks">';

		// Eizport.
		echo '<li class="eizport-requirement">' . $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		if ( 'install' === $needs ) {
			echo esc_html( __( 'Eizport is needed.', 'EIZPORT_TEXT_DOMAIN' ) );
			if ( current_user_can( 'install_plugins' ) ) {
				echo $get( 'install', $main, 'eizport', 'Eizport', wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=eizport' ), 'install-plugin_eizport' ), __( 'Install Now', 'EIZPORT_TEXT_DOMAIN' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $get.
			}
		} elseif ( 'activate' === $needs ) {
			echo esc_html( __( 'Eizport is installed but not active.', 'EIZPORT_TEXT_DOMAIN' ) );
			if ( current_user_can( 'activate_plugins' ) ) {
				echo $get( 'activate', $main, 'eizport', 'Eizport', wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $main ) ), 'activate-plugin_' . $main ), __( 'Activate', 'EIZPORT_TEXT_DOMAIN' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $get.
			}
		} else {
			$version = defined( 'Eizport\VERSION' ) ? constant( 'Eizport\VERSION' ) : get_file_data( WP_PLUGIN_DIR . '/' . $main, array( 'version' => 'Version' ) )['version'];
			/* translators: 1: the oldest Eizport version that reads the bundle, 2: this site's Eizport version. */
			echo esc_html( sprintf( __( 'This plugin needs Eizport %1$s or newer; this site has %2$s.', 'EIZPORT_TEXT_DOMAIN' ), $requires, $version ) );
			if ( current_user_can( 'update_plugins' ) ) {
				echo $get( 'update', $main, 'eizport', 'Eizport', wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $main ) ), 'upgrade-plugin_' . $main ), __( 'Update now', 'EIZPORT_TEXT_DOMAIN' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $get.
			}
		}
		echo '</li>';

		// The WordPress Importer.
		echo '<li class="eizport-requirement">';
		if ( file_exists( WP_PLUGIN_DIR . '/' . $importer ) ) {
			echo $ok . esc_html( __( 'The WordPress Importer is installed.', 'EIZPORT_TEXT_DOMAIN' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		} else {
			echo '<span class="eizport-requirement__before">' . $no . esc_html( __( 'The WordPress Importer is needed.', 'EIZPORT_TEXT_DOMAIN' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo '<span class="eizport-requirement__after" hidden>' . $ok . esc_html( __( 'The WordPress Importer is installed.', 'EIZPORT_TEXT_DOMAIN' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			if ( current_user_can( 'install_plugins' ) ) {
				echo $get( 'install', $importer, 'wordpress-importer', __( 'WordPress Importer', 'EIZPORT_TEXT_DOMAIN' ), wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=wordpress-importer' ), 'install-plugin_wordpress-importer' ), __( 'Install Now', 'EIZPORT_TEXT_DOMAIN' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $get.
			}
		}
		echo '</li>';

		// The bundle: what Eizport's screen says, without what only Eizport reads (the version that made it).
		echo '<li>' . $ok; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		/* translators: 1: file name, 2: size, 3: date. */
		echo esc_html( sprintf( __( 'The bundle: %1$s, %2$s, made %3$s.', 'EIZPORT_TEXT_DOMAIN' ), (string) preg_replace( '/-[a-z0-9]{16}(?=[.\/]|$)/', '', wp_basename( $bundle ) . ( is_dir( $bundle ) ? '/' : '' ) ), size_format( is_file( $bundle ) ? (int) filesize( $bundle ) : 0 ), wp_date( get_option( 'date_format' ), (int) filemtime( $bundle ) ) ) );
		echo '</li></ul>';
		$update = 'yes' === strtolower( trim( (string) get_file_data( $file, array( 'update' => 'Eizport Update' ) )['update'] ) );
		echo '<p><label><input type="checkbox" disabled' . ( $update ? ' checked' : '' ) . ' /> ' . esc_html( __( 'Update the items that already exist (same type and slug) instead of adding copies', 'EIZPORT_TEXT_DOMAIN' ) ) . '</label></p>';
		echo '</div></div></div>';
	};

	// Which of the plugins it needs were already there: the ones installed for it can go with it.
	register_activation_hook(
		$file,
		static function () use ( $slug, $main ) {
			$before          = (array) get_option( 'eizport_bundle_plugins_before', array() );
			$before[ $slug ] = array(
				$main => file_exists( WP_PLUGIN_DIR . '/' . $main ),
				'wordpress-importer/wordpress-importer.php' => file_exists( WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php' ),
			);
			update_option( 'eizport_bundle_plugins_before', $before, false );
		}
	);
	// Its screen, out of the menu: a one-off import has no place there. Reached from its row on Plugins (Run import) and the notice below.
	add_action(
		'admin_menu',
		static function () use ( $page, $title, $screen ) {
			add_management_page( $title, $title, 'import', $page, $screen );
			remove_submenu_page( 'tools.php', $page );
		}
	);
	// Tools → Import lists it too, set apart from the others: its entry opens its screen.
	add_action(
		'admin_init',
		static function () use ( $page, $title ) {
			if ( function_exists( 'register_importer' ) ) {
				register_importer( $page, $title, __( 'The content this plugin holds, exported with Eizport from another site.', 'EIZPORT_TEXT_DOMAIN' ), '__return_null' );
			}
		}
	);
	add_action(
		'load-importer-' . $page,
		static function () use ( $page ) {
			wp_safe_redirect( admin_url( 'tools.php?page=' . $page ) );
			exit;
		}
	);
	add_action(
		'admin_enqueue_scripts',
		static function ( $hook ) use ( $page ) {
			if ( 'import.php' !== $hook ) {
				return;
			}
			$row = '.importers tr:has(a[href*="import=' . sanitize_key( $page ) . '"])';
			wp_register_style( 'eizport-bundle-' . $page, false, array(), '1.0.0' );
			wp_enqueue_style( 'eizport-bundle-' . $page );
			wp_add_inline_style( 'eizport-bundle-' . $page, $row . ' td{background:#f0f6fc}' . $row . ' td:first-child{box-shadow:inset 4px 0 #3858e9}' . $row . ' .importer-title{font-weight:600}' );
		}
	);
	// Out of the menu, WordPress has no title for it: the plugin's name, in the window's title too.
	add_action(
		'load-tools_page_' . $page,
		static function () use ( $title ) {
			$GLOBALS['title'] = $title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin page title core reads.
		}
	);
	// On it, Tools → Import is the menu item shown as current.
	add_filter(
		'submenu_file',
		static function ( $submenu_file ) use ( $page ) {
			return isset( $_GET['page'] ) && $page === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ? 'import.php' : $submenu_file; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		}
	);
	// While Eizport is missing, the screen's look (a copy of Eizport's, made with it) and the script that gets Eizport and the WordPress Importer here, with core's updates script.
	add_action(
		'admin_enqueue_scripts',
		static function ( $hook ) use ( $file, $page, $missing ) {
			if ( 'tools_page_' . $page !== $hook || '' === $missing() ) {
				return;
			}
			$css = dirname( $file ) . '/screen.css';
			if ( is_file( $css ) ) {
				wp_enqueue_style( 'eizport-bundle-screen', plugins_url( 'screen.css', $file ), array(), (string) filemtime( $css ) );
			}
			add_filter(
				'admin_body_class',
				static function ( $classes ) {
					return false === strpos( $classes, 'eizport-import' ) ? $classes . ' eizport-import' : $classes;
				}
			);
			if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'update_plugins' ) ) {
				return;
			}
			wp_enqueue_script( 'updates' );
			$texts = array(
				'install'  => __( 'Installing…', 'EIZPORT_TEXT_DOMAIN' ),
				'activate' => __( 'Activating…', 'EIZPORT_TEXT_DOMAIN' ),
				'update'   => __( 'Updating…', 'EIZPORT_TEXT_DOMAIN' ),
				/* translators: %s: error message. */
				'failed'   => __( 'It didn’t work: %s', 'EIZPORT_TEXT_DOMAIN' ),
			);
			wp_add_inline_script(
				'updates',
				'( function ( texts ) {
					document.addEventListener( "DOMContentLoaded", function () {
						document.querySelectorAll( ".eizport-bundle-get" ).forEach( function ( button ) {
							var label = button.textContent, data = button.dataset, row = button.closest( ".eizport-requirement" );
							var step = function ( text ) {
								button.classList.add( "updating-message", "button-disabled" );
								button.setAttribute( "aria-disabled", "true" );
								button.textContent = text;
								wp.a11y.speak( text );
							};
							var fail = function ( response ) {
								button.classList.remove( "updating-message", "button-disabled" );
								button.removeAttribute( "aria-disabled" );
								button.textContent = label;
								var message = response && ( response.errorMessage || response.message );
								if ( message ) {
									wp.updates.addAdminNotice( { id: "eizport-bundle-error", className: "notice-error is-dismissible", message: texts.failed.replace( "%s", message ) } );
									wp.a11y.speak( message, "assertive" );
								}
							};
							// Eizport ready: its own screen takes over, the same frame with what it reads. The importer: its line turns.
							var done = function () {
								if ( "eizport" === data.slug ) {
									// After core lets go of its request: while it holds one, leaving the page asks to confirm.
									( function reload() {
										return wp.updates.ajaxLocked ? setTimeout( reload, 50 ) : window.location.reload();
									} )();
									return;
								}
								// Removed, not hidden: the display of .button wins over [hidden].
								button.remove();
								row.querySelector( ".eizport-requirement__before" ).hidden = true;
								row.querySelector( ".eizport-requirement__after" ).hidden = false;
								wp.a11y.speak( row.querySelector( ".eizport-requirement__after" ).textContent );
							};
							var activate = function () {
								step( texts.activate );
								// While a request runs, core queues the next one, and its queue drops activations.
								if ( wp.updates.ajaxLocked ) {
									setTimeout( activate, 50 );
									return;
								}
								wp.updates.activatePlugin( { name: data.name, slug: data.slug, plugin: data.plugin, success: done, error: fail } );
							};
							button.addEventListener( "click", function ( event ) {
								event.preventDefault();
								if ( button.classList.contains( "updating-message" ) ) {
									return;
								}
								if ( "activate" === data.mode ) {
									activate();
								} else if ( "update" === data.mode ) {
									step( texts.update );
									// An active plugin stays active through an update; an inactive one is activated after.
									wp.updates.updatePlugin( { plugin: data.plugin, slug: data.slug, success: function ( response ) {
										return response && response.activePlugin ? done() : activate();
									}, error: fail } );
								} else {
									step( texts.install );
									wp.updates.installPlugin( { slug: data.slug, success: activate, error: fail } );
								}
							} );
						} );
					} );
				} )( ' . wp_json_encode( $texts ) . ' );'
			);
			add_action(
				'admin_footer',
				static function () {
					wp_print_admin_notice_templates();
					wp_print_request_filesystem_credentials_modal();
				}
			);
		}
	);
	add_filter(
		'plugin_action_links_' . plugin_basename( $file ),
		static function ( $links ) use ( $page ) {
			if ( current_user_can( 'import' ) ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . $page ) ) . '">' . esc_html( __( 'Run import', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a>' );
			}
			return $links;
		}
	);
	// Until it is imported, a notice points to the screen (no redirect on activation).
	add_action(
		'admin_notices',
		static function () use ( $slug, $page, $title ) {
			$imported = (array) get_option( 'eizport_bundle_plugins_imported', array() );
			// Not on its screen, nor on Tools → Import, where its entry already stands out.
			if ( ! current_user_can( 'import' ) || isset( $imported[ $slug ] ) || 'import.php' === ( $GLOBALS['pagenow'] ?? '' ) || ( isset( $_GET['page'] ) && $page === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
				return;
			}
			echo '<div class="notice notice-info"><p>' . esc_html( $title ) . ': <a href="' . esc_url( admin_url( 'tools.php?page=' . $page ) ) . '">' . esc_html( __( 'open the import screen', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a></p></div>';
		}
	);
} )( __FILE__ );
