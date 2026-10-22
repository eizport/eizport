---
title: "Internal links to staging"
description: "Pages that link to each other, to products and to categories, built locally and moved to staging — with every link pointing to staging, not back to the local site."
---

## The scenario

The local site's home links to the shop, to three product categories, to the *About* page and to a few products — in buttons, in navigation links, in the text. With the WordPress Importer alone, staging gets those links as they were: `http://localhost:8909/product/…`, so every click on staging leads back to a site only one computer can open. The fix used to be a `search-replace` after each import.

## The steps

1. On the local site, **Tools → Export**: check the pages and products (and the menus, the widgets…).
2. **Export**, and on staging **Tools → Import → WordPress** with the file.

## What the import does

When the import ends, the local site's address becomes staging's in what was imported:

- the content and excerpt of every imported item, new or updated — links in the text, buttons, navigation links (also inside block attributes, where the address is escaped as `http:\/\/…`), with http or https;
- the custom links of the imported classic menus;
- the widgets and theme mods that came in the file.

The address is replaced only where it ends: `old.example` never touches `old.example.org`.

## What it doesn't

- Content that wasn't in the file: staging's own pages keep their links.
- Templates and template parts aren't exported yet, so links in a block theme's header or footer stay as they were.
