---
title: "Menus"
description: "Classic menus with their items and theme locations, and block navigations, on their own row; what's suggested, what the import does with menus the other site already has, and the limits."
---

Navigation menus are exported on their own row, **Menus**, next to the content types. Its side panel lists both kinds:

| Kind | What it is | Goes with it |
| --- | --- | --- |
| **Classic menu** | *Appearance → Menus*, in classic themes | Its items (with their order and submenus) and the theme locations it sits in |
| **Navigation** | The menu of a *Navigation* block, in block themes (*Appearance → Editor → Navigation*) | Its links |

## Suggested

Without choosing anything, the row suggests:

- the classic menus that sit in a **theme location** (*Primary*, *Footer*…);
- the **navigations** the items chosen use in their content, and the ones the active theme's templates and template parts use (a header's *Navigation* block). A *Navigation* block that points to no menu shows the most recently published one: that one is suggested.

Choosing a classic menu suggests what its items link to — pages, posts, products — in their rows. Export them too: the importer leaves out a menu item whose page isn't on the other site. Custom links always go.

## On the other site

- **Classic menus** are created with their items, which point to the imported pages, posts and terms; submenus keep their parents. Each menu goes back to its **theme locations**, when the other site's active theme has them (a block theme has none). The locations need Eizport on the other site: with the WordPress Importer alone, the menus come without them.
- **Navigations** are created, and the *Navigation* blocks that pointed to them — in the imported pages and in the other site's templates — point to the new ones.
- **A menu the other site already has** (same slug) keeps its items, unless the import [updates the items that already exist](../../getting-started/importing/#updating-items-that-already-exist): then its items are replaced by the file's, and it ends up as on this site. A navigation with the same slug is updated the same way.

## From WP-CLI and the ability

```bash
wp eizport export --types=page --menus=classic:18,navigation:4
wp eizport export --menus=all
```

The [`eizport/export`](../../developers/abilities/#eizportexport) ability takes `menus` (`classic`, `navigation` or `all`); [`eizport/get-export-options`](../../developers/abilities/#eizportget-export-options) lists them.

## Limits

- **All content** (no type chosen) already carries every menu, as WordPress's own export does; choosing menus there adds their theme locations.
- With no type checked and menus chosen, only the menus are exported.
- Inside a navigation, each link keeps the page ID and address it had on this site; when the pages get other IDs on the other site, the links still lead to the right address only if it's the same (see the [issues](https://github.com/eizport/eizport/issues) about block IDs and links to the source site). Classic menu items don't have this problem: the importer points them to the imported pages.
- The classic screen (*Tools → Export → Classic*) has no Menus row.
