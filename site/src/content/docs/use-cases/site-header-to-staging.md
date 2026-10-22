---
title: "Site header to staging"
description: "A block theme's header redone in the Site Editor locally — a new layout, its navigation, an image — and a page template, taken to staging in place of the ones staging has, without a Header 2."
---

## The scenario

The site uses a block theme. Locally, the header was redone in **Appearance → Editor**: the logo on the left, a new navigation, a banner image above it; and the **Pages** template got a line of its own above the content. Staging has its own header, edited there months ago.

Copying the block markup by hand loses the navigation (its `ref` is an ID of the local site) and the image (another ID), and the WordPress Importer alone adds the header again as *Header 2* next to staging's own, which keeps showing.

## The steps

1. On the local site, **Tools → Export**. Check **Editor template parts** — or open **Choose items** and check only **Header**, each part with its block markup as preview — and **Editor templates** (**Pages**).
2. Check **Menus** too, with the header's navigation: it's suggested, as the templates point to it.
3. **Export**, and on staging **Tools → Import → WordPress** with the file (with Eizport active there).

## What staging gets

- Its **Header** replaced by the local one, under the same slug — no *Header 2* — and the **Pages** template replaced (or added, when staging hadn't edited it).
- The header's navigation block pointing to the imported navigation, its image block to the imported image, and its links to the local site pointing to staging.
- Everything else of staging's look as it was: its other templates and parts, its styles, its CSS.

## What it doesn't

- Templates the theme's files hold and nobody edited: staging's theme has them already.
- Another theme's templates: only the active theme's are listed, and they go to the same theme on staging. With another theme active there, they wait until that theme is.
