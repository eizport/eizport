---
title: "Elementor to staging"
description: "A landing page built with Elementor — its images, a saved section template and the site's global colors and fonts — taken to staging with Elementor's own importer, everything pointing to staging's items."
---

## The scenario

A landing page is built with Elementor locally: a background image, a gallery, a **promo section saved as a template** and shown by a template widget, a button to the chef's page, and the brand color and font from *Elementor → Site Settings*. Staging already has its own content, so every ID there is different, and its own Kit.

## The steps

1. On the local site, **Tools → Export**: in **Pages**, **Choose items** → the landing page.
2. The **Elementor** row shows *2 suggested*: the site settings and the promo section. Check it.
3. **Export**: the page's design goes as `post_content/<slug>.json`, the template and the site settings in the bundle, with the images.
4. On staging, **Tools → Import → WordPress**: upload the bundle.

## What staging gets

- The page, with its design brought in by **Elementor's own import**: the background and the gallery show the images the bundle brought (no copies of them), the template widget shows the imported promo section, and the button goes to staging's chef page.
- The **promo section** in *Templates → Saved Templates*.
- A **Kit** with the brand color and font added to staging's, active — the heading shows the brand color.
- A second delivery updates the template and that Kit instead of adding copies.

## What it doesn't

- Elementor Pro's Theme Builder (headers, footers and their conditions) and popups aren't covered yet.
