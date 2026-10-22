---
title: "Galleries to staging"
description: "Pages built with Image, Gallery and Cover blocks, moved to a site where the media get other IDs — and still editable as they were."
---

## The scenario

The local site's pages use Image, Gallery, Cover and Media & Text blocks. Staging already has content, so the imported images get other IDs there. With the WordPress Importer alone, the images still show (their addresses are updated), but the blocks keep the old IDs: the editor shows *This image has an empty or invalid source*, *Replace* and the image settings act on the wrong item, and a gallery can't find its images.

## The steps

1. On the local site, **Tools → Export**: check the pages. Their images come along (*media used in content*).
2. **Export**, and on staging **Tools → Import → WordPress** with the file.

## What the import does

When the import ends, every imported page — new or updated — has its blocks pointed to the imported media: the Image and Cover blocks' `id` and `wp-image-N` class, the Gallery's `ids` and its images' `data-id`, Media & Text's `mediaId`, File, Video and Audio, `[gallery ids="…"]`, and the navigation links to pages and posts. The same goes for block widgets.

Only IDs that came in the file and changed are touched: a block pointing to something staging already had, and that wasn't in the file, stays as it is.

## What it doesn't

- A plugin's own blocks, until it lists them (the [`eizport_block_id_attributes`](../../developers/hooks/#eizport_block_id_attributes) filter).
