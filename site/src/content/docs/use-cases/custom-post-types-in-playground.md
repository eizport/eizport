---
title: "Custom post types in Playground"
description: "A site whose post types live in a must-use plugin, shown in WordPress Playground: the plugin goes in the .zip and runs before the import."
---

## The scenario

The site keeps its **Recipes** post type in a must-use plugin — `wp-content/mu-plugins/recipes.php`, which loads `recipes/register.php` — so no one can switch it off. A Playground demo with the recipes needs that plugin: without it, there's no Recipes post type to import into.

## The steps

1. **Tools → Export**: check **Recipes** (and what the recipes need).
2. **Details → Format: Playground**, then **Playground options → Themes and plugins**:
   - **Must-use plugins to install**: the recipes plugin;
   - the site's theme, and the plugins the recipes use.
3. **Export**, then **Open in Playground** on the copy in [Copies](../../settings/copies/).

## What happens

The `.zip` holds `mu-plugins/recipes.php` and `mu-plugins/recipes.zip` (the folder it loads). The blueprint writes them into Playground's `wp-content/mu-plugins/` **before the import**, so the Recipes post type exists when the recipes come in, and the menu shows **Recipes** with them.

## What it doesn't

- A host's own must-use plugins (and WordPress Studio's SQLite loader) aren't offered: Playground has its own.
- A must-use plugin that loads files from elsewhere (a path outside `mu-plugins/`) takes only what's in `mu-plugins/`.
- Custom code goes inside the file: share it only where its license allows.
