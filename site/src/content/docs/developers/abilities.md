---
title: "Abilities"
description: "Every eizport/* ability of the WordPress Abilities API — input, output, permission — over REST, PHP and MCP."
---

Everything the screens do is an **ability** of the [Abilities API](https://developer.wordpress.org/apis/abilities/) (WordPress 6.9+), in the category `eizport`. The Export and Settings screens call them too, so code, AI agents and the UI share one path.

## Calling them

**REST** (`/wp-json/wp-abilities/v1/abilities/<name>/run`, authenticated: cookie + nonce, or an application password):

```bash
curl -u admin:APP_PASSWORD -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/wp-abilities/v1/abilities/eizport/export/run \
  -d '{"input":{"types":{"page":{"ids":[43]}},"options":{"groups":["basics"]}}}'
```

Read-only abilities run with `GET` and the input in the query string.

**PHP**:

```php
$copy = wp_get_ability( 'eizport/export' )->execute(
	array(
		'types' => array( 'product' => array( 'filters' => array( 'status' => 'publish' ) ) ),
		'zip'   => true,
	)
);
if ( ! is_wp_error( $copy ) ) {
	echo $copy['download_url'];
}
```

**MCP**: with the [MCP adapter](https://github.com/WordPress/mcp-adapter), each ability is a tool an AI agent can call.

> One export runs per request: `export_wp()` defines its helpers inside itself. A second `eizport/export` in the same request returns `eizport_export_unavailable`.

## Overview

| Ability | Does | Permission | Annotations |
| --- | --- | --- | --- |
| `eizport/get-export-options` | What can be exported | `export` | readonly |
| `eizport/get-items` | A page of one type's items | `export` | readonly |
| `eizport/get-options` | The site's options, one by one | `export` | readonly |
| `eizport/get-suggestions` | What the items chosen suggest | `export` | readonly |
| `eizport/get-reviews` | A page of product reviews | `export` | readonly |
| `eizport/export` | Exports to a copy | `export` | — |
| `eizport/get-copies` | The copies kept | `export` | readonly |
| `eizport/delete-copies` | Deletes copies | `export` | destructive, idempotent |
| `eizport/import` | Imports a copy or a media file | `import` | — |
| `eizport/get-export-defaults` | The saved defaults | `export` | readonly |
| `eizport/save-export-defaults` | Saves defaults | `export` (site's: `manage_options`) | idempotent |
| `eizport/clear-export-defaults` | Forgets them | as above | destructive |
| `eizport/get-settings` | The plugin's settings | `manage_options` | readonly |
| `eizport/update-settings` | Changes settings | `manage_options` | idempotent |

## eizport/get-export-options

No input. Returns `types` (each with `type`, `label`, `count`, `hierarchical`, `statuses` and `taxonomies` with their terms), `option_groups` (`group`, `label`, `options`), `front_pages` (`front`, `posts` IDs), `themes`, `plugins`, `copy_lifetime` (seconds), `filename` (today's default name), `linked` (the linked settings mode), `bundle_zip`, `review_count` (approved product reviews; 0 without WooCommerce) `appearance` (`theme_mods`, `custom_css`, `global_styles`, `global_css`, `widgets` → how many the site has, 0 for none; see [Appearance](../../exporting/appearance/)) and `menus`: `classic` (`id`, `name`, `slug`, `items`, `locations` — the labels of the theme locations it sits in) and `navigation` (`id`, `title`, `links`) — see [Menus](../../exporting/menus/).

## eizport/get-items

| Input | Type | |
| --- | --- | --- |
| `type` | string, required | A post type from `get-export-options` |
| `search` | string | |
| `page`, `per_page` | integer | `per_page` up to 100 |
| `orderby` | `date` \| `title` \| `menu_order` | |
| `order` | `asc` \| `desc` | |
| `filters` | object | `author`, `start_date`/`end_date` (`YYYY-MM`), `status`, `terms` (term IDs) |
| `include` | integer[] | Only these items (none: an empty page) |

Returns `items` — `id`, `title`, `status`, `date`, `parent`, `author`, `file` (attachments), `roles` (`option`, `label`, `with`) — plus `total` and `total_pages`.

## eizport/get-options

Input: `search`, `group` (a key of `option_groups`), `names` (only these), `page`, `per_page`. Returns `items` (`name`, `size`, `value` — in a line, arrays as JSON, up to 200 characters — `autoload`, `post_id`), `total`, `total_pages`. Options that are never exported and transients are left out.

## eizport/get-suggestions

Input: `types` (as `eizport/export` takes them), `whole` (post types chosen whole, the same as `{}` in `types`) and `menus.classic` (menus chosen). Returns `items` (post type → IDs the items' content uses, through [`eizport_suggested_items`](../hooks/#eizport_suggested_items), and the pages and posts the menus chosen link to), `reviews` (approved reviews of the products chosen), `options` (settings pointing to the items, unless *Linked settings* is *Never* or *On export*), `appearance` (the look's keys the site has) and `menus` (`classic`: the menus in theme locations; `navigation`: the navigations the items' content or the theme's templates use). Nothing is added to an export by itself.

## eizport/get-reviews

Input: `search`, `include` (only these), `page`, `per_page`. Returns `items` (`id`, `author`, `product`, `product_title`, `rating`, `excerpt`, `date`), `total`, `total_pages`; empty without WooCommerce.

## eizport/export

| Input | Type | Default |
| --- | --- | --- |
| `types` | object: post type → `{ "ids": [...] }` or `{ "filters": {...} }` | all content |
| `media` | boolean: media files inside | `true` |
| `media_used` | boolean: media used in content | `true` unless *Only attached media* |
| `post_content` | boolean: `post_content/*.html` | *Content as .html files* |
| `bundle` | boolean: `false` for WordPress's plain `.xml` | `true` |
| `zip` | boolean: `.zip` instead of `.wxrz` | *Bundle as .zip* |
| `options` | object: `groups`, `names`, `all`, `front_pages`, `linked` | none |
| `blueprint` | object: `themes`, `plugins` — makes a Playground `.zip` | — |
| `as_plugin` | boolean: a plugin `.zip` that imports its bundle ([as plugin](../../exporting/as-plugin/)); not with `blueprint` | `false` |
| `update_existing` | boolean: ask the import to update the items that already exist (same type and slug); saved in the bundle | `false` |
| `reviews` | object: `ids` (comment IDs; `[]` for none) or `all` — WooCommerce product reviews | all of the products' reviews |
| `appearance` | string[]: `theme_mods`, `custom_css`, `global_styles`, `global_css`, `widgets` — the site's [look](../../exporting/appearance/), with the media it uses; needs `bundle` | none |
| `menus` | object: `classic` (menu term IDs, with their items and theme locations), `navigation` (`wp_navigation` IDs), or `all` — [menus](../../exporting/menus/) | none (*all content* has them) |
| `filename` | string, without extension | `<site>.WordPress.<date>` |

Returns the copy:

```json
{
  "name": "site-w9bjZ9pyEWxC8VMf.zip",
  "format": "playground",
  "size": 8178533,
  "created": "2026-10-10T22:11:58+00:00",
  "expires": "2026-10-11T22:11:58+00:00",
  "download_url": "https://example.com/?eizport_playground=site-w9bjZ9pyEWxC8VMf.zip",
  "playground_url": "https://playground.wordpress.net/?blueprint-url=…",
  "command": "npx @wp-playground/cli@latest server --blueprint=./site-w9bjZ9pyEWxC8VMf.zip"
}
```

`format` is `wxrz`, `zip`, `wxr`, `playground` or `plugin`; `expires` is `""` for copies kept until deleted. Errors: `eizport_invalid_type`, `eizport_invalid_options` (an option that is never exported), `eizport_invalid_blueprint` (a theme or plugin not installed), `eizport_export_unavailable`.

## eizport/get-copies and eizport/delete-copies

`get-copies` returns the list of copies, as above. `delete-copies` takes `names` (as `get-copies` returns them) or `all: true`, and returns `{ "deleted": 3 }`.

## eizport/import

Input: `copy` (a copy's name) or `attachment_id` (a `.wxrz`, `.zip`, `.xml` or `.xml.gz` in the media library). `update_existing` (boolean) updates the items that already exist (same type and slug) instead of adding copies; default: what the bundle asks. Needs the WordPress Importer installed. A plugin made [as plugin](../../exporting/as-plugin/) is refused (`eizport_plugin_zip`): install it as a plugin. Returns `posts` (how many the importer created or found), `options` (the names set) and `log` (the importer's messages, as text).

## Defaults

- `eizport/get-export-defaults` — no input; returns `{ "types": …, "settings": … }` or `{}`.
- `eizport/save-export-defaults` — input `types` and `settings` (the Export screen's state: `format`, `filename`, `media`, `option_names`, `option_groups`, `option_all`, `include_suggested`, `review_ids`, `review_all`, `as_plugin`, `update_existing`, `themes`, `plugins`); returns what was saved, sanitized.
- `eizport/clear-export-defaults` — no input.

## Settings

`eizport/get-settings` returns `copy_lifetime`, `replace_export`, `classic_ui`, `style_import`, `content_files`, `attached_only`, `bundle_zip`, `defaults_scope`, `linked_options`, and the choices of each (`lifetime_choices`, `scope_choices`, `linked_choices`). `eizport/update-settings` takes any of those (values from the choices) and returns them all; unknown keys are refused.
