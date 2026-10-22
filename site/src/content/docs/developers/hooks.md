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

## eizport_is_secret

Whether a name — an option, a theme mod, a widget field — holds a secret, so it's never exported nor listed. By default: names with `secret`, `password`, `token`, `api_key`, `license`, `private`, `credential`, `access_key`, `auth_key` or `webhook`, and plugins' known stores of them (WP Mail SMTP, payment gateways). Mark your own, or let one through:

```php
add_filter( 'eizport_is_secret', function ( bool $secret, string $name ) {
	return 'my_plugin_connection' === $name ? true : $secret;
}, 10, 2 );
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

## eizport_content_types

The post types the Export screen offers as rows: post type => value of core's *content* radio. A plugin takes out its own types that travel another way (the Advanced Custom Fields compatibility removes ACF's field groups, post types and taxonomies).

## eizport_look_items

The items of a row next to the look's (`$items`, `$row`): a plugin fills its own row, each item with `key`, `label`, `details` and `preview` (`type` and `value`). Used by the Advanced Custom Fields compatibility for the `advanced-custom-fields` row.

## eizport_look_data

The options file's data for such a row (`$out`, `$row`, `$keys`: the item keys chosen): the row's entry goes in `$out`.

## eizport_look_suggested

The keys such a row suggests for the posts chosen (`$keys`, `$row`, `$chosen`: post type => IDs).

## eizport_look_media

The attachments the look chosen uses (`$ids`, `$choice`): a plugin's row adds the ones its items use (the Elementor row: images of templates and site settings).

## eizport_content_file

The file a post's content goes to with *Content as .html files* on (`$file`, `$post`): `null` for `post_content/<slug>.html`, or `array( 'extension' => 'json', 'data' => … )` for a page builder's design in its own format — the post's content then stays in the WXR, and the importer keeps the `.json` for whoever applies it when the import ends (`$GLOBALS['eizport_content_data']`, by the item's original ID). The Elementor compatibility uses it.

## eizport_advanced_custom_fields_id_types

The ACF field types whose values are IDs an import maps: type => `post`, `term` or `user`. Add a plugin's own field type:

```php
add_filter( 'eizport_advanced_custom_fields_id_types', function ( array $types ) {
	$types['my_product_picker'] = 'post';
	return $types;
} );
```

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

## eizport_playground_mu_plugins

The must-use plugins a Playground `.zip` can take — without the platform's (WordPress Studio, hosts) and Eizport's own. Take a host's loader out, or put back one left out.

| Param | |
| --- | --- |
| `array $choices` | File => plugin data, as `get_mu_plugins()` gives them |

## eizport_selection_limit

How many items each *By selection* list of the classic screen shows (the latest). Default `1000`.

## eizport_copy_lifetime

How long a copy lasts, in seconds; `0` keeps copies until deleted. Default: the one chosen in Settings.
