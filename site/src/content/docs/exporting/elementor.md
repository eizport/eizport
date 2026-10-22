---
title: "Elementor"
description: "Elementor with its own exporter and importer: the site settings (the Kit) and templates on a row of their own, each page's design as a .json file, and on import every image, template and link pointing to the items on the other site."
---

With **Elementor** active, Eizport carries what Elementor's own *Import / Export Kit* does — in Elementor's formats, handed to Elementor's own importer on the other site.

## The Elementor row

The **Elementor** row's **Choose items** panel lists:

| Item | What it is | Preview |
| --- | --- | --- |
| **Site settings** | The active Kit: global colors and fonts, layout, theme style (*Elementor → Site Settings*), without the site's name, tagline, logo and icon — as Elementor's Kit export leaves them out | The global colors |
| **Each template** (*Templates → Saved Templates*) | A section, a page, a container… in Elementor's template file format, the one *Export template* writes | Its type |

They travel in the bundle's `options.json`, so they need a bundle (`.wxrz`, `.zip`, as plugin or a Playground `.zip`). Elementor's library (`elementor_library`) isn't offered as a content row: this row carries it.

## Pages built with Elementor

Check pages, posts — any type — built with Elementor in their own rows. With *Content as .html files* on ([Settings → Export](../../settings/export-settings/)), each one's design goes as **`post_content/<slug>.json`**, in Elementor's template file format, instead of its `_elementor_data` inside the WXR; the `.html` files stay for content in blocks. With it off, the design stays in the WXR, and is handled the same way on import.

The rows suggest the rest: **Elementor** shows the site settings and the templates the pages' template widgets show as *suggested*, and the images the designs use — image and gallery widgets, backgrounds — come along as media used.

## On the other site

When the import ends, the design goes through **Elementor's own import**:

1. **Templates** are created as Elementor's Kit import creates them; a template already imported from the same site is updated instead, so a second delivery doesn't add copies.
2. **Site settings** go through Elementor's own *site settings* import: a new active Kit with the global colors and fonts added to the site's. A later delivery from the same site updates that Kit, its colors and fonts replaced by their ID instead of added again.
3. **Each page's design** goes through its document's import. Images come from the attachments the bundle brought — Elementor finds them by their source URL instead of downloading them again —, template widgets point to the imported templates, and links to the source site to this one.
4. Elementor's **generated CSS** is cleared, to be built again.

Without Elementor active on the other site, the designs are written to the pages' meta, with their images and templates mapped, ready for when Elementor is activated.

## What it doesn't

- **Elementor Pro** (Theme Builder templates and conditions, popups, forms) isn't covered yet.
- **Dynamic tags** that point to a post or a term by ID keep that ID.
- A link to a page that isn't in the file points to the same address on this site.
