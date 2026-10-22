<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress site this plugin is installed in (set WP_LOAD to use
 * another wp-load.php). The tests only touch temporary files and filters;
 * they don't write to the database.
 *
 * @package Eizport
 */

// PHPUnit polyfills (yoast/phpunit-polyfills) for the snake_case fixtures.
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$eizport_wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : dirname( __DIR__, 5 ) . '/wp-load.php';
if ( ! is_file( $eizport_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at $eizport_wp_load; set WP_LOAD.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// On a network, WP_TEST_URL picks the site the tests run in (http://host/b/ for a subsite).
if ( getenv( 'WP_TEST_URL' ) ) {
	$eizport_url            = (string) parse_url( (string) getenv( 'WP_TEST_URL' ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress isn't loaded yet.
	$_SERVER['HTTP_HOST']   = $eizport_url . ( parse_url( (string) getenv( 'WP_TEST_URL' ), PHP_URL_PORT ) ? ':' . parse_url( (string) getenv( 'WP_TEST_URL' ), PHP_URL_PORT ) : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$_SERVER['REQUEST_URI'] = (string) parse_url( (string) getenv( 'WP_TEST_URL' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$_SERVER['SERVER_NAME'] = $eizport_url;
}

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core constant.
require_once $eizport_wp_load;

// The plugin may not be active on that site.
if ( ! defined( 'Eizport\VERSION' ) ) {
	require_once dirname( __DIR__, 2 ) . '/eizport.php';
}

require_once __DIR__ . '/class-test-case.php';
