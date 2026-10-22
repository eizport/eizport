---
title: "Options"
description: "Export site settings one by one, by group or all at once; linked settings; what is never exported; how IDs are mapped."
---

The **Options** row exports site settings in an `options.json` inside the bundle, set on the other site when the import ends.

![The Options panel on Selected: the settings the homepage brings.](../../../assets/screenshots/options-panel.webp)

## Three ways to choose

- **One by one** — every option the site has (search, pages), each with its size and an *IDs mapped on import* badge when it holds a post ID.
- **By group** — filter the panel by a group and export it: **Basics** (suggested), Reading, General, Permalinks, Discussion, Media, Privacy, Site logo and site icon, WooCommerce pages. The theme's Customizer settings, custom CSS, the Site Editor's styles and the widgets have [rows of their own](../appearance/). A group is resolved when the file is made, so it follows the active theme.
- **All** — every option that may be exported.

On the new screen, checking an option in the panel adds it at once (the *Selection* filter shows the ones chosen); groups and *all* come from saved defaults or the classic screen. With nothing chosen yet, the panel opens on **Basics**: title, tagline, language, time zone, date formats, homepage and posts page, posts per page, permalinks, site logo and site icon.

## Linked settings

Some options point to a post: `page_on_front`, `page_for_posts`, `wp_page_for_privacy_policy`, `site_icon`, WooCommerce's page IDs. When the post is exported, those options can come along — with the ones they need, like `show_on_front`.

On the new screen they are **suggested**: choose the homepage and the unchecked Options row shows **2 suggested** (`page_on_front` and `show_on_front`, counted, not named). Check the row and they go with the export; the row still says **2 suggested** (plus groups or *N items* you chose). Uncheck one of them in its panel and the rest become plain items — **1 item**. Leave the row unchecked and they don't go.

The setting decides when they are suggested or added:

| Mode (Settings → Export) | When |
| --- | --- |
| **When items are checked, and on export** (default) | Suggested in the Options row when you check an item with a role; on the classic screen and in the ability, also added when the file is made — for items exported by filters or with all content |
| **When items are checked** | Suggested from the side panel's selection |
| **On export** | Only when the file is made (classic screen, ability) |
| **Never** | You choose every option yourself |

## Never exported

Site URLs (`siteurl`, `home`), the admin email, keys and salts, `active_plugins`, roles (`<prefix>user_roles`), who may register and as what (`users_can_register`, `default_role`), `cron`, `rewrite_rules`, database versions, mail server credentials, the plugin's own settings, and every transient. Plugins add theirs with [`eizport_denied_options`](../../developers/hooks/#eizport_denied_options); WordPress's own can't be removed.

**Secrets** aren't exported nor listed either: an option, a theme mod or a widget field whose name says it holds one — `secret`, `password`, `token`, `api_key`, `license`, `private`, `credential`… — and plugins' known stores of them (WP Mail SMTP, payment gateways' settings). A plugin marks its own, or lets one through, with [`eizport_is_secret`](../../developers/hooks/#eizport_is_secret).

An import never sets WordPress's own either, whatever the bundle holds: a bundle is a file anyone could have written.

## Options outside the groups, on import

An import sets the options of the groups (with the ones plugins add), the options pointing to a post, the theme mods and the widgets. Any other option the bundle holds — exported **by name** — waits for whoever imports: step 1 of the importer lists them under **Other options**, unchecked, and only the ones checked are set (on a [bundle plugin](../as-plugin/)'s screen too). With [WP-CLI](../../developers/wp-cli/) and the [`eizport/import`](../../developers/abilities/#eizportimport) ability, only the ones named in `--options` / `options`. On a network, only for who may manage the network's options.

## IDs on import

`options.json` lists, under `post_ids`, the options that hold a post ID. On import each one gets the ID the importer gave that post; if the post wasn't imported, the option is left out rather than pointing to the wrong thing. `theme_mods_<theme>` values are mapped too (logo, menus).
