<?php
/**
 * Puts the demo store in another language (a translation repository's
 * demo/store.json) and prints, as JSON, what it replaced: applying that output
 * puts the store back. Run by useLocale() (demo.mjs) on the demo source site:
 *
 *   wp eval-file demo-store.php <store.json>
 *
 * The file has, all optional:
 * - options: name → value;
 * - products: SKU → name, slug, regular_price, weight, short_description, description;
 * - terms: taxonomy → current slug → name, slug, description;
 * - pages: option holding a page ID → post_title, post_name;
 * - templates, template_parts: slug → the theme's customized template or part
 *   (e.g. single-product, footer);
 * - navigation (the newest wp_navigation) and front_page (page_on_front):
 *   these take { "content": "…" } or { "file": "…" }, relative to store.json.
 * {site} in a content is the site's address.
 *
 * @package Eizport
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- A CLI script reading its own JSON file.

$store_file = $args[0] ?? '';
$store      = json_decode( (string) file_get_contents( $store_file ), true );
if ( ! is_array( $store ) ) {
	WP_CLI::error( "Not a store file: $store_file" );
}

$content = static function ( $entry ) use ( $store_file ) {
	$text = isset( $entry['file'] ) ? file_get_contents( dirname( $store_file ) . '/' . $entry['file'] ) : $entry['content'];
	return str_replace( '{site}', untrailingslashit( home_url() ), $text );
};
$before  = array();

foreach ( $store['options'] ?? array() as $name => $value ) {
	$before['options'][ $name ] = get_option( $name );
	update_option( $name, $value );
}

foreach ( $store['products'] ?? array() as $sku => $fields ) {
	$product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
	if ( ! $product ) {
		continue;
	}
	foreach ( $fields as $field => $value ) {
		$before['products'][ $sku ][ $field ] = $product->{"get_$field"}( 'edit' );
		$product->{"set_$field"}( $value );
	}
	$product->save();
}

foreach ( $store['terms'] ?? array() as $taxonomy => $terms ) {
	foreach ( $terms as $slug => $fields ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			continue;
		}
		// Keyed by the new slug: that is how the restore finds it.
		$before['terms'][ $taxonomy ][ $fields['slug'] ?? $slug ] = array_intersect_key( get_object_vars( $term ), $fields );
		wp_update_term( $term->term_id, $taxonomy, $fields );
	}
}

foreach ( $store['pages'] ?? array() as $option => $fields ) {
	$page = get_post( (int) get_option( $option ) );
	if ( ! $page ) {
		continue;
	}
	$before['pages'][ $option ] = array_intersect_key( get_post( $page, ARRAY_A ), $fields );
	wp_update_post( array( 'ID' => $page->ID ) + $fields );
}

// Block markup goes in as it is: kses would strip the inline styles.
kses_remove_filters();
$posts = array(
	'navigation' => current( get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 1, 'fields' => 'ids' ) ) ),
	'front_page' => (int) get_option( 'page_on_front' ),
);
// The theme's customized templates and template parts (saved ones only: the
// theme's files stay as they are).
foreach ( array( 'templates' => 'wp_template', 'template_parts' => 'wp_template_part' ) as $group => $type ) {
	foreach ( array_keys( $store[ $group ] ?? array() ) as $slug ) {
		$saved = get_block_template( get_stylesheet() . "//$slug", $type );
		if ( $saved && 'custom' === $saved->source && $saved->wp_id ) {
			$posts[ "$group.$slug" ] = $saved->wp_id;
			$store[ "$group.$slug" ] = $store[ $group ][ $slug ];
		}
	}
}
foreach ( $posts as $key => $post_id ) {
	if ( empty( $store[ $key ] ) || ! $post_id ) {
		continue;
	}
	$saved = array( 'content' => get_post_field( 'post_content', $post_id, 'raw' ) );
	if ( str_contains( $key, '.' ) ) {
		[ $group, $slug ]          = explode( '.', $key, 2 );
		$before[ $group ][ $slug ] = $saved;
	} else {
		$before[ $key ] = $saved;
	}
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => wp_slash( $content( $store[ $key ] ) ),
		)
	);
}

// New product and category bases: this request registered the old ones, so the
// rules are dropped and the next request builds them.
delete_option( 'rewrite_rules' );

echo wp_json_encode( $before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
