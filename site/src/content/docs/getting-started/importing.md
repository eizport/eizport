---
title: "Importing"
description: "How a bundle is imported with the WordPress Importer, what happens to media, content files and options, and the other ways to import."
---

## With the WordPress Importer

Upload a `.wxrz` or `.zip` bundle on **Tools → Import → WordPress**, like any WXR file. Gzipped WXR files (`.xml.gz`) and plain `.xml` files work too.

Exported [as plugin](../../exporting/as-plugin/)? Install the `.zip` in **Plugins → Add Plugin → Upload Plugin** and use its **Run import** under Tools instead.

**Tools → Import** opens the WordPress Importer directly, and its screen links to **All importers** ([Settings → General](../../settings/general/) turns this off).

The importer looks like the plugin's Export screen: a header with the step's main button (*Upload file and import*, *Submit*), each step in cards, and a **Choose file** button in the admin's language, on the line under its label; *Upload file and import* waits until a file is chosen. **Style Tools → Import → WordPress** in the plugin's [Settings → General](../../settings/general/) turns that off; bundles are accepted either way.

## Updating items that already exist

The WordPress Importer skips an item that already exists, and adds a copy when only the slug matches: a theme's *Home* page becomes `home-2`, and a second delivery of the same bundle changes nothing. **Update pages, posts and products that already exist (same slug) instead of adding copies**, on the importer's step 1 (and on the screen of a plugin exported [as plugin](../../exporting/as-plugin/)), changes that:

- an item of the same type and slug is **the** item: it gets the bundle's title, content, excerpt, order, custom fields and terms, and keeps its ID;
- settings that point to it (the homepage, the shop page…) point to it, so the theme's *Home* becomes the homepage with the bundle's content;
- attachments that already exist are reused, and their URLs in the updated content point to this site.

It's off unless the bundle asks for it: **Update existing**, in the Export screen's **Details** panel, saves the choice in the bundle, and the box comes checked. The person importing can still uncheck it.

## Behind the scenes

Before the importer reads anything:

1. **The bundle is unpacked** into a private folder under `wp-content/uploads/eizport/tmp/<random>/`. Only the files its manifest lists are written, only under that folder, and only with file types WordPress allows to be uploaded (never PHP).
2. **The WXR replaces the upload**, so the importer's own checks and steps run as usual.
3. **Content files go back in:** each `post_content/<slug>.html` is put back into its item, by original ID, else by slug.
4. **Media is served from the bundle:** when the importer downloads an attachment, a `pre_http_request` filter answers with the file from the folder instead of the network.
5. **Options are applied at the end** (`import_end`), with every post ID mapped to the imported post. Options pointing to items that weren't imported are left out.
6. **Term images are mapped at the end:** term meta that hold an attachment ID (a product category's thumbnail), listed in the manifest's `term_media`, point to the imported attachment.

The folder is deleted when the import ends or is abandoned.

## From a copy on the site

Copies made by the Export screen are listed in the plugin's **Settings → Copies** (from its row in Plugins). Each one has an **Import** action, which imports it into the same site — handy to restore content or to test a bundle.

## With code

- The [`eizport/import`](../../developers/abilities/#eizportimport) ability imports a `copy` or an `attachment_id` (a `.wxrz`, `.zip` or `.xml` in the media library).
- [`wp eizport import <file>`](../../developers/wp-cli/) does it from the command line — also from a bundle folder written by `wp eizport export --dir`, and with `--update-existing`.

## What is mapped

| In the file | On import |
| --- | --- |
| Attachment URLs | Files come from the bundle; the importer creates the attachments and image sizes as usual. An attachment the site already has is reused, with or without *Update existing*: its URLs (and its image sizes') in the imported content point to the file here |
| `post_content/<slug>.html` | Put back into the item's content |
| `options.json` | Set at the end; options listed in `post_ids` get the imported post's ID |
| `term_media` in the manifest | Term meta holding an attachment ID get the imported attachment's ID |
| Post IDs in blocks | Image, Gallery, Cover, Media & Text, File, Video and Audio blocks (`"id"`, `"ids"`, `"mediaId"`, `wp-image-N`, gallery `data-id`), `[gallery ids]` and navigation links to pages and posts point to the imported items, in new and updated items and in block widgets. Plugins add their blocks with `eizport_block_id_attributes` |
| The look ([appearance](../../exporting/appearance/)) | CSS rules added to this site's CSS as the bundle asks (end or start; head/tail comments), or — chosen on import — replacing a head/tail block of the same name or all the CSS — step 1 of the importer, `css_mode`/`css_markers` and `--css-mode`/`--[no-]css-markers` change it; theme mods, style groups and widgets join this site's |
| Terms | The items' categories, tags and other terms come in full (description, parent, term meta) and are created parents first; a term the site already has (same slug) is used as it is |
| Links to the source site | The source site's address (the WXR's `base_site_url`) becomes this site's in the imported items' content and excerpt (also escaped in block attributes, http or https), in the menus' custom links, and in widgets and theme mods |
| Products and their variations, pages and their children | Imported with their parents, as the WordPress Importer does |
