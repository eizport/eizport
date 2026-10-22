---
title: "Advanced Custom Fields"
description: "Advanced Custom Fields (ACF) on a row of its own: its post types, taxonomies and field groups in ACF's own export format, imported before the content, and the fields that store IDs pointing to the items on the other site."
---

With **Advanced Custom Fields (ACF)** active (6.1 or later, the free plugin), the Export screen has an **Advanced Custom Fields (ACF)** row. Its **Choose items** panel lists what ACF registers, each with a preview:

| Item | Preview |
| --- | --- |
| **Post type** (*ACF → Post Types*) | Its name (`recipe`) |
| **Taxonomy** (*ACF → Taxonomies*) | Its name (`cuisine`) |
| **Field group** (*ACF → Field Groups*) | Its fields and their types: *Photo (image), Related (relationship)…* |

They travel in **ACF's own export format** — the JSON of *ACF → Tools → Export* — inside the bundle's `options.json`, so they need a bundle (`.wxrz`, `.zip`, as plugin or a Playground `.zip`). ACF's own posts (`acf-field-group`, `acf-post-type`…) aren't offered as content rows: this row carries them.

## Suggested

Check the posts (pages, products, an ACF post type…) and the row suggests what they use: the field groups of their values, the ACF post type of their type and the ACF taxonomies of their terms. The posts that their **post object**, **relationship** and **page link** fields point to are suggested in their own rows, and the images and files of **image** and **file** fields come along as media used.

## On the other site

1. **Before the content**, ACF imports the post types, taxonomies and field groups, as *ACF → Tools → Import* does: one with the same key there is **updated**, not duplicated, so a second delivery updates the first. The post types and taxonomies are registered at once, so their posts and terms come in.
2. The content comes in, with the values in its custom fields.
3. **When the import ends**, the fields that store IDs get the IDs the items have there:

| Field type | Points to | Mapped by |
| --- | --- | --- |
| Image, File | Attachments | The imported attachments (or the ones the site already had) |
| Post Object, Relationship, Page Link | Posts, pages, any type | The imported items |
| Taxonomy | Terms | The imported terms |
| User | Users | The authors, as the importer maps them |

Only IDs that came in the file and changed are touched; a page link to a URL stays as it is. Field groups' location rules on a page or a post get its new ID too. Term values (fields shown on a taxonomy) are mapped as post values.

## What it doesn't

- **ACF PRO** (repeater, flexible content, clone, gallery, options pages, blocks) isn't covered yet.
- **Values on users** don't travel: a WXR has no users, only the authors of its posts. A user field points to a user there only when that user is an author in the file.
- **Without ACF active** on the other site, its items aren't imported (the values still come, in the custom fields).
- Field groups in the theme's **local JSON** (`acf-json/`) travel with the theme.
