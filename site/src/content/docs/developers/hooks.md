---
title: "Hooks"
description: "Every filter and action of Eizport, with its parameters and an example."
---

All hooks are prefixed `eizport_`. Add them from a plugin or a theme's `functions.php`.

## eizport_linked_options

Options that point to a post, with the role they give it. They get the imported post's ID on import, show as a badge on the item, and can come with it ([linked settings](../../exporting/options/#linked-settings)).

```php
add_filter( 'eizport_linked_options', function ( array $linked ) {
	$linked['my_plugin_landing_page'] = array(
		'label' => __( 'Landing page', 'my-plugin' ),     // The role, shown as a badge.
		'with'  => array( 'my_plugin_landing_enabled' ),  // Options that go with it.
		'when'  => array( 'my_plugin_mode' => 'landing' ), // Only while these hold these values.
	);
	return $linked;
} );
```

| Param | |
| --- | --- |
| `array $linked` | Option name → `label` (string), `with` (string[], optional), `when` (option → value, optional) |

## eizport_option_groups

The groups of options offered on the screens and in `options.groups`.

```php
add_filter( 'eizport_option_groups', function ( array $groups ) {
	$groups['my-plugin'] = array(
		'label'   => __( 'My Plugin: layout and colors', 'my-plugin' ), // "Name: what it holds".
		'options' => array( 'my_plugin_layout', 'my_plugin_colors' ),
	);
	return $groups;
} );
```

## eizport_denied_options

More options never exported — keys, tokens, licenses, version numbers. Returns names to **add**; WordPress's own denied options can't be removed.

```php
add_filter( 'eizport_denied_options', function ( array $names ) {
	return array_merge( $names, array( 'my_plugin_api_key', 'my_plugin_db_version' ) );
} );
```

## eizport_media_used

Attachments a post uses beyond what its content names, like a gallery stored in meta. Runs when some items are exported and *Only attached media* is off.

```php
add_filter( 'eizport_media_used', function ( array $used, WP_Post $post ) {
	if ( 'portfolio' === $post->post_type ) {
		$used = array_merge( $used, wp_parse_id_list( get_post_meta( $post->ID, 'gallery', true ) ) );
	}
	return $used;
}, 10, 2 );
```

## eizport_term_media

Term meta that hold an attachment ID, by taxonomy, like a category's image. The attachments go with the export (for the terms the items use), the manifest lists them (`term_media`), and the import points them to the imported attachments. The WooCommerce compatibility adds `product_cat` → `thumbnail_id`.

```php
add_filter( 'eizport_term_media', function ( array $keys ) {
	$keys['portfolio_type'][] = 'cover_id';
	return $keys;
} );
```

Pair it with [`eizport_media_used`](#eizport_media_used), which adds the attachments to the export.

## eizport_updated_from_import

Fires after an existing post was updated from an imported item, with *Update existing* on. Use it to update what a plugin keeps elsewhere.

```php
add_action( 'eizport_updated_from_import', function ( int $id, array $post ) {
	clean_post_cache( $id );
}, 10, 2 );
```

## eizport_block_id_attributes

Blocks whose attributes hold post IDs, mapped to the imported items when an import ends (core's Image, Gallery, Cover, Media & Text, File, Video, Audio and navigation links are there already). Add a plugin's block by its full name.

```php
add_filter( 'eizport_block_id_attributes', function ( array $blocks ) {
	$blocks['my-plugin/card'] = array( 'imageId', 'postIds' ); // A number or an array of numbers.
	return $blocks;
} );
```

## eizport_menus_update_existing

Whether an import replaces the items of the classic menus the site already has (`true`) or leaves them as they are (`false`, the default). *Update existing* turns it on for its import.

```php
add_filter( 'eizport_menus_update_existing', '__return_true' );
```

## eizport_suggested_items

Posts an item suggests exporting too, like the products a block in its content shows. The Export screen shows them as *N suggested* in their type's row ([choosing items](../../exporting/choosing-items/#suggestions)); nothing is added by itself. The WooCommerce compatibility uses it for products.

```php
add_filter( 'eizport_suggested_items', function ( array $suggested, WP_Post $post ) {
	// A "related" block stores the post IDs it shows.
	if ( preg_match_all( '/"relatedIds":\[([\d,]+)\]/', $post->post_content, $m ) ) {
		foreach ( $m[1] as $list ) {
			$suggested = array_merge( $suggested, wp_parse_id_list( $list ) );
		}
	}
	return $suggested;
}, 10, 2 );
```

Only posts of types that can be exported are suggested.

## eizport_register_compat

Action, on `plugins_loaded` (priority 20), where compatibilities hook the filters above. See [compatibilities](../compatibilities/).

## eizport_post_id_options

Options that hold a post ID, mapped on import. By default, the keys of `eizport_linked_options`; kept for compatibility.

| Param | |
| --- | --- |
| `string[] $names` | Option names |

## eizport_post_content

The content an imported item gets from its `post_content/` file.

| Param | |
| --- | --- |
| `string\|null $content` | The file's content, or `null` when there is none |
| `array $item` | `id` (original ID), `type`, `slug` |
| `string $dir` | Folder the bundle was unpacked into |

## eizport_wxrz_max_bytes

The most a bundle may expand to when imported. Default `512 * MB_IN_BYTES`.

## eizport_playground_blueprint

The blueprint of a Playground `.zip`, before it is written.

| Param | |
| --- | --- |
| `array $blueprint` | The blueprint (`steps`, `landingPage`…) |
| `string $wxr_name` | The WXR's name inside the `.zip` |

## eizport_selection_limit

How many items each *By selection* list of the classic screen shows (the latest). Default `1000`.

## eizport_copy_lifetime

How long a copy lasts, in seconds; `0` keeps copies until deleted. Default: the one chosen in Settings.
