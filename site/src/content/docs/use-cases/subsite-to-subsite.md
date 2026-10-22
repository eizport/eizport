---
title: "Subsite to subsite"
description: "On a network, a site built in one subsite moved to another — the live one, or a fresh copy — with its media, links and settings pointing to the right site."
---

## The scenario

A network has a subsite for building (`example.com/build`) and the live one (`example.com/shop`). The new pages, products and menus are ready in *build*; *shop* needs them. Copying the database tables between subsites is risky (IDs, prefixes, users shared by the network), and WordPress's own export leaves links and images pointing to *build*.

## The steps

1. In *build*, **Tools → Export**: check what goes (pages, products, menus, the look…).
2. **Export**, and in *shop* **Tools → Import → WordPress** with the file. Or from WP-CLI:

   ```bash
   wp --url=https://example.com/build eizport export --types=page,product --menus=all --filename=release
   wp --url=https://example.com/shop eizport import release.wxrz --update-existing
   ```

## What the import does

- The media go to *shop*'s own folder (`uploads/sites/<id>/`).
- Links to `example.com/build/…` point to `example.com/shop/…`; the network's address and *shop*'s own uploads are never touched.
- The IDs in blocks, menus and settings point to the items imported into *shop*.
- *build*'s saved defaults and settings stay in *build*.

## What it doesn't

- Users: they belong to the network; the importer assigns the posts to an existing user (or creates one) as WordPress does.
- Network settings (*Network Admin → Settings*) aren't exported: they belong to the whole network.
