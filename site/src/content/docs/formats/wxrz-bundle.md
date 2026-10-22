---
title: "WXRZ bundle"
description: "The .wxrz format, entry by entry — manifest, WXR, media, post_content and options.json — and the safety rules when one is opened."
---

A `.wxrz` file is a **gzipped tar archive** (`ustar`). Any tool that reads `.tar.gz` opens it:

```bash
tar tzvf site.wxrz        # list
tar xzf site.wxrz -C out  # extract
```

## Entries

```text
manifest.json                      required, first
<site>.WordPress.<date>.xml        the WXR (WordPress eXtended RSS)
options.json                       optional: settings
post_content/<slug>.html           optional: one per post with content
post_content/<slug>.json           optional: one per page built with Elementor, its design
uploads/2026/10/photo.jpg          optional: media files, as in wp-content/uploads
```

## manifest.json

```json
{
  "format": "wxrz",
  "version": 1,
  "generator": "Eizport 1.0.0",
  "site": "https://example.com/",
  "created": "2026-10-10T22:11:50+00:00",
  "wxr": [ "example.WordPress.2026-10-10.xml" ],
  "files": {
    "https://example.com/wp-content/uploads/2026/10/photo.jpg": "uploads/2026/10/photo.jpg"
  },
  "options": "options.json",
  "update_existing": true,
  "term_media": {
    "product_cat": { "western": { "thumbnail_id": 13 } }
  },
  "post_content": {
    "home.html": { "id": 43, "type": "page", "slug": "home" }
  }
}
```

| Key | Meaning |
| --- | --- |
| `format`, `version` | Always `"wxrz"` and `1` for this layout; a reader refuses other versions |
| `generator`, `site`, `created` | Who made it, from where, when (informative) |
| `wxr` | The WXR entry's name (the first one is read) |
| `files` | Attachment URL in the WXR → entry in the archive. The importer's download of that URL is answered with the entry |
| `options` | The options file's entry, when there is one |
| `update_existing` | `true` when the import should update the items that already exist (same type and slug); the importer's box comes checked |
| `term_media` | Taxonomy → term slug → meta key → attachment ID on the exporting site, for the terms the items use ([`eizport_term_media`](../../developers/hooks/#eizport_term_media)); mapped to the imported attachment at the end of the import |
| `post_content` | Content file → the item it belongs to: original `id`, `type` and `slug` |

## options.json

```json
{
  "format": "eizport-options",
  "version": 1,
  "options": {
    "show_on_front": "page",
    "page_on_front": "43",
    "blogname": "Example"
  },
  "post_ids": [ "page_on_front" ]
}
```

`options` are set with `update_option()` when the import ends. Each name in `post_ids` holds a post ID: it gets the ID the importer gave that post, or is left out if the post wasn't imported.

## post_content/

Each file is the item's `post_content`, byte for byte; in the WXR, the item's `<content:encoded>` is empty. On import, the content is put back into the WXR item with the same original ID (else the same type and slug) before the importer reads it. The [`eizport_post_content`](../../developers/hooks/#eizport_post_content) filter can change it.

## Opening a bundle safely

When a bundle is imported:

- only entries the manifest lists, plus `post_content/` files and `options.json`, are written;
- every media entry must be under `uploads/`, with no `..`, backslash, null byte or `//`, and a file type WordPress allows to be uploaded (`wp_check_filetype()`): never PHP;
- everything goes to a new private folder in `wp-content/uploads/eizport/tmp/`, deleted after the import;
- the archive may expand to **512 MB** at most (filter [`eizport_wxrz_max_bytes`](../../developers/hooks/#eizport_wxrz_max_bytes)), read in steps, against decompression bombs;
- entry names longer than `ustar` allows are skipped on export: the importer downloads those files instead.

> Only import files you trust, as with any WXR: a bundle can't run code, but its content and settings become your site's.
