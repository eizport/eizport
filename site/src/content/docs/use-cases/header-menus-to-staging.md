---
title: "Header menus to staging"
description: "A site built locally — pages, a header menu with submenus and its theme location — repeated on staging without rebuilding the menu by hand."
---

## The scenario

A site is built locally from a design: the pages, then the header menu — *Home*, *About*, *Services* with three pages under it, *Contact* and a link to the shop — set in the theme's *Primary* location. Staging needs the same pages and the same menu, and nobody wants to rebuild the menu by hand, item by item, after every round of changes.

## The steps

1. On the local site, **Tools → Export**. Check **Pages** — or only the ones the menu links to.
2. The **Menus** row says *1 suggested*: the menu in the *Primary* location. Check it. Opening its panel shows it as a *Classic menu* in *Primary*.
3. If a page of the menu wasn't chosen, the **Pages** row suggests it: check the row to bring it.
4. **Export**, and on staging **Tools → Import → WordPress** with the file (Eizport active there too, for the menu's location).

The same works for a block theme's header: the **Menus** row suggests the *Navigation* the header uses.

## What the file carries

- The pages, with their media.
- The menu, with its items in order, the submenu under *Services*, the custom link to the shop, and its place in *Primary*.

On staging, the menu items point to staging's pages (their new IDs) and the menu is in *Primary*.

## What it doesn't

- A page the menu links to that wasn't exported: its item is left out.
- The location, when staging's theme doesn't have *Primary* (another theme, or a block theme).

## Next time

After more changes, export again and import with *Update pages, posts and products that already exist (same slug)* checked (see [updating items that already exist](../../getting-started/importing/#updating-items-that-already-exist)): the pages are updated, and the menu takes the file's items instead of getting a second copy of them.
