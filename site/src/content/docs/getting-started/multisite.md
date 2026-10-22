---
title: "Multisite"
description: "Eizport on a WordPress network: network or per-site activation, who can do what, where each site's files go, WP-CLI with --url, and uninstalling."
---

Eizport works on each site of a network as on a single site: a site exports its own content and imports into itself, whatever the other sites hold.

## Activating

**Network Activate** it in *Network Admin → Plugins* to have it on every site, or activate it on the sites that need it. Nothing is shared between sites: each keeps its own settings, copies and saved defaults.

## Who can do what

| Who | Can |
| --- | --- |
| Site administrators | Export and import on their site, and change its Eizport settings |
| Super admins | All of that on every site, and install plugins — an [as plugin](../../exporting/as-plugin/) `.zip` needs one to install it; site administrators import the bundle inside it on Tools → Import instead |
| Users who can't post unfiltered HTML | Import as WordPress lets them: on a network only super admins can, so scripts and some embeds in imported content are filtered for the others, as they would be when typed in the editor |

## Each site's files

A site's media, copies (`uploads/eizport/`) and imported files live in its own uploads folder (`wp-content/uploads/sites/<id>/`). An export from one site and an import into another — subsite to subsite, a subsite to a single site, or the reverse — work as between two separate sites:

- the media come from the bundle into the importing site's folder;
- links to the exporting site (its own address, like `https://example.com/shop`) point to the importing one;
- the IDs in blocks, menus and settings point to the imported items.

## Saved defaults

*Save as default* keeps each user's choices **per site**: what someone saves on one site doesn't show on another. With *Defaults are saved for: The whole site* in the [general settings](../../settings/general/), a site has its own defaults for everyone on it.

## WP-CLI

Pick the site with `--url`:

```bash
wp --url=https://example.com/shop eizport export --types=product --filename=shop
wp --url=https://example.com/staging eizport import shop.wxrz
```

## Uninstalling

Deleting the plugin from *Network Admin → Plugins* removes its data from every site of the network: each site's `uploads/eizport/` folder, settings, saved defaults and scheduled cleanup.
