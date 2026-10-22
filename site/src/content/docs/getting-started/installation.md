---
title: "Installation"
description: "Install the plugin from the WordPress.org plugin directory, check the requirements, and add the WordPress Importer for imports."
---

## From the plugin directory

1. In WordPress, go to **Plugins → Add Plugin** and search for **Eizport** — or open its page, [wordpress.org/plugins/eizport](https://wordpress.org/plugins/eizport/).
2. Click **Install Now**, then **Activate**. Tools → Export now opens the new Export screen.

Updates come the same way, from **Dashboard → Updates**.

## With WP-CLI

```bash
wp plugin install eizport --activate
```

## Development versions and older releases

Every release is also on [GitHub](https://github.com/eizport/eizport/releases), as `eizport.zip`: add it in **Plugins → Add Plugin → Upload Plugin**.

## Try it first

Open the [live demo in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/eizport/eizport/trunk/playground/blueprint.json): a throwaway site in your browser with the plugin and sample pages.

## To import

Importing needs the **WordPress Importer**. On **Tools → Import**, click **Install Now** under *WordPress*, then **Run Importer**. Exporting works without it.

## Requirements in detail

| Requirement | Why |
| --- | --- |
| WordPress 7.0+ | The Export and Settings screens are built with `@wordpress/build` and booted by core's `@wordpress/boot`. |
| PHP 7.4+ | The plugin's minimum. |
| `zlib` | `.wxrz` bundles are gzipped tar archives. |
| `ZipArchive` (or PclZip) | `.zip` bundles and Playground `.zip` files. Core's PclZip is the fallback. |
| Abilities API | Part of WordPress 6.9+; the screens run the plugin's abilities. |

## Uninstalling

Deleting the plugin removes `wp-content/uploads/eizport/` (temporary copies and unfinished imports), its settings and the saved export defaults (the site's and every user's).
