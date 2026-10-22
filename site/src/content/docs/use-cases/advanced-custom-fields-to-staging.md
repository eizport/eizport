---
title: "Advanced Custom Fields to staging"
description: "Recipes built with Advanced Custom Fields (ACF) — their post type, taxonomy, field group and values — taken to staging with every photo, page and term they point to pointing right."
---

## The scenario

A food site keeps its **Recipes** with Advanced Custom Fields (ACF): a *Recipes* post type and a *Cuisines* taxonomy made in *ACF → Post Types* and *Taxonomies*, and a *Recipe details* field group with a photo, a printable card, the chef's page, related pages, a cuisine and the cook. A new recipe is ready locally; staging has its own content, so every ID there is different.

## The steps

1. On the local site, **Tools → Export**: check **Recipes** (or open **Choose items** for the new one).
2. The rows suggest the rest: **Pages** shows *2 suggested* (the chef's page and the related one) and **Advanced Custom Fields (ACF)** shows *3 suggested* (the post type, the taxonomy and the field group). Check both rows.
3. **Export** (a `.wxrz` or `.zip` bundle), and import it on staging in **Tools → Import → WordPress**.

## What staging gets

- ACF imports the *Recipes* post type, the *Cuisines* taxonomy and the *Recipe details* group **before** the content: the recipe comes in as a recipe, with its cuisine.
- The photo and the card come with the bundle, even unattached; on staging they keep their place — or point to the same files staging already had.
- The chef's page, the related pages, the cuisine and the cook point to the items **there**, with staging's IDs; the group's location rule on a page too.
- A second delivery updates the group, the post type and the taxonomy (same keys) instead of adding copies.

## What it doesn't

- ACF PRO fields (repeater, flexible content, gallery) aren't covered yet.
- A cook who isn't an author in the file keeps the old user ID; authors are mapped as the importer maps them.
