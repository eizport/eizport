---
title: "Compatibilities"
description: "Which plugins are supported (WooCommerce, Advanced Custom Fields) and which aren't officially yet (Elementor), how support is built from the public hooks, and how a plugin ships its own."
---

Support for another plugin is a **compatibility**: a file that uses only the public [hooks](../hooks/). The plugin ships two, for WooCommerce and Advanced Custom Fields; any plugin can add its own — or replace the bundled one.

## At a glance

| Plugin | Status | What to expect |
| --- | --- | --- |
| **WooCommerce** | Supported | Products, variations, orders and coupons as content types; shop, cart, checkout, my account and terms pages as linked settings; product galleries and category images; products shown by blocks suggested; product reviews in their own row; the product lookup table refreshed after an import |
| **Advanced Custom Fields (ACF)** | Supported (6.1 or later, free) | Its post types, taxonomies and field groups in its own export format, on their own row, imported before the content; fields that store IDs remapped; field groups and linked posts suggested; image and file fields as media used |
| **Elementor** | Not officially supported yet, not tested | See below |

Not tested means just that: nothing was checked against Elementor. What follows comes from how it stores its data, and from what the WordPress Importer does with it.

### Advanced Custom Fields

Supported, with its own row: see [Advanced Custom Fields](../../exporting/advanced-custom-fields/). In short:

- **Post types, taxonomies and field groups** go in ACF's own export format and are imported before the content, updating the ones with the same key.
- **Field values** travel in the posts' and terms' custom fields; the ones that hold IDs (image, file, post object, relationship, page link, taxonomy, user) are **remapped** to the items on the other site.
- **ACF PRO** (repeater, flexible content, gallery, options pages, blocks) isn't covered yet; values on users don't travel (a WXR has no users).

### Elementor

- **Designs** live in each page's custom fields (`_elementor_data`, a JSON holding the widgets, with image IDs and URLs), so they come along with the pages.
- **IDs and URLs inside that JSON** aren't remapped by the WordPress Importer: images can keep pointing to the old site's IDs and addresses.
- **Global styles and templates** (the site kit, saved templates) are Elementor's own content; Elementor's *Import / Export Kit* tool is made for them.
- **Generated CSS** files aren't exported; Elementor builds them again (*Elementor → Tools → Regenerate CSS & Data*).

Using one of these and want it supported? [Open an issue](https://github.com/eizport/eizport/issues) with what you export — or write a compatibility, below.

## The WooCommerce compatibility

`includes/compat/woocommerce.php`, registered on `eizport_register_compat` when `WooCommerce` exists:

| Hook | Adds |
| --- | --- |
| `eizport_linked_options` | Shop, Cart, Checkout, My account and Terms and conditions pages, as roles |
| `eizport_option_groups` | The *WooCommerce pages* group |
| `eizport_denied_options` | `woocommerce_version`, `woocommerce_db_version` |
| `eizport_media_used` | Product and variation galleries (`_product_image_gallery`), and the images of the products' categories |
| `eizport_term_media` | Product categories' `thumbnail_id`, mapped to the imported image |
| `import_end` | Saves each imported product the way WooCommerce does and refreshes its row in `wc_product_meta_lookup` (prices, SKU, stock: what the shop's filters and sorting read) |
| `eizport_suggested_items` | Products that blocks and `[products ids="…"]` show in the items chosen |
| `export_wp` + `query` | Product reviews: only the ones chosen, when the export says which (`reviews`) |

## The Advanced Custom Fields compatibility

`includes/compat/advanced-custom-fields.php`, registered on `eizport_register_compat` when ACF 6.1 or later is active, and `includes/import-advanced-custom-fields.php` for the import:

| Hook | Adds |
| --- | --- |
| `eizport_content_types` | Leaves ACF's own post types out of the content rows |
| `eizport_look_items`, `eizport_look_data`, `eizport_look_suggested` | The *Advanced Custom Fields (ACF)* row: its items, their export (ACF's format) and what the posts chosen suggest |
| `eizport_media_used` | The attachments of image and file fields |
| `eizport_suggested_items` | The posts of post object, relationship and page link fields |
| `import_start` | Imports the post types, taxonomies and field groups (`acf_import_internal_post_type()`), and registers them |
| `import_end` | Maps the IDs in the values and in field groups' location rules |
| `wp_import_existing_post` | ACF's own posts in a WXR exist by key, not by title and date |

## Writing one

```php
add_action( 'eizport_register_compat', function () {
	if ( ! class_exists( 'My_Plugin' ) ) {
		return;
	}
	add_filter( 'eizport_linked_options', 'my_plugin_eizport_linked' );
	add_filter( 'eizport_option_groups', 'my_plugin_eizport_groups' );
	add_filter( 'eizport_denied_options', 'my_plugin_eizport_denied' );
} );
```

`eizport_register_compat` fires on `plugins_loaded` at priority 20, after every plugin has loaded, so `class_exists()` checks are reliable.

## Replacing the bundled one

A plugin that ships its own support removes the bundled one first:

```php
remove_action( 'eizport_register_compat', 'Eizport\Compat\WooCommerce\register' );
```

## Testing

Test the compatibility's functions directly, so tests pass without the other plugin installed, and check that it registers only when that plugin is active.
