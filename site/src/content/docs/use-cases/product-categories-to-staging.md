---
title: "Product categories to staging"
description: "A store's products moved with their categories as they are — descriptions, parent categories and thumbnails — not just their names."
---

## The scenario

The local store has its products in categories with descriptions (shown on the category pages), some under a parent (*Coffee → Single origin*), and with thumbnails. Only the products are exported, not *All content*. With WordPress's own export, each product names its categories and nothing else: staging gets categories with the right names and no description, no parent, no thumbnail.

## The steps

1. On the local site, **Tools → Export**: check **Products** (all of them, or some).
2. **Export**, and on staging **Tools → Import → WordPress** with the file.

## What the file carries

Every category, tag and other term the exported items use, in full, with its parent categories even when no product is in them:

- the name, the slug and the **description**;
- the **parent**, so the hierarchy is rebuilt (parents are written first);
- the **term meta**: WooCommerce's thumbnail and display type, or a plugin's own fields.

On staging, the categories come as they were, and the thumbnails point to the imported images.

## What it doesn't

- A category staging already has (same slug) is used as it is: its description isn't overwritten.
- Terms no exported item uses stay out — export *All content* to bring every term.
