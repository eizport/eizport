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
 * bundle exported from another WordPress site and adds a screen under
 * Tools that imports it. Eizport draws that screen and runs the
 * import; without it, the screen says what to install. Once the content is
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

	// Eizport's import screen, or, without Eizport, how to get it: from the plugin directory, like an importer.
	$screen = static function () use ( $file, $title, $main, $page ) {
		if ( function_exists( '\Eizport\BundlePlugin\render' ) ) {
			\Eizport\BundlePlugin\render( $file );
			return;
		}
		// Without Eizport active: what to do, in plain words, in a card like WordPress's own.
		$installed = file_exists( WP_PLUGIN_DIR . '/' . $main );
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><hr class="wp-header-end">';
		echo '<div class="card" style="max-width:630px;margin-top:16px;padding:16px 24px">';
		echo '<h2 class="title">' . esc_html( $installed ? __( 'Activate Eizport to import', 'EIZPORT_TEXT_DOMAIN' ) : __( 'Eizport is needed to import', 'EIZPORT_TEXT_DOMAIN' ) ) . '</h2>';
		echo '<p>' . esc_html( __( 'This plugin holds content exported with Eizport. Eizport imports it, and then this plugin can go.', 'EIZPORT_TEXT_DOMAIN' ) ) . '</p>';
		if ( $installed && current_user_can( 'activate_plugins' ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $main ) ), 'activate-plugin_' . $main ) ) . '">' . esc_html( __( 'Activate Eizport', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a></p>';
		} elseif ( current_user_can( 'install_plugins' ) ) {
			// From the plugin directory, installed and activated here (the script below); without JavaScript, core's install screen.
			echo '<p class="eizport-requirement"><span class="eizport-requirement__before"><a class="button eizport-install-now" href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=eizport' ), 'install-plugin_eizport' ) ) . '" data-slug="eizport" data-plugin="' . esc_attr( $main ) . '" data-name="Eizport">' . esc_html( __( 'Install Now', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a></span>';
			echo '<span class="eizport-requirement__after" hidden><span style="color:#00a32a;font-weight:600" aria-hidden="true">✓</span> ' . esc_html( __( 'Eizport is active.', 'EIZPORT_TEXT_DOMAIN' ) ) . ' <a class="button button-primary" style="margin-left:8px" href="' . esc_url( admin_url( 'tools.php?page=' . $page ) ) . '">' . esc_html( __( 'Continue', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a></span></p>';
		}
		echo '</div></div>';
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
	add_action(
		'admin_menu',
		static function () use ( $page, $title, $screen ) {
			add_management_page( $title, $title, 'import', $page, $screen );
		}
	);
	// Install Now installs and activates Eizport on the screen, like Tools → Import installs an importer (core's updates script).
	add_action(
		'admin_enqueue_scripts',
		static function ( $hook ) use ( $page, $main ) {
			if ( 'tools_page_' . $page !== $hook || function_exists( '\Eizport\BundlePlugin\render' ) || file_exists( WP_PLUGIN_DIR . '/' . $main ) || ! current_user_can( 'install_plugins' ) ) {
				return;
			}
			wp_enqueue_script( 'updates' );
			$texts = array(
				'installing' => __( 'Installing…', 'EIZPORT_TEXT_DOMAIN' ),
				'activating' => __( 'Activating…', 'EIZPORT_TEXT_DOMAIN' ),
				/* translators: %s: error message. */
				'failed'     => __( 'Installation failed: %s', 'EIZPORT_TEXT_DOMAIN' ),
			);
			wp_add_inline_script(
				'updates',
				'( function ( texts ) {
					document.addEventListener( "DOMContentLoaded", function () {
						document.querySelectorAll( ".eizport-install-now" ).forEach( function ( button ) {
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
								if ( response && response.errorMessage ) {
									wp.updates.addAdminNotice( { id: "eizport-install-error", className: "notice-error is-dismissible", message: texts.failed.replace( "%s", response.errorMessage ) } );
									wp.a11y.speak( response.errorMessage, "assertive" );
								}
							};
							var activate = function () {
								step( texts.activating );
								// While a request runs, core queues the next one, and its queue drops activations.
								if ( wp.updates.ajaxLocked ) {
									setTimeout( activate, 50 );
									return;
								}
								wp.updates.activatePlugin( {
									name: data.name, slug: data.slug, plugin: data.plugin, error: fail,
									success: function () {
										row.querySelector( ".eizport-requirement__before" ).hidden = true;
										row.querySelector( ".eizport-requirement__after" ).hidden = false;
										// The other ways to get it go.
										document.querySelectorAll( ".eizport-requirement__manual" ).forEach( function ( el ) {
											el.hidden = true;
										} );
										wp.a11y.speak( row.querySelector( ".eizport-requirement__after" ).textContent );
									}
								} );
							};
							button.addEventListener( "click", function ( event ) {
								event.preventDefault();
								if ( button.classList.contains( "updating-message" ) ) {
									return;
								}
								step( texts.installing );
								if ( ! data.action ) {
									wp.updates.installPlugin( { slug: data.slug, success: activate, error: fail } );
									return;
								}
								// Another source: an admin-ajax action that answers like core\'s install-plugin.
								jQuery.post( window.ajaxurl, { action: data.action, _ajax_nonce: data.nonce } )
									.done( function ( response ) {
										return response && response.success ? activate() : fail( response && response.data );
									} )
									.fail( function ( xhr ) {
										fail( { errorMessage: xhr.statusText || String( xhr.status ) } );
									} );
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
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . $page ) ) . '">' . esc_html( __( 'Import', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a>' );
			}
			return $links;
		}
	);
	// Until it is imported, a notice points to the screen (no redirect on activation).
	add_action(
		'admin_notices',
		static function () use ( $slug, $page, $title ) {
			$imported = (array) get_option( 'eizport_bundle_plugins_imported', array() );
			if ( ! current_user_can( 'import' ) || isset( $imported[ $slug ] ) || ( isset( $_GET['page'] ) && $page === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
				return;
			}
			echo '<div class="notice notice-info"><p>' . esc_html( $title ) . ': <a href="' . esc_url( admin_url( 'tools.php?page=' . $page ) ) . '">' . esc_html( __( 'open the import screen', 'EIZPORT_TEXT_DOMAIN' ) ) . '</a></p></div>';
		}
	);
} )( __FILE__ );
